# SeaTable PHP SDK — API Endpoints Reference

Full endpoint reference for `seatable/seatable-api-php` (validated on v6.0.10).
All URIs are relative to the configured host (e.g. `https://your-seatable-server.com`).

> ⚠️ Always check return types in `SKILL.md §4` before accessing results.
> Many methods return `stdClass` or typed objects, not plain arrays.

---

## Auth Namespace (`SeaTable\Client\Auth`)

### BaseTokenApi

| Method | SDK Call | HTTP | Notes |
|---|---|---|---|
| Get Base-Token from API-Token | `getBaseTokenWithApiToken()` | GET `/api-gateway/api/v2/dtables/app-access-token/` | Most common auth flow. Result key is `dtable_uuid` (not `base_uuid`) |
| Get Base-Token from Account-Token | `getBaseTokenWithAccountToken($base_uuid)` | GET | Requires Account-Token |
| Get Base-Token from External Link | `getBaseTokenWithExternalLink($external_link)` | GET | Public/shared access |

---

## User Namespace (`SeaTable\Client\User`)

### UserApi

| Method | SDK Call | Description |
|---|---|---|
| Get account info | `getAccountInfo()` | Requires Account-Token |

### BasesApi

| Method | SDK Call | Description |
|---|---|---|
| List bases | `listBases()` | Lists all bases the account can access |

---

## Base Namespace (`SeaTable\Client\Base`)

All Base methods require a **Base-Token** and a `$base_uuid`.

### BaseInfoApi

| Method | SDK Call | Return | Description |
|---|---|---|---|
| Get metadata | `getMetadata($base_uuid)` | `array` (but `['metadata']` is stdClass) | Returns all tables, columns, views. Access via `->tables`, `->views` |
| List collaborators | `listCollaborators($base_uuid)` | varies | |

**Metadata access pattern:**
```php
$meta      = $baseInfoApi->getMetadata($uuid);
$tableName = $meta['metadata']->tables[0]->name;
$columns   = $meta['metadata']->tables[0]->columns; // array of stdClass
```

---

### RowsApi

| Method | SDK Call | Return | Description |
|---|---|---|---|
| Query with SQL | `querySQL($base_uuid, SqlQuery $query)` | `SqlQueryResponse` object | Use `->getResults()` getter — never array/property access |
| List rows | `listRows($base_uuid, $table_name, $view=null, $start=null, $limit=null, $convert_keys=null)` | `array` with `['rows']` as stdClass array | `convert_keys` has no effect — always returns internal keys |
| Get single row | `getRow($base_uuid, $row_id, $table_name, $convert_keys=null)` | varies | |
| Append rows | `appendRows($base_uuid, AppendRows $req)` | varies | Use column names (not internal keys) in row data |
| Update rows | `updateRow($base_uuid, UpdateRows $req)` | varies | Requires row `_id` |
| Delete rows | `deleteRow($base_uuid, DeleteRows $req)` | varies | Pass array of `_id` strings in `row_ids` |
| Lock rows | `lockRows($base_uuid, ...)` | — | |
| Unlock rows | `unlockRows($base_uuid, ...)` | — | |

**`SqlQueryResponse` getters:**
```php
$response = $rowsApi->querySQL($uuid, $query);
$rows     = $response->getResults();   // array of assoc arrays
$meta     = $response->getMetadata();  // column metadata
$ok       = $response->getSuccess();   // bool
```

**`listRows` row access (stdClass):**
```php
$result = $rowsApi->listRows($uuid, 'Table1', null, $start, $limit);
$id     = $result['rows'][0]->_id;    // stdClass property
```

---

### TablesApi

| Method | SDK Call | Description |
|---|---|---|
| Create table | `createTable($base_uuid, ...)` | |
| Rename table | `renameTable($base_uuid, ...)` | |
| Delete table | `deleteTable($base_uuid, ...)` | |
| Duplicate table | `duplicateTable($base_uuid, ...)` | |

---

### ColumnsApi

> ⚠️ `listColumns` has **reversed parameter order** compared to all other methods:
> `listColumns($table_name, $base_uuid)` — table first, UUID second.

| Method | SDK Call | Description |
|---|---|---|
| List columns | `listColumns($table_name, $base_uuid)` | Returns `array['columns']` of stdClass objects |
| Insert column | `insertColumn($base_uuid, InsertColumnRequest $req)` | Fields: `table_name`, `column_name`, `column_type` |
| Append columns | `appendColumns($base_uuid, AppendColumnsRequest $req)` | Batch insert via `columns` array of `AppendColumnsRequestColumnsInner` |
| Update column | `updateColumn($base_uuid, ...)` | |
| Delete column | `deleteColumn($base_uuid, DeleteColumn $req)` | Field is `'column'` (not `'column_name'`) + `'table_name'` |
| Add select options | `addSelectOption($base_uuid, ...)` | For single/multi-select columns |
| Update select options | `updateSelectOption($base_uuid, ...)` | |
| Delete select options | `deleteSelectOption($base_uuid, ...)` | |

**Column access (stdClass):**
```php
$result  = $columnsApi->listColumns($tableName, $uuid); // ← table first!
$columns = $result['columns'];
$name    = $columns[0]->name;   // stdClass property
$key     = $columns[0]->key;    // internal column ID
$type    = $columns[0]->type;
```

---

### ViewsApi

| Method | SDK Call | Description |
|---|---|---|
| List views | `listViews($base_uuid, $table_name)` | |
| Create view | `createView($base_uuid, ...)` | |
| Get view | `getView($base_uuid, $table_name, $view_name)` | |
| Update view | `updateView(...)` | |
| Delete view | `deleteView(...)` | |

---

### LinksApi (row-to-row relationships)

| Method | SDK Call | Description |
|---|---|---|
| List row links | `listRowLinks($base_uuid, ...)` | |
| Create row links | `createRowLink($base_uuid, ...)` | |
| Update row links | `updateRowLink($base_uuid, ...)` | |
| Delete row links | `deleteRowLink($base_uuid, ...)` | |

---

### SnapshotsApi

| Method | SDK Call | Description |
|---|---|---|
| Create snapshot | `createSnapshot($base_uuid)` | Manual base backup |

---

## File Namespace (`SeaTable\Client\File`)

File uploads are a two-step process: get upload link → POST file to that URL.
The SDK only handles step 1.

| Method | SDK Call | Description |
|---|---|---|
| Get upload link | `getUploadLink($base_uuid)` | Returns signed upload URL + paths |
| Get download link | `getFileDownloadLink($base_uuid, $path)` | |
| Delete asset | `deleteBaseAsset($base_uuid, ...)` | |

**Complete upload flow:**

```php
use SeaTable\Client\File\FilesApi;
use SeaTable\Client\Base\UpdateRows;

$filesApi   = new FilesApi(new HttpClient(), makeConfig($auth));
$uploadInfo = $filesApi->getUploadLink($uuid);

// $uploadInfo keys:
//   'upload_link'        — POST target URL
//   'parent_path'        — base asset dir (e.g. /asset/uuid)
//   'file_relative_path' — subdir for docs/PDFs (e.g. /files/2024-01)
//   'img_relative_path'  — subdir for images (e.g. /images/2024-01)

$filePath = '/tmp/invoice.pdf';
$fileName = basename($filePath);

// POST the file directly (SDK does not do this for you)
(new HttpClient())->post($uploadInfo['upload_link'], [
    'multipart' => [
        ['name' => 'parent_dir',    'contents' => $uploadInfo['parent_path']],
        ['name' => 'relative_path', 'contents' => $uploadInfo['file_relative_path']],
        ['name' => 'replace',       'contents' => '1'],  // '1' = overwrite, '0' = keep both
        ['name' => 'file',          'contents' => fopen($filePath, 'r'), 'filename' => $fileName],
    ],
    'headers' => ['Authorization' => 'Bearer ' . $auth['access_token']],
]);

// Attach to row: file columns are arrays of {name, url} objects
$storedPath = $uploadInfo['parent_path'] . '/' . $uploadInfo['file_relative_path'] . '/' . $fileName;
$rowsApi->updateRow($uuid, new UpdateRows([
    'table_name' => 'Invoices',
    'updates'    => [[
        'row_id' => $rowId,
        'row'    => ['Attachment' => [['name' => $fileName, 'url' => $storedPath]]],
    ]],
]));
```

**Key file upload rules:**
- Use `file_relative_path` for PDFs/docs, `img_relative_path` for images
- `replace: '1'` overwrites; `'0'` keeps both (SeaTable auto-renames)
- File column values are **arrays of objects** — always wrap in `[]` even for a single file
- Must include `Authorization` header on the raw POST — the SDK does not add it

---

## SysAdmin / TeamAdmin Namespaces

Available for admin operations (user management, team management). Not covered here.
See: https://github.com/seatable/seatable-api-php
