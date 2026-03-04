<?php

namespace App\Repositories;

use App\DTOs\ContactDTO;
use SeaTable\Client\Base\RowsApi;
use SeaTable\Client\Base\SqlQuery;
use SeaTable\Client\Base\AppendRows;
use SeaTable\Client\ApiException;

class ContactRepository
{
    private RowsApi $rowsApi;
    private string $baseUuid;
    private string $tableName = 'Contacts';

    /**
     * Dependency Injection ensures the SeaTable client can be mocked during testing.
     */
    public function __construct(RowsApi $rowsApi, string $baseUuid)
    {
        $this->rowsApi = $rowsApi;
        $this->baseUuid = $baseUuid;
    }

    /**
     * Fetch a paginated list of contacts using SQL.
     * * @return ContactDTO[]
     */
    public function getContacts(int $limit = 100, int $offset = 0): array
    {
        try {
            // Note the use of backticks for columns with spaces
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

            // SeaTable's querySQL returns an object, we MUST use getResults()
            $response = $this->rowsApi->querySQL($this->baseUuid, $query);
            $rawRows = $response->getResults() ?? [];

            // Map the raw associative arrays to DTOs
            return array_map(function (array $row) {
                return ContactDTO::fromSeaTableArray($row);
            }, $rawRows);

        } catch (ApiException $e) {
            $this->handleApiException('getContacts', $e);
        }
    }

    /**
     * Find a single contact safely.
     */
    public function findById(string $id): ?ContactDTO
    {
        // SECURITY: Strict validation before concatenating into the SQL string
        // SeaTable IDs are typically alphanumeric with hyphens/underscores
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new \InvalidArgumentException("Invalid Contact ID format.");
        }

        try {
            $sql = sprintf(
                "SELECT * FROM `%s` WHERE _id = '%s' LIMIT 1",
                $this->tableName,
                $id // Safe to interpolate because of the regex check above
            );

            $query = new SqlQuery([
                'sql'          => $sql,
                'convert_keys' => true,
            ]);

            $response = $this->rowsApi->querySQL($this->baseUuid, $query);
            $results = $response->getResults();

            if (empty($results)) {
                return null;
            }

            return ContactDTO::fromSeaTableArray($results[0]);

        } catch (ApiException $e) {
            $this->handleApiException('findById', $e);
        }
    }

    /**
     * Create a new contact.
     */
    public function create(array $rawData): void
    {
        // SECURITY: Prevent Mass Assignment by strictly allowing only expected fields
        $safeData = [
            'name'         => $rawData['name'] ?? '',
            'surname'      => $rawData['surname'] ?? '',
            'phone number' => $rawData['phone number'] ?? '',
        ];

        // Ensure required fields aren't completely empty before hitting the API
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
     * Centralized secure error handling.
     * Logs the detailed SDK error internally, but throws a generic exception outwards.
     */
    private function handleApiException(string $context, ApiException $e): void
    {
        $statusCode = $e->getCode();
        $responseBody = $e->getResponseBody();

        // Log the actual SeaTable failure internally (e.g., using Monolog or error_log)
        error_log("SeaTable API Error in ContactRepository::{$context} - Status: {$statusCode}, Body: {$responseBody}");

        // Throw a generic, safe exception to the frontend/consumer
        throw new \RuntimeException("A database error occurred while processing your request.");
    }
}