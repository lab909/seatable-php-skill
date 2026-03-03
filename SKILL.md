---
name: seatable-php
description: >
  Use this skill whenever you are developing a PHP backend application that needs to use SeaTable as a database or data store. Triggers include any mention of SeaTable, requests to build a PHP API or backend that reads/writes structured data to SeaTable, setting up the SeaTable PHP SDK, performing CRUD operations on SeaTable bases, or integrating SeaTable into a Laravel/Slim/vanilla PHP project. If the user says "use SeaTable", "connect to SeaTable", "store data in SeaTable", or "query my SeaTable base", always use this skill immediately.
---

# SeaTable PHP SDK Skill

SeaTable is an Airtable-like cloud spreadsheet/database. This skill covers everything needed to build PHP backends that use SeaTable as a data store via the official `seatable/seatable-api-php` SDK.

> ⚠️ The current SDK (v4+) was auto-generated from OpenAPI and is **not compatible** with v0.2 or earlier. Always use the current API patterns documented here.

---

## 1. Installation

```bash
composer require seatable/seatable-api-php
```

Requires PHP 7.4+ and Composer. The SDK depends on `guzzlehttp/guzzle` (auto-installed).

---

## 2. Authentication — Three Tokens

SeaTable uses a **three-token hierarchy**. Understanding this is essential:

| Token | Length | Expires | Used for |
|---|---|---|---|
| **Account-Token** | 40 chars | Never | Account-level operations (list bases, manage users) |
| **API-Token** | 40 chars | Never | Per-base password; generates a Base-Token |
| **Base-Token** | 400+ chars (JWT) | **3 days** | All base CRUD operations |

**For most backend apps, you only need an API-Token** (created in the SeaTable UI per base). You exchange it for a Base-Token at runtime.

All requests use: `Authorization: Bearer <token>`

---

## 3. Project Setup Pattern

Always store credentials in environment variables, never hardcoded.

**`.env` (or environment)**
```
SEATABLE_SERVER_URL=https://your-seatable-server.com
SEATABLE_API_TOKEN=your_api_token_here
```

**Bootstrap / connection helper:**
```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

function getSeaTableBaseToken(): array {
    $config = SeaTable\Client\Configuration::getDefaultConfiguration();
    $config->setAccessToken($_ENV['SEATABLE_API_TOKEN']);
    $config->setHost($_ENV['SEATABLE_SERVER_URL']);  // omit for cloud.seatable.io

    $authApi = new SeaTable\Client\Auth\BaseTokenApi(
        new GuzzleHttp\Client(),
        $config
    );

    $result = $authApi->getBaseTokenWithApiToken();

    return [
        'base_uuid'    => $result['dtable_uuid'],
        'access_token' => $result['access_token'],
        'config'       => $config,
    ];
}

function getRowsApi(array $auth): SeaTable\Client\Base\RowsApi {
    $config = SeaTable\Client\Configuration::getDefaultConfiguration();
    $config->setAccessToken($auth['access_token']);
    $config->setHost($_ENV['SEATABLE_SERVER_URL']);
    return new SeaTable\Client\Base\RowsApi(new GuzzleHttp\Client(), $config);
}
```

> ⚠️ **Base-Tokens expire after 3 days.** In long-running apps (daemons, queues), implement token refresh logic. In request-scoped apps (HTTP APIs), fetching a fresh token per request is acceptable but adds latency — consider short-lived caching (e.g., APCu or Redis with a 2-day TTL).

---

## 4. Core CRUD Operations

### Read — SQL Query (recommended for flexibility)
```php
$auth = getSeaTableBaseToken();
$rowsApi = getRowsApi($auth);

$query = new SeaTable\Client\Base\SqlQuery([
    'sql'          => "SELECT * FROM Users WHERE active = 1 LIMIT 100",
    'convert_keys' => true,   // use column names as keys instead of internal keys
]);

$result = $rowsApi->querySQL($auth['base_uuid'], $query);
// $result['results'] is an array of row objects
```

### Read — List Rows (paginated)
```php
$result = $rowsApi->listRows($auth['base_uuid'], 'YourTableName');
// Optional params: view_name, limit, start (for pagination)
```

### Create — Append Rows
```php
$request = new SeaTable\Client\Base\AppendRows([
    'table_name'    => 'Users',
    'rows'          => [
        ['Name' => 'Alice', 'Email' => 'alice@example.com', 'Active' => true],
        ['Name' => 'Bob',   'Email' => 'bob@example.com',   'Active' => false],
    ],
    'apply_default' => true,  // apply column default values
]);

$result = $rowsApi->appendRows($auth['base_uuid'], $request);
```

### Update — Update Row(s)
```php
// You need the row's _id (the internal SeaTable row identifier)
$request = new SeaTable\Client\Base\UpdateRows([
    'table_name' => 'Users',
    'updates'    => [
        [
            'row_id' => 'THE_ROW_ID',
            'row'    => ['Active' => false, 'Name' => 'Alice Updated'],
        ],
    ],
]);

$rowsApi->updateRow($auth['base_uuid'], $request);
```

### Delete — Delete Row(s)
```php
$request = new SeaTable\Client\Base\DeleteRows([
    'table_name' => 'Users',
    'row_ids'    => ['ROW_ID_1', 'ROW_ID_2'],
]);

$rowsApi->deleteRow($auth['base_uuid'], $request);
```

### Get Single Row
```php
$row = $rowsApi->getRow($auth['base_uuid'], 'Users', $rowId);
```

---

## 5. API Namespace Map

The SDK is split into namespaces. Always use the correct one:

| Task | Class | Namespace |
|---|---|---|
| Exchange API-Token for Base-Token | `BaseTokenApi` | `SeaTable\Client\Auth` |
| Get account info, list bases | `UserApi`, `BasesApi` | `SeaTable\Client\User` |
| Base metadata | `BaseInfoApi` | `SeaTable\Client\Base` |
| Row CRUD + SQL | `RowsApi` | `SeaTable\Client\Base` |
| Column management | `ColumnsApi` | `SeaTable\Client\Base` |
| Table management | `TablesApi` | `SeaTable\Client\Base` |
| File upload/download | `FilesApi` | `SeaTable\Client\File` |
| Links between rows | `LinksApi` | `SeaTable\Client\Base` |

Full endpoint lists: see `references/api-endpoints.md`

---

## 6. Self-Hosted Server Configuration

Since the user has a **self-hosted SeaTable instance**, always call `$config->setHost(...)`. Without it the SDK defaults to `https://cloud.seatable.io`.

```php
$config = SeaTable\Client\Configuration::getDefaultConfiguration();
$config->setAccessToken($token);
$config->setHost('https://your-seatable-server.com');  // ← always required for self-hosted
```

The host should be the root URL with no trailing slash and no path suffix.

---

## 7. Error Handling

All SDK calls throw exceptions on failure. Always wrap in try/catch:

```php
try {
    $result = $rowsApi->appendRows($auth['base_uuid'], $request);
} catch (\SeaTable\Client\ApiException $e) {
    $code    = $e->getCode();           // HTTP status code
    $body    = $e->getResponseBody();   // Raw response body
    $headers = $e->getResponseHeaders();
    // Log and handle: 401 = token expired/invalid, 403 = no permission,
    // 404 = base/table not found, 429 = rate limited
    error_log("SeaTable API error [{$code}]: {$body}");
    throw $e;
}
```

---

## 8. Typical Project Structure

```
my-backend/
├── composer.json
├── .env                      # SEATABLE_SERVER_URL, SEATABLE_API_TOKEN
├── src/
│   ├── SeaTable/
│   │   ├── Client.php        # bootstrap helper (getBaseToken, getRowsApi, etc.)
│   │   └── Repository/
│   │       ├── UserRepository.php
│   │       └── OrderRepository.php
│   └── ...
└── vendor/
```

A **Repository pattern** is recommended: each SeaTable table gets its own repository class that internally uses the SDK, keeping CRUD logic out of controllers/handlers.

---

## 9. Key Gotchas

- **`convert_keys: true`** in SQL queries maps SeaTable's internal column keys to human-readable column names — almost always what you want.
- Row IDs (`_id`) are returned in query results; store them if you need to update/delete later.
- SeaTable column names are case-sensitive.
- The `apply_default` flag in `AppendRows` controls whether default column values are applied.
- For **file uploads**, use `FilesApi::getUploadLink()` first to get a signed URL, then POST the file to that URL — the SDK does not upload the file directly.
- SeaTable SQL supports `SELECT`, `INSERT`, `UPDATE`, `DELETE` but is not full SQL — check `references/sql-limitations.md` for what's supported.

---

## Reference Files

- `references/api-endpoints.md` — Full list of all available API classes and methods
- `references/sql-limitations.md` — SeaTable SQL dialect notes and supported functions
