# SeaTable SQL — Dialect Notes & Limitations

SeaTable supports a SQL-like query language via `RowsApi::querySQL()`. It is **not** full SQL — there are important differences from MySQL/PostgreSQL.

---

## Supported Statements

- `SELECT` — full support with `WHERE`, `ORDER BY`, `LIMIT`, `OFFSET`, `GROUP BY`
- `INSERT` — limited, prefer the `appendRows` SDK method
- `UPDATE` — limited, prefer the `updateRow` SDK method
- `DELETE` — limited, prefer the `deleteRow` SDK method

In practice: **use SQL only for SELECT queries**; use the dedicated SDK methods for writes.

---

## SELECT Examples

```sql
-- Basic select
SELECT * FROM TableName

-- Filter
SELECT Name, Email FROM Users WHERE Active = 1

-- Order and limit
SELECT * FROM Orders ORDER BY CreatedAt DESC LIMIT 50

-- Offset pagination
SELECT * FROM Orders LIMIT 20 OFFSET 40

-- String match
SELECT * FROM Users WHERE Name LIKE '%alice%'

-- Date filter (ISO 8601 format)
SELECT * FROM Events WHERE Date >= '2024-01-01'

-- NULL check
SELECT * FROM Tasks WHERE CompletedAt IS NULL
```

---

## `convert_keys` Parameter

```php
$query = new SeaTable\Client\Base\SqlQuery([
    'sql'          => "SELECT * FROM Users",
    'convert_keys' => true,   // ← IMPORTANT
]);
```

- `convert_keys: true` → result keys are column **names** (e.g. `"Name"`, `"Email"`)
- `convert_keys: false` → result keys are internal column IDs (e.g. `"0000"`, `"XyZa"`)

**Always use `true`** unless you specifically need internal keys.

---

## Supported Column Types in WHERE

| Column Type | Example |
|---|---|
| Text / Long Text | `WHERE Name = 'Alice'` |
| Number | `WHERE Age > 30` |
| Checkbox | `WHERE Active = 1` (use 1/0) |
| Date | `WHERE CreatedAt >= '2024-01-01'` |
| Single Select | `WHERE Status = 'Active'` |
| Multi Select | Limited support — avoid complex multi-select filters in SQL |
| Email / URL | `WHERE Email = 'x@y.com'` |

---

## Limitations

- **No JOINs** across tables — use SeaTable Links + `LinksApi` for relationships
- **No subqueries**
- **No aggregate functions** like `COUNT()`, `SUM()` in all contexts — check your SeaTable version
- Column names with **spaces or special characters** must be quoted with backticks: `` `My Column` ``
- The `_id` field (row ID) is always returned and can be used in WHERE: `WHERE _id = 'abc123'`
- `DISTINCT` is supported
- Maximum rows returned per query: depends on SeaTable instance config (default varies)

---

## Getting Row IDs

The `_id` field is always present in results and is required for updates/deletes:

```php
$result = $rowsApi->querySQL($base_uuid, new SqlQuery([
    'sql' => "SELECT _id, Name FROM Users WHERE Email = 'alice@example.com'",
    'convert_keys' => true,
]));

$rowId = $result['results'][0]['_id'];
```
