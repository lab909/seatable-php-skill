# SeaTable PHP SDK — API Endpoints Reference

Full endpoint reference for `seatable/seatable-api-php`.
All URIs are relative to the configured host (e.g. `https://your-seatable-server.com`).

---

## Auth Namespace (`SeaTable\Client\Auth`)

### BaseTokenApi
| Method | SDK Call | HTTP | Description |
|---|---|---|---|
| Get Base-Token from API-Token | `getBaseTokenWithApiToken()` | GET `/api-gateway/api/v2/dtables/app-access-token/` | Most common auth flow for backend apps |
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
| Method | SDK Call | Description |
|---|---|---|
| Get metadata | `getMetadata($base_uuid)` | Returns all tables, columns, views |
| List collaborators | `listCollaborators($base_uuid)` | |

### RowsApi
| Method | SDK Call | Description |
|---|---|---|
| Query with SQL | `querySQL($base_uuid, SqlQuery $query)` | Most flexible read method |
| List rows | `listRows($base_uuid, $table_name, ...)` | Paginated, optional view filter |
| Get single row | `getRow($base_uuid, $table_name, $row_id)` | |
| Append rows | `appendRows($base_uuid, AppendRows $req)` | Insert one or many rows |
| Update rows | `updateRow($base_uuid, UpdateRows $req)` | Update by row_id |
| Delete rows | `deleteRow($base_uuid, DeleteRows $req)` | Delete by row_id array |
| Lock rows | `lockRows($base_uuid, ...)` | |
| Unlock rows | `unlockRows($base_uuid, ...)` | |

### TablesApi
| Method | SDK Call | Description |
|---|---|---|
| Create table | `createTable($base_uuid, ...)` | |
| Rename table | `renameTable($base_uuid, ...)` | |
| Delete table | `deleteTable($base_uuid, ...)` | |
| Duplicate table | `duplicateTable($base_uuid, ...)` | |

### ColumnsApi
| Method | SDK Call | Description |
|---|---|---|
| List columns | `listColumns($base_uuid, $table_name)` | |
| Insert column | `insertColumn($base_uuid, ...)` | |
| Append columns | `appendColumns($base_uuid, AppendColumnsRequest $req)` | Batch insert |
| Update column | `updateColumn($base_uuid, ...)` | |
| Delete column | `deleteColumn($base_uuid, ...)` | |
| Add select options | `addSelectOption($base_uuid, ...)` | For single/multi-select columns |
| Update select options | `updateSelectOption($base_uuid, ...)` | |
| Delete select options | `deleteSelectOption($base_uuid, ...)` | |

### ViewsApi
| Method | SDK Call | Description |
|---|---|---|
| List views | `listViews($base_uuid, $table_name)` | |
| Create view | `createView($base_uuid, ...)` | |
| Get view | `getView($base_uuid, $table_name, $view_name)` | |
| Update view | `updateView(...)` | |
| Delete view | `deleteView(...)` | |

### LinksApi (row-to-row relationships)
| Method | SDK Call | Description |
|---|---|---|
| List row links | `listRowLinks($base_uuid, ...)` | |
| Create row links | `createRowLink($base_uuid, ...)` | |
| Update row links | `updateRowLink($base_uuid, ...)` | |
| Delete row links | `deleteRowLink($base_uuid, ...)` | |

### SnapshotsApi
| Method | SDK Call | Description |
|---|---|---|
| Create snapshot | `createSnapshot($base_uuid)` | Manual base backup |

---

## File Namespace (`SeaTable\Client\File`)

File uploads are a two-step process: get upload link → POST file to that URL.

| Method | SDK Call | Description |
|---|---|---|
| Get upload link | `getUploadLink($base_uuid)` | Returns signed upload URL |
| Get download link | `getFileDownloadLink($base_uuid, $path)` | |
| Delete asset | `deleteBaseAsset($base_uuid, ...)` | |

**Upload flow — complete example:**

File upload is a **two-step process**. The SDK only handles step 1 (getting the signed URL); step 2 requires a raw multipart HTTP POST.

```php
use SeaTable\Client\Configuration;
use SeaTable\Client\File\FilesApi;
use SeaTable\Client\Base\UpdateRows;
use GuzzleHttp\Client as HttpClient;

// --- Step 1: Get a signed upload URL from SeaTable ---
$config = Configuration::getDefaultConfiguration();
$config->setAccessToken($auth['access_token']);
$config->setHost($_ENV['SEATABLE_SERVER_URL']);

$httpClient = new HttpClient();
$filesApi   = new FilesApi($httpClient, $config);

$uploadInfo = $filesApi->getUploadLink($base_uuid);
// $uploadInfo keys:
//   'upload_link'        — the URL to POST the file to
//   'parent_path'        — base asset directory (e.g. /asset/some-uuid)
//   'file_relative_path' — subdirectory for generic files (e.g. /files/2024-01)
//   'img_relative_path'  — subdirectory for images (e.g. /images/2024-01)

// --- Step 2: POST the file via multipart form to the signed URL ---
// Required form fields: parent_dir, relative_path, replace, file
$filePath = '/path/to/invoice.pdf';
$fileName = basename($filePath);

$httpClient->post($uploadInfo['upload_link'], [
    'multipart' => [
        // parent_dir: the base asset path returned from step 1
        ['name' => 'parent_dir',     'contents' => $uploadInfo['parent_path']],
        // relative_path: use file_relative_path for docs/PDFs, img_relative_path for images
        ['name' => 'relative_path',  'contents' => $uploadInfo['file_relative_path']],
        // replace: '1' overwrites if a file with the same name exists, '0' keeps both
        ['name' => 'replace',        'contents' => '1'],
        // file: the actual file resource with its filename
        ['name' => 'file',           'contents' => fopen($filePath, 'r'), 'filename' => $fileName],
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $auth['access_token'],
    ],
]);

// --- Step 3: Attach the uploaded file to a row ---
// ⚠️ COLUMN TYPE MATTERS — the value format differs between file and image columns:

// For FILE columns (type: "file") — use {name, url} object array:
$storedPath = $uploadInfo['parent_path'] . '/' . $uploadInfo['file_relative_path'] . '/' . $fileName;
$rowsApi = new \SeaTable\Client\Base\RowsApi($httpClient, $config);
$request = new UpdateRows([
    'table_name' => 'Invoices',
    'updates'    => [
        new \SeaTable\Client\Base\UpdateRowsUpdatesInner([
            'row_id' => $rowId,
            'row'    => (object)[
                'Attachment' => [[           // ← array of objects for file columns
                    'name' => $fileName,
                    'url'  => $storedPath,
                ]],
            ],
        ]),
    ],
]);
$rowsApi->updateRow($base_uuid, $request);

// For IMAGE columns (type: "image") — use plain full-URL string array:
$host      = $_ENV['SEATABLE_HOST'] ?? 'https://cloud.seatable.io';
$imageUrl  = "{$host}/workspace/{$workspaceId}{$uploadInfo['parent_path']}/{$uploadInfo['img_relative_path']}/{$fileName}";
$request = new UpdateRows([
    'table_name' => 'Products',
    'updates'    => [
        new \SeaTable\Client\Base\UpdateRowsUpdatesInner([
            'row_id' => $rowId,
            'row'    => (object)[
                'cover_image' => [$imageUrl],   // ← plain URL string array for image columns
            ],
        ]),
    ],
]);
$rowsApi->updateRow($base_uuid, $request);
```

**Key rules for file/image uploads:**
- For PDFs and generic files use `file_relative_path`; for images use `img_relative_path`
- The `replace` field: `'1'` = overwrite existing file, `'0'` = keep both (SeaTable auto-renames)
- The `Authorization` header must be included on the raw POST; the SDK does not do this for you
- **FILE columns** (`type: file`): value is `[['name' => $name, 'url' => $relativePath]]` — array of objects
- **IMAGE columns** (`type: image`): value is `[$fullAbsoluteUrl]` — plain array of full URL strings (scheme + host required). Using `{name, url}` objects or relative URLs will store data but the image will not render in the SeaTable UI.
- Always use `UpdateRows` + `UpdateRowsUpdatesInner` (plural). `UpdateRow` (singular) silently does nothing.

---

## SysAdmin / TeamAdmin Namespaces

Available but rarely used in backend app development. See full docs at:
https://github.com/seatable/seatable-api-php
