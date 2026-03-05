<?php
/**
 * setup.php — SeaTable Connection Validator
 *
 * Run this script after configuring your .env to verify that all required
 * environment variables are present and that SeaTable is reachable.
 *
 * Usage:
 *   php setup.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use SeaTable\Client\Auth\BaseTokenApi;
use SeaTable\Client\Configuration;
use GuzzleHttp\Client as HttpClient;

echo "SeaTable Connection Validator\n";
echo str_repeat('=', 40) . "\n\n";

// ── Step 1: Load .env ──────────────────────────────────────────────
echo "[1/4] Loading .env file... ";
try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    echo "OK\n";
} catch (\Exception $e) {
    echo "FAILED\n";
    echo "  → Could not load .env: " . $e->getMessage() . "\n";
    echo "  → Copy .env.example to .env and fill in your values.\n";
    exit(1);
}

// ── Step 2: Check required variables ───────────────────────────────
echo "[2/4] Checking required environment variables...\n";
$errors = [];

$serverUrl = $_ENV['SEATABLE_SERVER_URL'] ?? '';
$apiToken  = $_ENV['SEATABLE_API_TOKEN'] ?? '';

if (empty($serverUrl)) {
    $errors[] = 'SEATABLE_SERVER_URL is missing or empty';
} else {
    echo "  SEATABLE_SERVER_URL = {$serverUrl} ✓\n";
    // Warn about trailing slash
    if (str_ends_with($serverUrl, '/')) {
        echo "  ⚠ Warning: Remove the trailing slash from SEATABLE_SERVER_URL\n";
    }
}

if (empty($apiToken)) {
    $errors[] = 'SEATABLE_API_TOKEN is missing or empty';
} else {
    // Show only the first/last 4 chars for security
    $masked = substr($apiToken, 0, 4) . str_repeat('*', max(0, strlen($apiToken) - 8)) . substr($apiToken, -4);
    echo "  SEATABLE_API_TOKEN  = {$masked} ✓\n";
}

if (!empty($errors)) {
    echo "\n  FAILED — Missing variables:\n";
    foreach ($errors as $err) {
        echo "  → {$err}\n";
    }
    echo "\n  Where to find these values:\n";
    echo "  → SEATABLE_SERVER_URL: Your SeaTable instance URL (e.g. https://cloud.seatable.io)\n";
    echo "  → SEATABLE_API_TOKEN:  SeaTable UI → Open your Base → ··· menu → API Token → Add Token\n";
    exit(1);
}

// ── Step 3: Test HTTP connectivity ─────────────────────────────────
echo "[3/4] Testing HTTP connectivity to {$serverUrl}... ";
try {
    $httpClient = new HttpClient(['timeout' => 10]);
    $response   = $httpClient->get(rtrim($serverUrl, '/') . '/api/v2.1/ping/');
    $statusCode = $response->getStatusCode();
    if ($statusCode === 200) {
        echo "OK (HTTP {$statusCode})\n";
    } else {
        echo "WARNING (HTTP {$statusCode})\n";
    }
} catch (\Exception $e) {
    echo "FAILED\n";
    echo "  → Could not reach server: " . $e->getMessage() . "\n";
    echo "  → Check SEATABLE_SERVER_URL and network/firewall settings.\n";
    exit(1);
}

// ── Step 4: Exchange API-Token for Base-Token ──────────────────────
echo "[4/4] Exchanging API-Token for Base-Token... ";
try {
    $config = Configuration::getDefaultConfiguration();
    $config->setAccessToken($apiToken);
    $config->setHost($serverUrl);

    $authApi = new BaseTokenApi(new HttpClient(), $config);
    $result  = $authApi->getBaseTokenWithApiToken();

    $baseUuid  = $result['dtable_uuid'] ?? '(unknown)';
    $baseName  = $result['dtable_name'] ?? '(unknown)';
    $hasToken  = !empty($result['access_token']);

    if ($hasToken) {
        echo "OK\n";
        echo "  Base name: {$baseName}\n";
        echo "  Base UUID: {$baseUuid}\n";
    } else {
        echo "FAILED — No access_token in response\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "FAILED\n";
    echo "  → " . $e->getMessage() . "\n";
    echo "  → Check that your SEATABLE_API_TOKEN is valid and has not been revoked.\n";
    exit(1);
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "All checks passed. Your SeaTable connection is working.\n";
echo "You can now start building your API.\n";
