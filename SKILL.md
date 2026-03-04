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
3. [Bootstrap Pattern](#3-bootstrap-pattern)
4. [Return Type Reference — CRITICAL](#4-return-type-reference--critical)
5. [Core CRUD Operations](#5-core-crud-operations)
6. [Column Management](#6-column-management)
7. [Reading Data — SQL vs listRows](#7-reading-data--sql-vs-listrows)
8. [API Namespace Map](#8-api-namespace-map)
9. [Self-Hosted Configuration](#9-self-hosted-configuration)
10. [Error Handling](#10-error-handling)
11. [Project Structure](#11-project-structure)
12. [Key Gotchas & Discoveries](#12-key-gotchas--discoveries)
13. [SQL Reference](#13-sql-reference)

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
|---|---|---|---|
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
> In request-scoped HTTP apps, fetching a fresh token per request is acceptable.

---

## 3. Bootstrap Pattern

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

---

## 4. Return Type Reference — CRITICAL

> ⚠️ **This is the most important section.** The SDK returns inconsistent types across methods.
> Getting this wrong causes silent failures (null instead of data) or fatal errors.

### `BaseTokenApi::getBaseTokenWithApiToken()`
- Returns: **plain PHP `array`**
- Keys: `dtable_uuid`, `access_token`, `dtable_server`, `dtable_socket`, `dtable_db_server`, `workspace_id`, `dtable_name`
- Access: `$result['dtable_uuid']`, `$result['access_token']`

### `BaseInfoApi::getMetadata($uuid)`
- Returns: **`array`** at the top level
- But `$result['metadata']` is a **`stdClass` object** — NOT an array
- Access pattern:
  ```php
  $meta      = $baseInfoApi->getMetadata($uuid);
  // ✓ correct:
  $tables    = $meta['metadata']->tables;        // array of stdClass
  $tableName = $meta['metadata']->tables[0]->name; // string
  // ✗ wrong — throws "Cannot use object of type stdClass as array":
  $tables    = $meta['metadata']['tables'];
  ```

### `RowsApi::querySQL($uuid, SqlQuery $query)`
- Returns: **`SqlQueryResponse` object** (typed PHP class)
- Must use **getter methods** — direct property access and array access both return null:
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
- Each row in `getResults()` is an **associative array** (when `convert_keys: true`):
  ```php
  $rows[0]['name']         // string
  $rows[0]['phone number'] // string (column names with spaces work fine)
  $rows[0]['_id']          // string — the row ID
  ```

### `RowsApi::listRows($uuid, $tableName, ...)`
- Returns: **`array`** at the top level
- `$result['rows']` is an **`array`** of **`stdClass` objects**
- Access pattern:
  ```php
  $result = $rowsApi->listRows($uuid, 'TableName', null, 0, 100);
  $rows   = $result['rows'];     // array of stdClass
  $id     = $rows[0]->_id;      // ✓ object property access
  $id     = $rows[0]['_id'];    // ✗ wrong
  ```
- ⚠️ **`convert_keys` has NO effect** in `listRows` — rows always use internal column keys
  (e.g. `"pDkj"` for a column named `"name"`). See [Section 7](#7-reading-data--sql-vs-listrows).

### `ColumnsApi::listColumns($tableName, $uuid)`
- Returns: **`array`** at top level
- `$result['columns']` is an **`array`** of **`stdClass` objects**
- Each column has properties: `->key`, `->name`, `->type`, `->width`, `->editable`, `->resizable`
  ```php
  $result  = $columnsApi->listColumns($tableName, $uuid);
  $columns = $result['columns'];
  $name    = $columns[0]->name;   // ✓ stdClass property
  $name    = $columns[0]['name']; // ✗ wrong
  ```
- ⚠️ Note the **reversed parameter order**: `listColumns($table_name, $base_uuid)` — table first, UUID second.
  Most other API methods have `($uuid, ...)` as the first parameter.

### Write operations (`appendRows`, `insertColumn`, `deleteRow`, `deleteColumn`)
- Return values vary but are generally not needed for simple CRUD
- Wrap in try/catch for error detection

---

## 5. Core CRUD Operations

### Insert rows

```php
use SeaTable\Client\Base\AppendRows;

$request = new AppendRows([
    'table_name' => 'Contacts',
    'rows'       => [
        ['name' => 'Alice', 'surname' => 'Smith', 'phone number' => '+1 555 123 4567'],
        ['name' => 'Bob',   'surname' => 'Jones',  'phone number' => '+44 20 7946 0958'],
    ],
    // 'apply_default' => true,  // apply column default values (optional)
]);

$rowsApi->appendRows($uuid, $request);
```

Insert rows use **column names** (not internal keys) as array keys — this works correctly.
For columns with spaces (e.g. `phone number`), use the exact column name as the key.

**Batch inserts**: SeaTable handles up to ~50-100 rows per `appendRows` call reliably.
For large datasets, batch in groups of 20–50.

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
// Use listRows when you need all rows (no SQL row limit applies)
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

// Need _id from a prior query
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

Batch delete in groups of 50 for reliability.

### Get single row

```php
$row = $rowsApi->getRow($uuid, 'Contacts', $rowId, true); // true = convert_keys
```

---

## 6. Column Management

### List columns

```php
// ⚠️ Parameter order: table_name FIRST, then base_uuid (opposite of most methods)
$result  = $columnsApi->listColumns($tableName, $uuid);
$columns = $result['columns']; // array of stdClass objects

// Get existing column names:
$existingNames = array_map(fn($c) => $c->name, $columns);
```

### Insert a single column

```php
use SeaTable\Client\Base\InsertColumnRequest;

$req = new InsertColumnRequest([
    'table_name'  => 'Contacts',
    'column_name' => 'phone number',   // spaces allowed
    'column_type' => 'text',           // see column types below
]);

$columnsApi->insertColumn($uuid, $req);
```

### Append multiple columns at once

```php
use SeaTable\Client\Base\AppendColumnsRequest;
use SeaTable\Client\Base\AppendColumnsRequestColumnsInner;

$req = new AppendColumnsRequest([
    'table_name' => 'Contacts',
    'columns'    => [
        new AppendColumnsRequestColumnsInner(['column_name' => 'name',         'column_type' => 'text']),
        new AppendColumnsRequestColumnsInner(['column_name' => 'surname',      'column_type' => 'text']),
        new AppendColumnsRequestColumnsInner(['column_name' => 'phone number', 'column_type' => 'text']),
    ],
]);

$columnsApi->appendColumns($uuid, $req);
```

### Delete a column

```php
use SeaTable\Client\Base\DeleteColumn;

// ⚠️ Field is 'column' (not 'column_name')
$req = new DeleteColumn([
    'table_name' => 'Contacts',
    'column'     => 'phone number',   // the column NAME, not its internal key
]);

$columnsApi->deleteColumn($uuid, $req);
```

### Column types

| Type string | Description |
|---|---|
| `'text'` | Plain text |
| `'long-text'` | Multi-line text (Markdown) |
| `'number'` | Integer or decimal |
| `'checkbox'` | Boolean |
| `'date'` | Date/datetime |
| `'single-select'` | Single choice from list |
| `'multiple-select'` | Multiple choices from list |
| `'email'` | Email address |
| `'url'` | URL |
| `'image'` | Image attachment |
| `'file'` | File attachment |
| `'collaborator'` | Reference to a SeaTable user |
| `'link'` | Link to another table row |
| `'formula'` | Computed formula column |

---

## 7. Reading Data — SQL vs listRows

This is a critical architectural decision. Use the right tool for the job:

### Use `querySQL` when you need:
- **Human-readable column names** in results (the only reliable way)
- Filtered results (`WHERE` clause)
- Sorted results (`ORDER BY`)
- Counted results (`COUNT(*)`)
- Paginated reads for display (use `LIMIT N OFFSET M`)

```php
// ✓ Returns rows with column names as keys
$query = new SqlQuery([
    'sql'          => "SELECT name, surname, `phone number` FROM Contacts LIMIT 10 OFFSET 20",
    'convert_keys' => true,
]);
$rows = $rowsApi->querySQL($uuid, $query)->getResults();
// $rows[0]['name'] === 'Alice'
```

**SQL default row limit**: SeaTable caps SQL results at **100 rows** by default when no `LIMIT`
is specified. Always add explicit `LIMIT N` for large tables.

### Use `listRows` when you need:
- **All rows** without a row-count cap (handles 1000s of rows via pagination)
- Only the row `_id` values (e.g. to batch-delete everything)
- Iterating through all data without knowing the count upfront

```php
// ✓ Fetches ALL rows safely, no SQL limit applies
$start = 0; $limit = 1000; $allRows = [];
do {
    $result   = $rowsApi->listRows($uuid, 'Contacts', null, $start, $limit);
    $chunk    = $result['rows'] ?? [];
    $allRows  = array_merge($allRows, $chunk);
    $start   += $limit;
} while (count($chunk) === $limit);
```

### Why `convert_keys` does NOT work in `listRows`

The `convert_keys` parameter in `listRows` is documented as converting internal column keys to
names, but **in practice it has no effect** (tested on SeaTable v4/v5 self-hosted). Rows always
come back with internal keys like `"pDkj"` regardless of the flag value.

**Only `querySQL` reliably returns human-readable column names.** Always prefer `querySQL` when
you need to access column data by name.

---

## 8. API Namespace Map

| Task | Class | Namespace |
|---|---|---|
| Exchange API-Token → Base-Token | `BaseTokenApi` | `SeaTable\Client\Auth` |
| Base metadata (tables, columns) | `BaseInfoApi` | `SeaTable\Client\Base` |
| Row CRUD + SQL queries | `RowsApi` | `SeaTable\Client\Base` |
| Column management | `ColumnsApi` | `SeaTable\Client\Base` |
| Table management | `TablesApi` | `SeaTable\Client\Base` |
| File uploads/downloads | `FilesApi` | `SeaTable\Client\File` |
| Row-to-row links | `LinksApi` | `SeaTable\Client\Base` |
| Account info, list bases | `UserApi`, `BasesApi` | `SeaTable\Client\User` |

Full endpoint lists: see `references/api-endpoints.md`

---

## 9. Self-Hosted Configuration

For self-hosted SeaTable instances, **always call `$config->setHost()`**. Without it, the SDK
defaults to `https://cloud.seatable.io`.

```php
$config = Configuration::getDefaultConfiguration();
$config->setAccessToken($token);
$config->setHost('https://your-seatable-server.com'); // no trailing slash, no path suffix
```

This must be set on **every** `Configuration` instance — one per API class. The `setHost()` call
is not global.

---

## 10. Error Handling

```php
try {
    $result = $rowsApi->appendRows($uuid, $request);
} catch (\SeaTable\Client\ApiException $e) {
    $code    = $e->getCode();           // HTTP status code
    $body    = $e->getResponseBody();   // Raw response body string
    $headers = $e->getResponseHeaders();

    // Common codes:
    // 401 — token expired or invalid
    // 403 — no permission on this base
    // 404 — base/table not found
    // 429 — rate limited
    error_log("SeaTable API error [{$code}]: {$body}");
    throw $e;
}
```

Note: accessing the wrong property type (e.g. `$stdClass['key']`) does NOT throw in PHP 8 —
it silently returns `null`. This can cause hard-to-diagnose bugs. Always verify the return type
before accessing results (see [Section 4](#4-return-type-reference--critical)).

---

## 11. Project Structure

```
my-backend/
├── composer.json
├── .env                       # SEATABLE_SERVER_URL, SEATABLE_API_TOKEN, SEATABLE_BASE_UUID
├── vendor/
├── src/
│   └── SeaTableClient.php     # Bootstrap: getAuth(), getRowsApi(), getColumnsApi(), getBaseInfoApi()
├── migrate.php                # CLI: create columns + seed data
├── rollback.php               # CLI: delete all rows + columns (clean slate)
├── api/
│   └── contacts.php           # JSON endpoint (paginated, uses querySQL)
└── index.php                  # Web frontend
```

**.env file:**
```
SEATABLE_SERVER_URL=https://your-seatable-server.com
SEATABLE_API_TOKEN=your_40char_api_token
SEATABLE_BASE_UUID=your-base-uuid-here   # optional, can also read from getAuth()['base_uuid']
```

---

## 12. Key Gotchas & Discoveries

These were discovered through hands-on testing with SDK v6.0.10 on a self-hosted instance.

### Return type inconsistency (the #1 source of bugs)

| Method | Top-level return | Nested values |
|---|---|---|
| `getBaseTokenWithApiToken()` | `array` | plain values |
| `getMetadata($uuid)` | `array` | `['metadata']` is **stdClass** |
| `querySQL($uuid, $q)` | **`SqlQueryResponse` object** | use getters: `getResults()` |
| `listRows($uuid, ...)` | `array` | `['rows']` is array of **stdClass** |
| `listColumns($name, $uuid)` | `array` | `['columns']` is array of **stdClass** |

### `listColumns` has reversed parameters

```php
// ✓ correct — table name FIRST, then uuid
$result = $columnsApi->listColumns($tableName, $uuid);

// ✗ wrong — flipped — returns wrong data or throws
$result = $columnsApi->listColumns($uuid, $tableName);
```

### `DeleteColumn` uses `'column'`, not `'column_name'`

```php
// ✓ correct
new DeleteColumn(['table_name' => 'T', 'column' => 'my col']);

// ✗ wrong — field is silently ignored, request may fail
new DeleteColumn(['table_name' => 'T', 'column_name' => 'my col']);
```

### SQL `COUNT(*)` works but result access needs care

```php
$q      = new SqlQuery(['sql' => "SELECT COUNT(*) FROM Table1", 'convert_keys' => true]);
$result = $rowsApi->querySQL($uuid, $q);
$rows   = $result->getResults();
// $rows[0] is an assoc array: ['COUNT(*)' => 103]
$count  = (int) array_values((array)$rows[0])[0]; // safest way to extract the count
```

### SQL has a default 100-row limit

Without an explicit `LIMIT`, `querySQL` returns at most 100 rows. For paginated APIs, always use:
```sql
SELECT col1, col2 FROM TableName LIMIT 10 OFFSET 20
```
For full-table operations (delete all, export), use `listRows` with a pagination loop instead.

### Column names with spaces in SQL

Use backticks around column names containing spaces or special characters:
```sql
SELECT name, surname, `phone number` FROM Contacts
```

### `appendRows` uses column names (not internal keys)

When inserting rows, specify values using the human-readable column name:
```php
'rows' => [
    ['name' => 'Alice', 'phone number' => '+1 555 0100'],  // ✓ column name
    // NOT: ['pDkj' => 'Alice', '8Ms8' => '+1 555 0100']  // ✗ internal key
]
```

### `getBaseTokenWithApiToken()` key is `dtable_uuid`

```php
$result   = $authApi->getBaseTokenWithApiToken();
$uuid     = $result['dtable_uuid'];    // ✓ correct key
// NOT:   $result['base_uuid'];        // ✗ this key does not exist
```

### Column metadata stdClass access

```php
$meta   = $baseInfoApi->getMetadata($uuid);
$tables = $meta['metadata']->tables;          // stdClass->property, NOT array
$name   = $meta['metadata']->tables[0]->name; // chain of -> accesses
```

### `listRows` rows are stdClass, not arrays

```php
$result = $rowsApi->listRows($uuid, 'Table1', null, 0, 100);
foreach ($result['rows'] as $row) {
    $id = $row->_id;        // ✓ stdClass property
    // NOT: $row['_id']     // ✗ wrong type
}
```

---

## 13. SQL Reference

See `references/sql-limitations.md` for the full SQL dialect reference.

### Quick examples

```sql
-- Count
SELECT COUNT(*) FROM Contacts

-- Paginated select with specific columns
SELECT name, surname, `phone number` FROM Contacts LIMIT 10 OFFSET 0

-- Filtered
SELECT * FROM Contacts WHERE surname = 'Smith'

-- Sorted
SELECT * FROM Contacts ORDER BY name ASC LIMIT 50

-- By row ID
SELECT * FROM Contacts WHERE _id = 'CxNAsIEjT8Oojx2I87gqCA'

-- All row IDs (for bulk delete) — but limited to 100 rows, use listRows for full tables
SELECT _id FROM Contacts LIMIT 100 OFFSET 0
```

### What SQL does NOT support

- No JOINs across tables (use `LinksApi` for relationships)
- No subqueries
- No window functions
- Default result cap of 100 rows when no `LIMIT` specified

---

## Reference Files

- `references/api-endpoints.md` — Full list of all API classes and their methods
- `references/sql-limitations.md` — SeaTable SQL dialect notes, limitations, and examples
