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
// $result->getResults() returns an array of row objects/arrays
```

> **`convert_keys`:** Use `true` to get human-readable column names (e.g. `cover_image`). Use `false` to get internal 4-char keys (e.g. `XX8w`) — required when column display names contain spaces or special characters that break SQL.

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

> ⚠️ **Extracting the new row ID:** `appendRows()` returns `row_ids` as an array of `stdClass` objects (not plain strings). Always extract `_id` defensively:
> ```php
> $responseArray = is_array($result) ? $result : (array) $result;
> $raw    = $responseArray['row_ids'][0] ?? '';
> $rowId  = is_array($raw) ? ($raw['_id'] ?? '') : (is_object($raw) ? $raw->_id : (string) $raw);
> ```

### Update — Update Row(s)
```php
use SeaTable\Client\Base\UpdateRows;
use SeaTable\Client\Base\UpdateRowsUpdatesInner;

$request = new UpdateRows([
    'table_name' => 'Users',
    'updates'    => [
        new UpdateRowsUpdatesInner([
            'row_id' => 'THE_ROW_ID',
            'row'    => (object)['Active' => false, 'Name' => 'Alice Updated'],
        ]),
    ],
]);

$rowsApi->updateRow($auth['base_uuid'], $request);
```

> ⚠️ **Critical: use `UpdateRows` + `UpdateRowsUpdatesInner`, not `UpdateRow`.** The SDK has a similarly-named `UpdateRow` model (singular) that generates a flat `{table_name, row_id, row}` payload. The API accepts this silently and returns `{"success":true}` — but **never applies the update**. Only the `updates` array format works. This is one of the most dangerous silent bugs in the SDK.

### Delete — Delete Row(s)
```php
$request = new SeaTable\Client\Base\DeleteRows([
    'table_name' => 'Users',
    'row_ids'    => ['ROW_ID_1', 'ROW_ID_2'],
]);

$rowsApi->deleteRow($auth['base_uuid'], $request);
```

> ⚠️ **Use `DeleteRows` (plural) with `row_ids` array, not `DeleteRow` (singular) with `row_id`.** Same silent-failure issue as `UpdateRow` — the API accepts `DeleteRow` and returns success without deleting anything.

### Get Single Row
```php
$row = $rowsApi->getRow($auth['base_uuid'], 'Users', $rowId);
```

> Note: `getRow()` may have strict `row_id` validation in some SDK versions. If it throws unexpectedly, use SQL instead: `SELECT * FROM Users WHERE _id='...' LIMIT 1`

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

## 10. Known Silent Bugs (SDK v4+)

These issues return `{"success":true}` with no error but do nothing. They are the most dangerous bugs to encounter.

| Wrong model | Correct model | Effect of wrong |
|---|---|---|
| `UpdateRow` | `UpdateRows` + `UpdateRowsUpdatesInner` | Update accepted silently, `_mtime` never changes, data not updated |
| `DeleteRow` | `DeleteRows` with `row_ids` array | Delete accepted silently, row still exists |

**Debugging tip:** After any `updateRow()` call, query `_mtime` on the row. If it hasn't changed, you used the wrong model.

---

## 11. Column Type Reference

Different column types require different value formats. Getting these wrong causes silent failures or data that stores but doesn't render.

### Image columns (`type: image`)

```php
// ✅ Correct — plain array of full absolute URL strings
'cover_image' => ['https://your-host.com/workspace/1/asset/uuid/images/2024-03/photo.jpg']

// ❌ Wrong — {name, url} object format (stores in API but image won't render in UI)
'cover_image' => [['name' => 'photo.jpg', 'url' => '/workspace/1/asset/...']]

// ❌ Wrong — relative URL (won't render)
'cover_image' => ['/workspace/1/asset/uuid/images/2024-03/photo.jpg']
```

The full URL must include the scheme and host: `https://your-seatable-server.com/workspace/{workspace_id}/asset/{base_uuid}/images/{year-month}/{filename}`

### File columns (`type: file`)

```php
// ✅ Correct for file columns — {name, url} object array
'attachment' => [['name' => 'doc.pdf', 'url' => '/workspace/1/asset/uuid/files/2024-03/doc.pdf']]
```

> **Image vs File columns have opposite formats.** Image columns take plain URL strings; file columns take `{name, url}` objects.

### Link columns (`type: link` — "Link to other records")

Link columns cannot be set via `updateRow()`. They must be managed through `LinksApi`. See Section 12.

### Text, number, boolean, date columns

Standard PHP scalar values — no surprises.

---

## 12. Link Columns (Row-to-Row Relationships)

Link columns ("Link to other records") require `LinksApi`, not `RowsApi`. You need the internal `table_id`, `other_table_id`, and `link_id` — all 4-character internal identifiers discoverable via `BaseInfoApi::getMetadata()`.

```php
use SeaTable\Client\Base\LinksApi;
use SeaTable\Client\Base\RowLinkCreateUpdateDelete;

$linksConfig = (new Configuration())->setAccessToken($baseToken);
if (!empty($_ENV['SEATABLE_HOST'])) $linksConfig->setHost($_ENV['SEATABLE_HOST']);
$linksApi = new LinksApi(new GuzzleClient(), $linksConfig);

$request = new RowLinkCreateUpdateDelete([
    'table_id'           => 'Ab12',        // 4-char ID of the source table
    'other_table_id'     => 'Cd34',        // 4-char ID of the target table
    'link_id'            => 'Ef56',        // 4-char link_id of the column
    'other_rows_ids_map' => [
        $sourceRowId => [$targetRowId],    // map: one source row → array of target row IDs
    ],
]);

$linksApi->createRowLink($baseUuid, $request);
```

**How to discover table IDs and link IDs:**

```php
$baseInfoApi = new SeaTable\Client\Base\BaseInfoApi(new GuzzleClient(), $config);
$metadata    = $baseInfoApi->getMetadata($baseUuid);
// Inspect $metadata['tables'] → each table has 'id' (4-char) and 'columns'
// Link columns have 'type' === 'link' and a 'data' object containing 'link_id'
```

Run this once per project and hardcode the IDs in `.env` — they don't change.

**Lookup-or-create pattern** (e.g. for a categories table):

```php
function lookupOrCreateRow(RowsApi $rowsApi, string $baseUuid, string $table, string $nameCol, string $value): string {
    // 1. Try to find existing row
    $sql    = sprintf("SELECT _id FROM %s WHERE %s='%s' LIMIT 1", $table, $nameCol, addslashes($value));
    $result = $rowsApi->querySQL($baseUuid, new SqlQuery(['sql' => $sql, 'convert_keys' => false]));
    $rows   = $result->getResults();

    if (!empty($rows)) {
        $row = is_array($rows[0]) ? $rows[0] : (array) $rows[0];
        return $row['_id'];
    }

    // 2. Create if not found
    $insert = $rowsApi->appendRows($baseUuid, new AppendRows([
        'table_name' => $table,
        'rows'       => [[$nameCol => $value]],
    ]));
    $res    = is_array($insert) ? $insert : (array) $insert;
    $raw    = $res['row_ids'][0] ?? '';
    // row_ids[0] is a stdClass object, not a plain string
    return is_array($raw) ? ($raw['_id'] ?? '') : (is_object($raw) ? $raw->_id : (string) $raw);
}
```

---

## 13. Reusable Client Template

A ready-to-use `SeaTableClient.php` is available at `templates/SeaTableClient.php`.

**Copy it into any new project:**

```bash
cp ~/.claude/skills/seatable-php/templates/SeaTableClient.php src/SeaTableClient.php
```

Then adjust the namespace at the top to match your project.

**What it provides:**

| Method | Description |
|---|---|
| `connect()` | Auth token exchange + initialise all API clients |
| `query(string $sql, bool $convertKeys)` | SQL query → array of rows |
| `insertRow(string $table, array $data)` | Append row, return `_id` (handles stdClass extraction) |
| `updateRow(string $table, string $rowId, array $fields)` | Safe update via `UpdateRows` plural model |
| `deleteRows(string $table, array $rowIds)` | Batched delete via `DeleteRows` plural model |
| `clearTable(string $table)` | Delete all rows (useful for test resets) |
| `uploadImage(string $localPath, string $fileName)` | Upload file, return full absolute URL |
| `patchImageColumn(string $table, string $rowId, string $column, string $url)` | Set image column to uploaded URL |
| `lookupOrCreate(string $table, string $nameCol, string $value)` | Find or create row, return `_id` |
| `linkRows(...)` | Link two rows via a "Link to other records" column |
| `getMetadata()` | Return all tables + columns for discovering internal IDs |

**Minimum `.env` keys required:**

```env
SEATABLE_HOST=https://your-seatable-server.com
SEATABLE_API_TOKEN=your_40_char_token
SEATABLE_BASE_UUID=your-base-uuid
```

**Quickstart example:**

```php
$client = new SeaTableClient();
$client->connect();

// Insert a row
$rowId = $client->insertRow('Products', ['name' => 'Widget', 'price' => 9.99]);

// Upload an image and attach it
$url = $client->uploadImage('/tmp/photo.jpg', 'widget.jpg');
$client->patchImageColumn('Products', $rowId, 'cover_image', $url);

// Link to a category (lookup-or-create)
$catId = $client->lookupOrCreate('categories', 'category', 'Hardware');
$client->linkRows($rowId, $catId, $linkId, $productsTableId, $categoriesTableId);
```

**Discover table IDs and link IDs** — use the ready-made script (see Section 14).

---

## 14. New Project Checklist

Follow these steps at the start of every new SeaTable PHP project:

**Step 1 — Copy the reusable client:**
```bash
cp ~/.claude/skills/seatable-php/templates/SeaTableClient.php src/SeaTableClient.php
```
Adjust the namespace at the top to match your project.

**Step 2 — Set up `.env`:**
```bash
cp ~/.claude/skills/seatable-php/templates/.env.example .env
```
Fill in `SEATABLE_HOST`, `SEATABLE_API_TOKEN`, and `SEATABLE_BASE_UUID`. Leave the `TABLE_ID_*` / `LINK_ID_*` lines commented out for now — Step 4 generates those.

**Step 3 — Test the connection before writing any feature code:**
```bash
cp ~/.claude/skills/seatable-php/templates/test-connection.php test-connection.php
php test-connection.php
```

Expected output:
```
[OK] All required .env keys present
[OK] Connected to SeaTable (token exchanged successfully)
[OK] Base reachable — 3 table(s) found
     - audiobooks  (42 rows)
     - categories  (0 rows)
[OK] All checks passed — ready to build!
```

If this step fails, fix the credentials before proceeding. Do not start implementing features against broken credentials.

**Step 4 — Run the discovery script** (required if your schema has any link columns):
```bash
cp ~/.claude/skills/seatable-php/templates/discover-ids.php discover-ids.php
php discover-ids.php
```

The script prints all table IDs, column keys, and link IDs in `.env`-ready format, e.g.:
```
TABLE_ID_AUDIOBOOKS=Ab12
TABLE_ID_CATEGORIES=Cd34
LINK_ID_CATEGORIES=Ef56
```

Copy those values into `.env`, then delete `discover-ids.php`.

**Step 4 — Hardcode the IDs** in your application code (or load from `.env`):
```php
$client->linkRows($sourceRowId, $targetRowId,
    $_ENV['LINK_ID_CATEGORIES'],
    $_ENV['TABLE_ID_AUDIOBOOKS'],
    $_ENV['TABLE_ID_CATEGORIES'],
);
```

> ⚠️ **Never skip Step 3 if you have link columns.** The IDs are not guessable. Attempting to wire link columns without running the discovery script leads to silent failures that are hard to debug.

---

## Reference Files

- `references/api-endpoints.md` — Full list of all available API classes and methods
- `references/sql-limitations.md` — SeaTable SQL dialect notes and supported functions
- `templates/SeaTableClient.php` — Reusable client class (copy into new projects)
- `templates/.env.example` — All required `.env` keys with comments (copy to project root)
- `templates/test-connection.php` — Verify credentials and list tables before building
- `templates/discover-ids.php` — One-off script to print all table/link IDs in `.env` format
