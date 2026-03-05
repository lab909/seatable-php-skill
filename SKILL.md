---
name: seatable-php
description: >
    Use this skill whenever you are developing a PHP backend application that needs to use SeaTable as a database or data store. Triggers include any mention of SeaTable, requests to build a PHP API or backend that reads/writes structured data to SeaTable, setting up the SeaTable PHP SDK, performing CRUD operations on SeaTable bases, or integrating SeaTable into a Laravel/Slim/vanilla PHP project. If the user says "use SeaTable", "connect to SeaTable", "store data in SeaTable", or "query my SeaTable base", always use this skill immediately.
---

# SeaTable PHP SDK Skill

SeaTable is an Airtable-like cloud spreadsheet/database. This skill covers everything needed
to build PHP backends that use SeaTable as a data store via the official `seatable/seatable-api-php`
SDK (v4+, auto-generated from OpenAPI).

> ⚠️ The SDK v4+ is **not compatible** with v0.2 or earlier. Always use the patterns documented here.
> This document was validated against **SDK v6.0.10** on a self-hosted SeaTable instance.

---

## Table of Contents

1. [Installation](#1-installation)
2. [Authentication](#2-authentication)
3. [Security & Architecture Guardrails (CRITICAL)](#3-security--architecture-guardrails-critical)
4. [Bootstrap Pattern](#4-bootstrap-pattern)
5. [Return Type Reference — CRITICAL](#5-return-type-reference--critical)
6. [Core CRUD Operations](#6-core-crud-operations)
7. [Column Management](#7-column-management)
8. [Reading Data — SQL vs listRows](#8-reading-data--sql-vs-listrows)
9. [API Namespace Map](#9-api-namespace-map)
10. [Self-Hosted Configuration](#10-self-hosted-configuration)
11. [Error Handling & Retry Strategy](#11-error-handling--retry-strategy)
12. [Project Structure](#12-project-structure)
13. [Key Gotchas & Discoveries](#13-key-gotchas--discoveries)
14. [SQL Reference](#14-sql-reference)

---

## 1. Installation

```bash
composer require seatable/seatable-api-php
# Optionally for .env support:
composer require vlucas/phpdotenv
```

Requires PHP 7.4+. The SDK depends on `guzzlehttp/guzzle` (auto-installed).

---

## 2. Authentication

SeaTable uses a three-token hierarchy:

| Token | Length | Expires | Used for |
| --- | --- | --- | --- |
| **Account-Token** | 40 chars | Never | Account-level ops (list bases, manage users) |
| **API-Token** | 40 chars | Never | Per-base password; exchanges for a Base-Token |
| **Base-Token** | 400+ chars (JWT) | **3 days** | All base CRUD operations |

**For most apps, you only need an API-Token** (created in the SeaTable UI per base).

```php
use SeaTable\Client\Auth\BaseTokenApi;
use SeaTable\Client\Configuration;
use GuzzleHttp\Client as HttpClient;

$config = Configuration::getDefaultConfiguration();
$config->setAccessToken($_ENV['SEATABLE_API_TOKEN']);
$config->setHost($_ENV['SEATABLE_SERVER_URL']); // required for self-hosted

$authApi = new BaseTokenApi(new HttpClient(), $config);
$result  = $authApi->getBaseTokenWithApiToken();

// $result is an array:
$baseUuid    = $result['dtable_uuid'];   // ← key is 'dtable_uuid', NOT 'base_uuid'
$accessToken = $result['access_token'];
```

> ⚠️ Base-Tokens expire after 3 days. For long-running processes, implement refresh logic.
> In request-scoped HTTP apps, fetching a fresh token per request is acceptable but adds
> latency — consider short-lived caching (see [Section 4 — Token Caching](#token-caching)).

---

## 3. Security & Architecture Guardrails (CRITICAL)

When scaffolding or modifying code, you MUST adhere to these strict security and architectural rules.

### A. SQL Injection Prevention

SeaTable allows SQL queries via its API, but it does NOT support parameterized SQL via the SDK's `querySQL` method.

* **Requirement:** You must **never** concatenate raw user input directly into a SQL query string.
* **Rule:** If you must construct SQL strings, rigorously sanitize, escape, and cast all inputs (e.g., `intval()` for integers, strict regex validation for strings with length limits) before appending them to the query.
* **Prohibited:** `"SELECT * FROM Tasks WHERE status = '{$_POST['status']}'"`
* **Safe pattern:**
```php
// Validate BEFORE interpolation — strict type + length constraints
$status = $_POST['status'] ?? '';
if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
    throw new \InvalidArgumentException("Invalid status value.");
}
$sql = "SELECT * FROM Tasks WHERE status = '{$status}' LIMIT 100";
```

### B. Input Validation & Mass Assignment Prevention

* **Requirement:** Never take raw request payloads (`$_POST`, `$_GET`, or raw JSON input) and pass them directly to the SeaTable SDK's `appendRows` or `updateRow` methods.
* **Rule:** Implement a strict allow-list of permitted columns. Validate all incoming data types, lengths, and formats before sending them to SeaTable.

### C. Error Handling & Information Disclosure

* **Requirement:** SeaTable API errors can leak sensitive Base UUIDs or table structures.
* **Rule:** Catch `\SeaTable\Client\ApiException`. Log the detailed exception message and body internally, but **only return generic HTTP error responses** (e.g., `400 Bad Request` or `500 Internal Server Error`) to the API consumer.
* **Rule:** Never log raw tokens. If logging the response body, be aware it may contain sensitive identifiers — sanitize before writing to shared log systems.

### D. Environment Variables & Secrets

* **Requirement:** Store all credentials in `.env` files using `vlucas/phpdotenv`.
* **Rule:** Always add `.env` to `.gitignore` to prevent committing secrets to version control.
* **Rule:** Provide a `.env.example` with placeholder values (committed to the repo) so developers know which variables are required.

### E. The Repository Pattern & DTOs

* **Requirement:** Do not scatter SeaTable API calls throughout controllers or routing files. Encapsulate all database interactions within Repository classes (e.g., `TaskRepository`).
* **Requirement:** Map SeaTable's associative array responses into strongly typed PHP classes (Data Transfer Objects / DTOs) immediately upon retrieval. Repositories must return DTOs, not raw arrays or `stdClass` objects.
* **Requirement:** Inject the SeaTable SDK classes (`RowsApi`, `ColumnsApi`) into Repositories via dependency injection for testability.

### F. File Upload Security

* **Requirement:** Before uploading files, always validate that the source file exists (`file_exists()`), sanitize the filename with `basename()`, and validate the file type/extension against an allow-list.
* **Prohibited:** Passing user-supplied paths directly to `fopen()` without validation.

---

## 4. Bootstrap Pattern

Recommended shared bootstrap reused by all scripts and API endpoints:

```php
<?php
// src/SeaTableClient.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use SeaTable\Client\Auth\BaseTokenApi;
use SeaTable\Client\Base\BaseInfoApi;
use SeaTable\Client\Base\ColumnsApi;
use SeaTable\Client\Base\RowsApi;
use SeaTable\Client\Configuration;
use GuzzleHttp\Client as HttpClient;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function getAuth(): array
{
    $config = Configuration::getDefaultConfiguration();
    $config->setAccessToken($_ENV['SEATABLE_API_TOKEN']);
    $config->setHost($_ENV['SEATABLE_SERVER_URL']);

    $authApi = new BaseTokenApi(new HttpClient(), $config);
    $result  = $authApi->getBaseTokenWithApiToken();

    return [
        'base_uuid'    => $result['dtable_uuid'],  // ← SDK returns 'dtable_uuid'
        'access_token' => $result['access_token'],
    ];
}

function makeConfig(array $auth): Configuration
{
    $config = Configuration::getDefaultConfiguration();
    $config->setAccessToken($auth['access_token']);
    $config->setHost($_ENV['SEATABLE_SERVER_URL']);
    return $config;
}

function getRowsApi(array $auth): RowsApi
{
    return new RowsApi(new HttpClient(), makeConfig($auth));
}

function getColumnsApi(array $auth): ColumnsApi
{
    return new ColumnsApi(new HttpClient(), makeConfig($auth));
}

function getBaseInfoApi(array $auth): BaseInfoApi
{
    return new BaseInfoApi(new HttpClient(), makeConfig($auth));
}
```

### Token Caching

For production apps that make many requests, avoid exchanging the API-Token on every call.
Cache the Base-Token with a TTL shorter than its 3-day expiry (e.g., 2 days):

```php
/**
 * Get a cached Base-Token, refreshing only when expired.
 * Uses APCu for single-server setups. Replace with Redis/Memcached for multi-server.
 */
function getAuthCached(): array
{
    $cacheKey = 'seatable_base_token';
    $cached   = apcu_fetch($cacheKey);

    if ($cached !== false) {
        return $cached;
    }

    $auth = getAuth(); // fresh token exchange
    apcu_store($cacheKey, $auth, 172800); // cache for 2 days (172800 seconds)

    return $auth;
}
```

---

## 5. Return Type Reference — CRITICAL

> ⚠️ **This is the most important section.** The SDK returns inconsistent types across methods.
> Getting this wrong causes silent failures (null instead of data) or fatal errors.

### `BaseTokenApi::getBaseTokenWithApiToken()`

* Returns: **plain PHP `array`**
* Keys: `dtable_uuid`, `access_token`, `dtable_server`, `dtable_socket`, `dtable_db_server`, `workspace_id`, `dtable_name`
* Access: `$result['dtable_uuid']`, `$result['access_token']`

### `BaseInfoApi::getMetadata($uuid)`

* Returns: **`array`** at the top level
* But `$result['metadata']` is a **`stdClass` object** — NOT an array
* Access pattern:
```php
$meta      = $baseInfoApi->getMetadata($uuid);
// ✓ correct:
$tables    = $meta['metadata']->tables;        // array of stdClass
$tableName = $meta['metadata']->tables[0]->name; // string
// ✗ wrong — throws "Cannot use object of type stdClass as array":
$tables    = $meta['metadata']['tables'];
```

### `RowsApi::querySQL($uuid, SqlQuery $query)`

* Returns: **`SqlQueryResponse` object** (typed PHP class)
* Must use **getter methods** — direct property access and array access both return null:
```php
$response = $rowsApi->querySQL($uuid, $query);
// ✓ correct:
$rows = $response->getResults();   // array of associative arrays
$meta = $response->getMetadata();  // array of column metadata objects
$ok   = $response->getSuccess();   // bool
// ✗ wrong — returns null (no exception in PHP 8, silent failure):
$rows = $response->results;
$rows = $response['results'];
```

* Each row in `getResults()` is an **associative array** (when `convert_keys: true`):
```php
$rows[0]['name']         // string
$rows[0]['phone number'] // string (column names with spaces work fine)
$rows[0]['_id']          // string — the row ID
```

### `RowsApi::listRows($uuid, $tableName, ...)`

* Returns: **`array`** at the top level
* `$result['rows']` is an **`array`** of **`stdClass` objects**
* Access pattern:
```php
$result = $rowsApi->listRows($uuid, 'TableName', null, 0, 100);
$rows   = $result['rows'];     // array of stdClass
$id     = $rows[0]->_id;      // ✓ object property access
$id     = $rows[0]['_id'];    // ✗ wrong
```

* ⚠️ **`convert_keys` has NO effect** in `listRows` — rows always use internal column keys
  (e.g. `"pDkj"` for a column named `"name"`). See [Section 8](#8-reading-data--sql-vs-listrows).

### `ColumnsApi::listColumns($tableName, $uuid)`

* Returns: **`array`** at top level
* `$result['columns']` is an **`array`** of **`stdClass` objects**
* Each column has properties: `->key`, `->name`, `->type`, `->width`, `->editable`, `->resizable`
```php
$result  = $columnsApi->listColumns($tableName, $uuid);
$columns = $result['columns'];
$name    = $columns[0]->name;   // ✓ stdClass property
$name    = $columns[0]['name']; // ✗ wrong
```

* ⚠️ Note the **reversed parameter order**: `listColumns($table_name, $base_uuid)` — table first, UUID second.
  Most other API methods have `($uuid, ...)` as the first parameter.

---

## 6. Core CRUD Operations

### Insert rows

```php
use SeaTable\Client\Base\AppendRows;

$request = new AppendRows([
    'table_name' => 'Contacts',
    'rows'       => [
        ['name' => 'Alice', 'surname' => 'Smith', 'phone number' => '+1 555 123 4567'],
        ['name' => 'Bob',   'surname' => 'Jones',  'phone number' => '+44 20 7946 0958'],
    ],
]);

$rowsApi->appendRows($uuid, $request);
```

Insert rows use **column names** (not internal keys) as array keys.

### Read rows via SQL (recommended)

```php
use SeaTable\Client\Base\SqlQuery;

$query = new SqlQuery([
    'sql'          => "SELECT name, surname, `phone number` FROM Contacts LIMIT 10 OFFSET 0",
    'convert_keys' => true,
]);

$response = $rowsApi->querySQL($uuid, $query);
$rows     = $response->getResults(); // array of assoc arrays with column names as keys
```

### Read rows via listRows (for fetching row IDs / full pagination)

```php
$start  = 0;
$limit  = 1000;
$rowIds = [];

do {
    $result = $rowsApi->listRows($uuid, 'Contacts', null, $start, $limit);
    $chunk  = $result['rows'] ?? [];
    foreach ($chunk as $row) {
        $rowIds[] = $row->_id;  // stdClass — use -> not []
    }
    $start += $limit;
} while (count($chunk) === $limit);
```

### Update rows

```php
use SeaTable\Client\Base\UpdateRows;

$request = new UpdateRows([
    'table_name' => 'Contacts',
    'updates'    => [
        [
            'row_id' => 'THE_ROW_ID',
            'row'    => ['name' => 'Alice Updated', 'surname' => 'Smith-Jones'],
        ],
    ],
]);

$rowsApi->updateRow($uuid, $request);
```

### Delete rows

```php
use SeaTable\Client\Base\DeleteRows;

$request = new DeleteRows([
    'table_name' => 'Contacts',
    'row_ids'    => ['ROW_ID_1', 'ROW_ID_2', 'ROW_ID_3'],
]);

$rowsApi->deleteRow($uuid, $request);
```

---

## 7. Column Management

### List columns

```php
// ⚠️ Parameter order: table_name FIRST, then base_uuid
$result  = $columnsApi->listColumns($tableName, $uuid);
$columns = $result['columns']; // array of stdClass objects
```

### Insert a single column

```php
use SeaTable\Client\Base\InsertColumnRequest;

$req = new InsertColumnRequest([
    'table_name'  => 'Contacts',
    'column_name' => 'phone number',
    'column_type' => 'text',
]);

$columnsApi->insertColumn($uuid, $req);
```

---

## 8. Reading Data — SQL vs listRows

### Use `querySQL` when you need:

* **Human-readable column names** in results
* Filtered results (`WHERE` clause)
* Sorted results (`ORDER BY`)
* Paginated reads for display (use `LIMIT N OFFSET M`)

**SQL default row limit**: SeaTable caps SQL results at **100 rows** by default. Always add explicit `LIMIT N`.

### Use `listRows` when you need:

* **All rows** without a row-count cap
* Only the row `_id` values (e.g. to batch-delete everything)

> ⚠️ **`convert_keys` does NOT work in `listRows`**. Rows always come back with internal keys like `"pDkj"`. **Only `querySQL` reliably returns human-readable column names.**

---

## 9. API Namespace Map

| Task | Class | Namespace |
| --- | --- | --- |
| Exchange API-Token → Base-Token | `BaseTokenApi` | `SeaTable\Client\Auth` |
| Base metadata (tables, columns) | `BaseInfoApi` | `SeaTable\Client\Base` |
| Row CRUD + SQL queries | `RowsApi` | `SeaTable\Client\Base` |
| Column management | `ColumnsApi` | `SeaTable\Client\Base` |
| Table management | `TablesApi` | `SeaTable\Client\Base` |
| File uploads/downloads | `FilesApi` | `SeaTable\Client\File` |
| Row-to-row links | `LinksApi` | `SeaTable\Client\Base` |
| Account info, list bases | `UserApi`, `BasesApi` | `SeaTable\Client\User` |

---

## 10. Self-Hosted Configuration

For self-hosted SeaTable instances, **always call `$config->setHost()`**.

```php
$config = Configuration::getDefaultConfiguration();
$config->setAccessToken($token);
$config->setHost('https://your-seatable-server.com'); // no trailing slash, no path suffix
```

The host should be the root URL only. Without `setHost()`, the SDK defaults to `https://cloud.seatable.io`.

> ⚠️ **TLS/SSL:** Never disable SSL verification in production (`['verify' => false]`). If your
> self-hosted instance uses a custom CA, configure Guzzle to trust it explicitly:
> ```php
> $httpClient = new HttpClient(['verify' => '/path/to/custom-ca-bundle.crt']);
> ```

---

## 11. Error Handling & Retry Strategy

### Basic error handling

```php
try {
    $result = $rowsApi->appendRows($uuid, $request);
} catch (\SeaTable\Client\ApiException $e) {
    $code = $e->getCode();
    $body = $e->getResponseBody();

    // CRITICAL: Log internally, do not leak $body to API consumers.
    // Be aware $body may contain Base UUIDs or table structure details.
    error_log("SeaTable API error [{$code}]: {$body}");

    // Return a generic error to the caller
    throw new \RuntimeException("Database operation failed.");
}
```

### Handling rate limits (HTTP 429) and token expiry (HTTP 401)

```php
function executeWithRetry(callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;

    while (true) {
        try {
            return $operation();
        } catch (\SeaTable\Client\ApiException $e) {
            $code = $e->getCode();
            $attempt++;

            // 401 = token expired — refresh and retry once
            if ($code === 401 && $attempt === 1) {
                error_log("SeaTable: Base-Token expired, refreshing...");
                // Caller should refresh auth and rebuild the API client
                throw new TokenExpiredException("Base-Token expired, refresh required.");
            }

            // 429 = rate limited — exponential backoff
            if ($code === 429 && $attempt <= $maxRetries) {
                $waitSeconds = min(pow(2, $attempt), 30); // 2s, 4s, 8s... max 30s
                error_log("SeaTable: Rate limited, waiting {$waitSeconds}s (attempt {$attempt}/{$maxRetries})");
                sleep($waitSeconds);
                continue;
            }

            // All other errors or max retries exceeded
            error_log("SeaTable API error [{$code}]: " . $e->getResponseBody());
            throw new \RuntimeException("Database operation failed.");
        }
    }
}
```

---

## 12. Project Structure

When scaffolding projects, enforce this directory structure to support DTOs and Repositories:

```
my-backend/
├── composer.json
├── .env                       # SEATABLE_SERVER_URL, SEATABLE_API_TOKEN (never committed)
├── .env.example               # Template with placeholder values (committed to repo)
├── .gitignore                 # Must include: .env, /vendor/
├── src/
│   ├── SeaTableClient.php     # Bootstrap API Client setup
│   ├── DTOs/
│   │   └── ContactDTO.php     # Strongly typed representation of a row
│   └── Repositories/
│       └── ContactRepository.php # Encapsulates all RowsApi calls, returns ContactDTOs
├── api/
│   └── contacts.php           # JSON endpoint (validates input, calls Repository)
└── index.php
```

> ⚠️ **Always create a `.gitignore`** that excludes `.env` and `/vendor/`. See `examples/.gitignore`
> for a ready-to-use template.

Always use the Repository pattern and DTOs. Refer to the files in `examples/ContactRepository.php` and `examples/ContactDTO.php` for the required implementation style, security checks, and error handling.

---

## 13. Key Gotchas & Discoveries

* **Return type inconsistency**: Some methods return arrays, some return objects, some return arrays of objects. Always check Section 5.
* **`listColumns` parameters**: `listColumns($tableName, $uuid)` — table name first, UUID second.
* **`DeleteColumn` key**: Uses `'column'`, not `'column_name'`.
* **SQL column quoting**: Use backticks around column names containing spaces: `` SELECT `phone number` FROM Contacts ``
* **`getBaseTokenWithApiToken` key**: The UUID is returned as `dtable_uuid`, not `base_uuid`.
* **Table names in SQL**: When building SQL, only use hardcoded or validated table names — never raw user input. Mark table name sources clearly with comments in code.
* **Self-hosted TLS**: Do not disable SSL verification. Use a custom CA bundle if needed.

---

## 14. SQL Reference

See `references/sql-limitations.md` for the full SQL dialect reference.
