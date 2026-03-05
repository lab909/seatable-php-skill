<?php

namespace App\Repositories;

use App\DTOs\ContactDTO;
use SeaTable\Client\Base\RowsApi;
use SeaTable\Client\Base\SqlQuery;
use SeaTable\Client\Base\AppendRows;
use SeaTable\Client\Base\UpdateRows;
use SeaTable\Client\Base\DeleteRows;
use SeaTable\Client\ApiException;

class ContactRepository
{
    private RowsApi $rowsApi;
    private string $baseUuid;

    // Hardcoded table name — never use user input for table names in SQL
    private string $tableName = 'Contacts';

    // SeaTable row IDs are typically 22 alphanumeric chars with hyphens/underscores
    private const ROW_ID_PATTERN = '/^[a-zA-Z0-9_-]{1,50}$/';

    // Allow-list of columns that can be written via create/update
    private const WRITABLE_COLUMNS = ['name', 'surname', 'phone number'];

    /**
     * Dependency Injection ensures the SeaTable client can be mocked during testing.
     */
    public function __construct(RowsApi $rowsApi, string $baseUuid)
    {
        $this->rowsApi  = $rowsApi;
        $this->baseUuid = $baseUuid;
    }

    /**
     * Fetch a paginated list of contacts using SQL.
     *
     * @return ContactDTO[]
     */
    public function getContacts(int $limit = 100, int $offset = 0): array
    {
        // Sanitize pagination values — integers only, with sane bounds
        $limit  = max(1, min(intval($limit), 1000));
        $offset = max(0, intval($offset));

        try {
            // $this->tableName is a hardcoded class constant — safe to interpolate.
            // Never pass user-supplied table names here.
            $sql = sprintf(
                "SELECT _id, name, surname, `phone number` FROM `%s` LIMIT %d OFFSET %d",
                $this->tableName,
                $limit,
                $offset
            );

            $query = new SqlQuery([
                'sql'          => $sql,
                'convert_keys' => true, // CRITICAL: Required to get human-readable column names
            ]);

            // querySQL returns SqlQueryResponse — MUST use getResults() getter
            $response = $this->rowsApi->querySQL($this->baseUuid, $query);
            $rawRows  = $response->getResults() ?? [];

            return array_map(
                fn(array $row) => ContactDTO::fromSeaTableArray($row),
                $rawRows
            );
        } catch (ApiException $e) {
            $this->handleApiException('getContacts', $e);
            return []; // unreachable, but satisfies static analysis
        }
    }

    /**
     * Find a single contact by its SeaTable row ID.
     */
    public function findById(string $id): ?ContactDTO
    {
        // SECURITY: Strict validation before interpolating into SQL.
        // SeaTable row IDs are short alphanumeric strings — reject anything else.
        if (!preg_match(self::ROW_ID_PATTERN, $id)) {
            throw new \InvalidArgumentException("Invalid Contact ID format.");
        }

        try {
            $sql = sprintf(
                "SELECT * FROM `%s` WHERE _id = '%s' LIMIT 1",
                $this->tableName, // hardcoded — safe
                $id               // validated by regex above
            );

            $query = new SqlQuery([
                'sql'          => $sql,
                'convert_keys' => true,
            ]);

            $response = $this->rowsApi->querySQL($this->baseUuid, $query);
            $results  = $response->getResults();

            if (empty($results)) {
                return null;
            }

            return ContactDTO::fromSeaTableArray($results[0]);
        } catch (ApiException $e) {
            $this->handleApiException('findById', $e);
            return null; // unreachable
        }
    }

    /**
     * Search contacts by name using a LIKE query.
     *
     * @return ContactDTO[]
     */
    public function searchByName(string $name, int $limit = 50): array
    {
        // SECURITY: Strip any characters that could break the SQL LIKE clause.
        // Allow only letters, numbers, spaces, hyphens, and apostrophes.
        $safeName = preg_replace('/[^a-zA-Z0-9 \'-]/', '', $name);
        if (empty($safeName) || strlen($safeName) > 200) {
            return [];
        }

        $limit = max(1, min(intval($limit), 500));

        try {
            $sql = sprintf(
                "SELECT _id, name, surname, `phone number` FROM `%s` WHERE name LIKE '%%%s%%' LIMIT %d",
                $this->tableName,
                $safeName,
                $limit
            );

            $query = new SqlQuery([
                'sql'          => $sql,
                'convert_keys' => true,
            ]);

            $response = $this->rowsApi->querySQL($this->baseUuid, $query);
            $rawRows  = $response->getResults() ?? [];

            return array_map(
                fn(array $row) => ContactDTO::fromSeaTableArray($row),
                $rawRows
            );
        } catch (ApiException $e) {
            $this->handleApiException('searchByName', $e);
            return [];
        }
    }

    /**
     * Create a new contact.
     *
     * @param array<string, mixed> $rawData Unvalidated input (e.g. from JSON request body)
     */
    public function create(array $rawData): void
    {
        // SECURITY: Prevent Mass Assignment — only allow listed columns
        $safeData = $this->sanitizeInput($rawData);

        // Validate required fields
        if (empty($safeData['name'])) {
            throw new \InvalidArgumentException("Name is required.");
        }

        try {
            $request = new AppendRows([
                'table_name' => $this->tableName,
                'rows'       => [$safeData],
            ]);

            $this->rowsApi->appendRows($this->baseUuid, $request);
        } catch (ApiException $e) {
            $this->handleApiException('create', $e);
        }
    }

    /**
     * Update an existing contact by row ID.
     *
     * @param string               $id      SeaTable row ID
     * @param array<string, mixed> $rawData Unvalidated input
     */
    public function update(string $id, array $rawData): void
    {
        if (!preg_match(self::ROW_ID_PATTERN, $id)) {
            throw new \InvalidArgumentException("Invalid Contact ID format.");
        }

        // SECURITY: Allow-list columns and strip empty values
        $safeData = array_filter($this->sanitizeInput($rawData), fn($v) => $v !== '');
        if (empty($safeData)) {
            throw new \InvalidArgumentException("No valid fields to update.");
        }

        try {
            $request = new UpdateRows([
                'table_name' => $this->tableName,
                'updates'    => [
                    [
                        'row_id' => $id,
                        'row'    => $safeData,
                    ],
                ],
            ]);

            $this->rowsApi->updateRow($this->baseUuid, $request);
        } catch (ApiException $e) {
            $this->handleApiException('update', $e);
        }
    }

    /**
     * Delete one or more contacts by row ID.
     *
     * @param string[] $ids Array of SeaTable row IDs
     */
    public function delete(array $ids): void
    {
        // Validate every ID before sending the request
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match(self::ROW_ID_PATTERN, $id)) {
                throw new \InvalidArgumentException("Invalid Contact ID format: " . var_export($id, true));
            }
        }

        if (empty($ids)) {
            return;
        }

        try {
            $request = new DeleteRows([
                'table_name' => $this->tableName,
                'row_ids'    => array_values($ids),
            ]);

            $this->rowsApi->deleteRow($this->baseUuid, $request);
        } catch (ApiException $e) {
            $this->handleApiException('delete', $e);
        }
    }

    /**
     * Sanitize raw input against the allow-list of writable columns.
     * Casts all values to string and trims whitespace.
     *
     * @param  array<string, mixed> $rawData
     * @return array<string, string>
     */
    private function sanitizeInput(array $rawData): array
    {
        $safe = [];
        foreach (self::WRITABLE_COLUMNS as $col) {
            if (isset($rawData[$col])) {
                $safe[$col] = trim((string) $rawData[$col]);
            }
        }
        return $safe;
    }

    /**
     * Centralized secure error handling.
     * Logs the detailed SDK error internally, but throws a generic exception outwards.
     *
     * @throws \RuntimeException always
     */
    private function handleApiException(string $context, ApiException $e): never
    {
        $statusCode   = $e->getCode();
        $responseBody = $e->getResponseBody();

        // Log the real error internally (e.g., Monolog, error_log).
        // Be aware $responseBody may contain Base UUIDs or table structure.
        error_log("SeaTable API Error in ContactRepository::{$context} - Status: {$statusCode}, Body: {$responseBody}");

        // Generic safe message for the caller / API consumer
        throw new \RuntimeException("A database error occurred while processing your request.");
    }
}
