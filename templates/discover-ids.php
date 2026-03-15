<?php

/**
 * SeaTable ID Discovery Script
 *
 * Run this once per project to discover all internal table IDs, column keys,
 * and link IDs. Copy the output values into your .env file.
 *
 * Usage:
 *   php discover-ids.php
 *
 * Required .env keys:
 *   SEATABLE_HOST        e.g. https://cloud.seatable.io (or your self-hosted URL)
 *   SEATABLE_API_TOKEN   40-char API token created in the SeaTable UI per base
 *   SEATABLE_BASE_UUID   UUID of the target base
 *
 * Assumes SeaTableClient.php has been copied to src/SeaTableClient.php.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\SeaTableClient;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$client = new SeaTableClient();
$client->connect();

$tables = $client->getMetadata();

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "SeaTable Base Metadata\n";
echo str_repeat('=', 60) . "\n\n";

$envLines = [];

foreach ($tables as $table) {
    $tableName = $table['name'] ?? '?';
    $tableId   = $table['_id']  ?? '?';

    $envKey = 'TABLE_ID_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $tableName));
    $envLines[] = "{$envKey}={$tableId}";

    echo "Table: {$tableName}\n";
    echo "  _id (table ID): {$tableId}\n";

    $columns = $table['columns'] ?? [];
    $linkCols = [];

    foreach ($columns as $col) {
        $colName = $col['name'] ?? '?';
        $colKey  = $col['key']  ?? '?';
        $colType = $col['type'] ?? '?';

        if ($colType === 'link') {
            $linkId        = $col['data']['link_id']        ?? '?';
            $otherTableId  = $col['data']['other_table_id'] ?? '?';

            $linkEnvKey  = 'LINK_ID_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $colName));
            $envLines[]  = "{$linkEnvKey}={$linkId}";

            $linkCols[] = [
                'name'          => $colName,
                'key'           => $colKey,
                'link_id'       => $linkId,
                'other_table_id'=> $otherTableId,
            ];

            echo "  [link] {$colName}\n";
            echo "         column key:     {$colKey}\n";
            echo "         link_id:        {$linkId}         ← LINK_ID_" . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $colName)) . "\n";
            echo "         other_table_id: {$otherTableId}\n";
        } else {
            echo "  [{$colType}] {$colName} (key: {$colKey})\n";
        }
    }

    echo "\n";
}

echo str_repeat('=', 60) . "\n";
echo "Suggested .env entries\n";
echo str_repeat('=', 60) . "\n\n";
foreach ($envLines as $line) {
    echo $line . "\n";
}
echo "\n";
echo "Copy the values above into your .env file, then delete this script.\n\n";
