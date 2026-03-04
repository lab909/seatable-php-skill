# SeaTable SQL — Dialect Notes & Limitations

SeaTable supports a SQL-like query language via `RowsApi::querySQL()`.
It is **not** full SQL — there are important differences from MySQL/PostgreSQL.

> Validated on SeaTable v4/v5 self-hosted, SDK v6.0.10.

---

## Supported Statements

- `SELECT` — full support with `WHERE`, `ORDER BY`, `LIMIT`, `OFFSET`, `GROUP BY`
- `INSERT` — limited, prefer `appendRows()` SDK method
- `UPDATE` — limited, prefer `updateRow()` SDK method
- `DELETE` — limited, prefer `deleteRow()` SDK method

**In practice: use SQL only for `SELECT` queries.** Use dedicated SDK methods for writes.

---

## `querySQL` Return Type

```php
$response = $rowsApi->querySQL($uuid, new SqlQuery([
    'sql'          => "SELECT ...",
    'convert_keys' => true,
]));

// ✓ Returns SqlQueryResponse — use GETTERS only:
$rows = $response->getResults();   // array of assoc arrays (with convert_keys: true)
$meta = $response->getMetadata();  // array of column metadata stdClass objects
$ok   = $response->getSuccess();   // bool

// ✗ These silently return null — do NOT use:
$rows = $response['results'];
$rows = $response->results;
```

---

## `convert_keys` Parameter

```php
$query = new SqlQuery([
    'sql'          => "SELECT * FROM Contacts",
    'convert_keys' => true,   // ← IMPORTANT
]);
```

- `convert_keys: true` → result keys are column **names** (e.g. `"name"`, `"phone number"`)
- `convert_keys: false` → result keys are internal column IDs (e.g. `"0000"`, `"pDkj"`)

**Always use `true`** unless you specifically need internal keys.

> ⚠️ `convert_keys` only works in `querySQL`. In `listRows`, this parameter has **no effect** —
> rows always use internal keys regardless of the value passed. Use `querySQL` whenever you need
> human-readable column names.

---

## Default Row Limit

**SeaTable's SQL engine caps results at 100 rows by default** when no `LIMIT` is specified.

```sql
-- ✗ Returns at most 100 rows even if there are 10,000:
SELECT * FROM Contacts

-- ✓ Explicit LIMIT for paginated reads:
SELECT name, surname FROM Contacts LIMIT 10 OFFSET 0

-- ✓ Larger limit if needed (check your instance's max):
SELECT _id FROM Contacts LIMIT 10000 OFFSET 0
```

For fetching **all rows** (e.g. to bulk-delete), use `listRows` with a pagination loop instead
of SQL — `listRows` does not have this 100-row cap.

---

## SELECT Examples

```sql
-- Basic select all columns (100-row cap applies)
SELECT * FROM TableName

-- Specific columns
SELECT name, surname, `phone number` FROM Contacts

-- Count rows
SELECT COUNT(*) FROM Contacts

-- Filter
SELECT name, email FROM Users WHERE active = 1

-- Multiple filters
SELECT * FROM Orders WHERE status = 'pending' AND total > 100

-- Sorting
SELECT * FROM Orders ORDER BY created_at DESC LIMIT 50

-- Pagination
SELECT name, surname FROM Contacts LIMIT 10 OFFSET 30

-- String search
SELECT * FROM Users WHERE name LIKE '%alice%'

-- Date filter (ISO 8601 format)
SELECT * FROM Events WHERE event_date >= '2024-01-01'

-- NULL check
SELECT * FROM Tasks WHERE completed_at IS NULL

-- By internal row ID
SELECT * FROM Contacts WHERE _id = 'CxNAsIEjT8Oojx2I87gqCA'

-- DISTINCT
SELECT DISTINCT surname FROM Contacts ORDER BY surname
```

---

## Quoting Rules

Column names with **spaces or special characters** must be wrapped in backticks:

```sql
-- ✓ Backticks for column names with spaces
SELECT `phone number`, `first name` FROM Contacts

-- ✓ Table names can also use backticks (optional if no spaces)
SELECT _id FROM `Table1`

-- ✓ No backticks needed for simple names
SELECT name, surname FROM Contacts
```

In PHP string interpolation:

```php
// Backticks inside double-quoted strings — escape the $ but not the backtick
$sql = "SELECT name, `phone number` FROM `{$tableName}` LIMIT {$limit} OFFSET {$offset}";
```

---

## Supported Column Types in WHERE

| Column Type | Example |
|---|---|
| Text / Long Text | `WHERE name = 'Alice'` |
| Number | `WHERE age > 30` |
| Checkbox | `WHERE active = 1` (use 1/0, not true/false) |
| Date | `WHERE created_at >= '2024-01-01'` |
| Single Select | `WHERE status = 'Active'` |
| Multi Select | Limited — avoid complex multi-select filters in SQL |
| Email / URL | `WHERE email = 'x@y.com'` |
| Row ID | `WHERE _id = 'abc123'` |

---

## Aggregate Functions

`COUNT(*)` is confirmed to work. Other aggregates (`SUM`, `AVG`, `MIN`, `MAX`) may work
depending on the SeaTable version — test on your instance.

```php
// COUNT(*) — confirmed working
$query = new SqlQuery([
    'sql'          => "SELECT COUNT(*) FROM Table1",
    'convert_keys' => true,
]);
$rows  = $rowsApi->querySQL($uuid, $query)->getResults();
// $rows[0] === ['COUNT(*)' => 103]
// Safe extraction:
$count = (int) array_values((array)$rows[0])[0];
```

---

## Getting Row IDs

The `_id` field is always present and required for updates/deletes:

```php
$response = $rowsApi->querySQL($uuid, new SqlQuery([
    'sql'          => "SELECT _id, name FROM Contacts WHERE surname = 'Smith'",
    'convert_keys' => true,
]));

$rowId = $response->getResults()[0]['_id'];
```

---

## Limitations Summary

| Feature | Supported? |
|---|---|
| `SELECT` with `WHERE`, `ORDER BY`, `LIMIT`, `OFFSET` | ✅ Yes |
| `GROUP BY` | ✅ Yes |
| `COUNT(*)` | ✅ Yes |
| `DISTINCT` | ✅ Yes |
| Column names with spaces (via backticks) | ✅ Yes |
| `_id` system column in queries | ✅ Yes |
| `JOIN` across tables | ❌ No — use `LinksApi` |
| Subqueries | ❌ No |
| Window functions | ❌ No |
| `INSERT` / `UPDATE` / `DELETE` via SQL | ⚠️ Limited — prefer SDK methods |
| Default row cap | ⚠️ 100 rows — always use explicit `LIMIT` |
| `convert_keys` in `listRows` | ❌ No effect — use `querySQL` instead |
