<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client as GuzzleClient;
use SeaTable\Client\Auth\BaseTokenApi;
use SeaTable\Client\Base\AppendRows;
use SeaTable\Client\Base\BaseInfoApi;
use SeaTable\Client\Base\DeleteRows;
use SeaTable\Client\Base\LinksApi;
use SeaTable\Client\Base\RowLinkCreateUpdateDelete;
use SeaTable\Client\Base\RowsApi;
use SeaTable\Client\Base\SqlQuery;
use SeaTable\Client\Base\UpdateRows;
use SeaTable\Client\Base\UpdateRowsUpdatesInner;
use SeaTable\Client\Configuration;
use SeaTable\Client\File\FilesImagesApi;

/**
 * SeaTableClient — reusable wrapper for common SeaTable SDK operations.
 *
 * Copy this file into your project's src/ directory and adjust the namespace.
 *
 * Required .env keys:
 *   SEATABLE_HOST        e.g. https://cloud.seatable.io (or your self-hosted URL)
 *   SEATABLE_API_TOKEN   40-char API token created in the SeaTable UI per base
 *   SEATABLE_BASE_UUID   UUID of the target base
 *
 * Usage:
 *   $client = new SeaTableClient();
 *   $client->connect();
 *   $rowId  = $client->insertRow('MyTable', ['Name' => 'Alice']);
 *   $client->updateRow('MyTable', $rowId, ['Name' => 'Alice Updated']);
 *   $client->deleteRows('MyTable', [$rowId]);
 *   $imageUrl = $client->uploadImage('/path/to/photo.jpg', 'photo.jpg');
 *   $client->patchImageColumn('MyTable', $rowId, 'cover_image', $imageUrl);
 *   $catId  = $client->lookupOrCreate('categories', 'category', 'Fiction');
 *   $client->linkRows($audiobookRowId, $catId, 'Lx9k', 'Ab12', 'Cd34');
 */
class SeaTableClient
{
    private string $host;
    private string $apiToken;
    private string $baseUuid;

    private ?string $baseToken   = null;
    private ?int $workspaceId    = null;
    private ?RowsApi $rowsApi    = null;
    private ?FilesImagesApi $filesApi = null;
    private ?LinksApi $linksApi  = null;

    public function __construct(
        string $apiToken = null,
        string $baseUuid = null,
        string $host     = null,
    ) {
        $this->apiToken = $apiToken ?? $_ENV['SEATABLE_API_TOKEN'];
        $this->baseUuid = $baseUuid ?? $_ENV['SEATABLE_BASE_UUID'];
        $this->host     = rtrim($host ?? $_ENV['SEATABLE_HOST'] ?? 'https://cloud.seatable.io', '/');
    }

    // -------------------------------------------------------------------------
    // Connection
    // -------------------------------------------------------------------------

    /**
     * Exchange the API token for a Base-Token and initialise all API clients.
     * Call this once before any other method.
     */
    public function connect(): void
    {
        $authConfig = (new Configuration())
            ->setAccessToken($this->apiToken)
            ->setHost($this->host);

        $tokenResponse = (new BaseTokenApi(new GuzzleClient(), $authConfig))
            ->getBaseTokenWithApiToken();

        // SDK returns an array (ObjectSerializer behaviour) — guard for both
        $res              = is_array($tokenResponse) ? $tokenResponse : (array) $tokenResponse;
        $this->baseToken  = $res['access_token'];
        $this->workspaceId = (int) $res['workspace_id'];

        $baseConfig = (new Configuration())
            ->setAccessToken($this->baseToken)
            ->setHost($this->host);

        // FilesImagesApi authenticates with the API token, not the base token
        $fileConfig = (new Configuration())
            ->setAccessToken($this->apiToken)
            ->setHost($this->host);

        $this->rowsApi  = new RowsApi(new GuzzleClient(), $baseConfig);
        $this->filesApi = new FilesImagesApi(new GuzzleClient(), $fileConfig);
        $this->linksApi = new LinksApi(new GuzzleClient(), $baseConfig);
    }

    public function getBaseToken(): string
    {
        $this->assertConnected();
        return $this->baseToken;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Run an SQL query and return the results array.
     * Use convert_keys=true for display names, false for internal 4-char keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, bool $convertKeys = true): array
    {
        $this->assertConnected();
        $result = $this->rowsApi->querySQL(
            $this->baseUuid,
            new SqlQuery(['sql' => $sql, 'convert_keys' => $convertKeys]),
        );
        $rows = $result->getResults() ?? [];
        return array_map(fn($r) => is_array($r) ? $r : (array) $r, $rows);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a single row into $table and return the new row's _id.
     *
     * @param array<string, mixed> $data  Column name → value map
     */
    public function insertRow(string $table, array $data): string
    {
        $this->assertConnected();

        $result = $this->rowsApi->appendRows(
            $this->baseUuid,
            new AppendRows(['table_name' => $table, 'rows' => [$data], 'apply_default' => false]),
        );

        // row_ids[0] is a stdClass object — extract _id defensively
        $res = is_array($result) ? $result : (array) $result;
        $raw = $res['row_ids'][0] ?? '';
        return is_array($raw) ? ($raw['_id'] ?? '') : (is_object($raw) ? (string) $raw->_id : (string) $raw);
    }

    /**
     * Update one or more columns on an existing row.
     *
     * ⚠️  Always uses UpdateRows + UpdateRowsUpdatesInner (the plural model).
     *     UpdateRow (singular) silently accepts the request but applies nothing.
     *
     * @param array<string, mixed> $fields  Column name → new value map
     */
    public function updateRow(string $table, string $rowId, array $fields): void
    {
        $this->assertConnected();

        $this->rowsApi->updateRow(
            $this->baseUuid,
            new UpdateRows([
                'table_name' => $table,
                'updates'    => [
                    new UpdateRowsUpdatesInner([
                        'row_id' => $rowId,
                        'row'    => (object) $fields,
                    ]),
                ],
            ]),
        );
    }

    /**
     * Delete one or more rows (batched in groups of 50).
     *
     * ⚠️  Always uses DeleteRows (plural) with row_ids array.
     *     DeleteRow (singular) silently accepts but deletes nothing.
     *
     * @param string[] $rowIds
     */
    public function deleteRows(string $table, array $rowIds): void
    {
        $this->assertConnected();

        foreach (array_chunk($rowIds, 50) as $batch) {
            $this->rowsApi->deleteRow(
                $this->baseUuid,
                new DeleteRows(['table_name' => $table, 'row_ids' => $batch]),
            );
        }
    }

    /**
     * Delete every row in $table. Useful for test resets.
     */
    public function clearTable(string $table): void
    {
        $rows   = $this->query("SELECT _id FROM {$table}", false);
        $rowIds = array_filter(array_column($rows, '_id'));
        if (!empty($rowIds)) {
            $this->deleteRows($table, array_values($rowIds));
        }
    }

    // -------------------------------------------------------------------------
    // Image upload
    // -------------------------------------------------------------------------

    /**
     * Upload a local image file to SeaTable and return the full absolute URL
     * needed to store it in an image column.
     *
     * The returned URL has the form:
     *   https://host/workspace/{workspaceId}/asset/{baseUuid}/images/{year-month}/{filename}
     */
    public function uploadImage(string $localPath, string $fileName): string
    {
        $this->assertConnected();

        if (!file_exists($localPath)) {
            throw new \RuntimeException("Image file not found: {$localPath}");
        }

        // Step 1 — get a signed upload link
        $info = $this->filesApi->getUploadLink();
        $info = is_array($info) ? $info : (array) $info;

        $uploadLink   = $info['upload_link'];
        $parentPath   = $info['parent_path'];
        $relativePath = $info['img_relative_path'];

        // The SDK returns a full URL; extract just the UUID portion it expects
        if (!preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/', $uploadLink, $m)) {
            throw new \RuntimeException("Could not extract UUID from upload link: {$uploadLink}");
        }

        // Step 2 — POST the file
        $uploaded = $this->filesApi->uploadFile(
            $m[1],
            new \SplFileObject($localPath),
            $parentPath,
            $relativePath,
            '1', // replace existing file with same name
        );

        $uploaded     = is_array($uploaded) ? $uploaded : (array) $uploaded;
        $first        = $uploaded[0] ?? [];
        $uploadedName = is_array($first) ? ($first['name'] ?? '') : (string) ($first->name ?? '');

        if (empty($uploadedName)) {
            throw new \RuntimeException("Upload failed: no filename in response");
        }

        // Step 3 — construct the full absolute URL
        return "{$this->host}/workspace/{$this->workspaceId}{$parentPath}/{$relativePath}/{$uploadedName}";
    }

    /**
     * Set an image column on a row to a single uploaded image URL.
     *
     * ⚠️  Image columns require a plain array of full absolute URL strings.
     *     Using [{name, url}] objects stores data but the image will NOT render
     *     in the SeaTable UI. Relative URLs also won't render.
     */
    public function patchImageColumn(string $table, string $rowId, string $column, string $imageUrl): void
    {
        $this->updateRow($table, $rowId, [$column => [$imageUrl]]);
    }

    // -------------------------------------------------------------------------
    // Linked table helpers
    // -------------------------------------------------------------------------

    /**
     * Find a row in $table where $nameColumn = $value, or create it if absent.
     * Returns the row's _id.
     *
     * Typical use: ensure a category exists before linking it to another row.
     */
    public function lookupOrCreate(string $table, string $nameColumn, string $value): string
    {
        $this->assertConnected();

        $rows = $this->query(
            sprintf("SELECT _id FROM %s WHERE %s='%s' LIMIT 1", $table, $nameColumn, addslashes($value)),
            false,
        );

        if (!empty($rows)) {
            return $rows[0]['_id'];
        }

        return $this->insertRow($table, [$nameColumn => $value]);
    }

    /**
     * Create a link between two rows via a "Link to other records" column.
     *
     * All three IDs ($linkId, $sourceTableId, $targetTableId) are 4-character
     * internal identifiers. Discover them once with getMetadata() and hardcode
     * them in .env — they never change.
     *
     * @see getMetadata() to discover IDs
     */
    public function linkRows(
        string $sourceRowId,
        string $targetRowId,
        string $linkId,
        string $sourceTableId,
        string $targetTableId,
    ): void {
        $this->assertConnected();

        $this->linksApi->createRowLink(
            $this->baseUuid,
            new RowLinkCreateUpdateDelete([
                'table_id'           => $sourceTableId,
                'other_table_id'     => $targetTableId,
                'link_id'            => $linkId,
                'other_rows_ids_map' => [$sourceRowId => [$targetRowId]],
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Metadata discovery
    // -------------------------------------------------------------------------

    /**
     * Return all tables with their columns (including internal IDs and link_ids).
     * Run this once per project to discover TABLE_ID_* and LINK_ID_* values,
     * then hardcode them in .env.
     *
     * Typical output per column:
     *   ['key' => 'Ab12', 'type' => 'link', 'name' => 'categories',
     *    'data' => ['link_id' => 'Ef56', 'other_table_id' => 'Cd34', ...]]
     */
    public function getMetadata(): array
    {
        $this->assertConnected();

        $baseConfig = (new Configuration())
            ->setAccessToken($this->baseToken)
            ->setHost($this->host);

        $meta = (new BaseInfoApi(new GuzzleClient(), $baseConfig))->getMetadata($this->baseUuid);
        $meta = is_array($meta) ? $meta : (array) $meta;
        return $meta['tables'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function assertConnected(): void
    {
        if ($this->rowsApi === null) {
            throw new \LogicException('Call connect() before using SeaTableClient.');
        }
    }
}
