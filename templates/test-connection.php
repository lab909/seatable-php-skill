<?php

/**
 * SeaTable Connection Test
 *
 * Verifies that your .env credentials are correct and the base is reachable.
 * Run this before starting any feature implementation.
 *
 * Usage:
 *   php test-connection.php
 *
 * Expected output on success:
 *   [OK] Connected to SeaTable
 *   [OK] Base reachable — 3 table(s) found
 *        - audiobooks  (42 rows)
 *        - categories  (8 rows)
 *        - authors     (15 rows)
 *   [OK] All checks passed — ready to build!
 *
 * Assumes SeaTableClient.php has been copied to src/SeaTableClient.php.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\SeaTableClient;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pass  = "\033[0;32m[OK]\033[0m";
$fail  = "\033[0;31m[FAIL]\033[0m";
$warn  = "\033[0;33m[WARN]\033[0m";
$errors = 0;

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "SeaTable Connection Test\n";
echo str_repeat('─', 50) . "\n\n";

// ── Check required .env keys ────────────────────────────────
$required = ['SEATABLE_HOST', 'SEATABLE_API_TOKEN', 'SEATABLE_BASE_UUID'];
foreach ($required as $key) {
    if (empty($_ENV[$key])) {
        echo "{$fail} Missing .env key: {$key}\n";
        $errors++;
    }
}
if ($errors > 0) {
    echo "\nFill in the missing .env keys and re-run.\n\n";
    exit(1);
}
echo "{$pass} All required .env keys present\n";
echo "       Host:      {$_ENV['SEATABLE_HOST']}\n";
echo "       Base UUID: {$_ENV['SEATABLE_BASE_UUID']}\n\n";

// ── Attempt connection ──────────────────────────────────────
$client = new SeaTableClient();
try {
    $client->connect();
    echo "{$pass} Connected to SeaTable (token exchanged successfully)\n\n";
} catch (\Throwable $e) {
    echo "{$fail} Connection failed: " . $e->getMessage() . "\n";
    echo "       Check SEATABLE_HOST and SEATABLE_API_TOKEN in .env\n\n";
    exit(1);
}

// ── Fetch metadata and list tables ─────────────────────────
try {
    $tables = $client->getMetadata();
    $count  = count($tables);
    echo "{$pass} Base reachable — {$count} table(s) found\n";

    foreach ($tables as $table) {
        $name = $table['name'] ?? '?';
        try {
            $rows    = $client->query("SELECT _id FROM `{$name}` LIMIT 1", false);
            // Count rows via SQL COUNT
            $countResult = $client->query("SELECT COUNT(*) as total FROM `{$name}`", true);
            $total = $countResult[0]['total'] ?? $countResult[0]['COUNT(*)'] ?? '?';
            echo "       - {$name}  ({$total} rows)\n";
        } catch (\Throwable $e) {
            echo "       - {$name}  {$warn} could not count rows: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
} catch (\Throwable $e) {
    echo "{$fail} Could not fetch base metadata: " . $e->getMessage() . "\n";
    echo "       Check SEATABLE_BASE_UUID in .env\n\n";
    exit(1);
}

// ── Summary ────────────────────────────────────────────────
echo str_repeat('─', 50) . "\n";
echo "{$pass} All checks passed — ready to build!\n";
echo str_repeat('─', 50) . "\n\n";
echo "Next step: run php discover-ids.php to get table/link IDs.\n\n";
