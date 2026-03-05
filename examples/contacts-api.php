<?php
/**
 * api/contacts.php — Example JSON API endpoint
 *
 * Demonstrates the correct pattern for wiring a SeaTable Repository
 * into a request handler with input validation and safe error responses.
 *
 * Routes handled (via simple path/method matching):
 *   GET    /api/contacts.php              → list contacts
 *   GET    /api/contacts.php?id=XXX       → get single contact
 *   GET    /api/contacts.php?search=alice  → search by name
 *   POST   /api/contacts.php              → create contact
 *   PUT    /api/contacts.php?id=XXX       → update contact
 *   DELETE /api/contacts.php?id=XXX       → delete contact
 */

require_once __DIR__ . '/../src/SeaTableClient.php';
require_once __DIR__ . '/../src/DTOs/ContactDTO.php';
require_once __DIR__ . '/../src/Repositories/ContactRepository.php';

use App\Repositories\ContactRepository;

header('Content-Type: application/json; charset=utf-8');

// ── Bootstrap ──────────────────────────────────────────────────────
try {
    $auth    = getAuth();
    $rowsApi = getRowsApi($auth);
    $repo    = new ContactRepository($rowsApi, $auth['base_uuid']);
} catch (\Exception $e) {
    // SECURITY: Never expose internal error details to the client
    error_log("Bootstrap failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Service temporarily unavailable.']);
    exit;
}

// ── Routing ────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($repo);
            break;

        case 'POST':
            handlePost($repo);
            break;

        case 'PUT':
            handlePut($repo);
            break;

        case 'DELETE':
            handleDelete($repo);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed.']);
    }
} catch (\InvalidArgumentException $e) {
    // Validation errors are safe to return to the client
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    // RuntimeException from Repository = generic DB error (already sanitized)
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    // Unexpected errors — log details, return generic message
    error_log("Unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
}

// ── Handlers ───────────────────────────────────────────────────────

function handleGet(ContactRepository $repo): void
{
    // Single contact by ID
    if (!empty($_GET['id'])) {
        $contact = $repo->findById($_GET['id']);
        if ($contact === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Contact not found.']);
            return;
        }
        echo json_encode(['data' => $contact->toArray()]);
        return;
    }

    // Search by name
    if (!empty($_GET['search'])) {
        $results = $repo->searchByName($_GET['search']);
        echo json_encode([
            'data'  => array_map(fn($c) => $c->toArray(), $results),
            'count' => count($results),
        ]);
        return;
    }

    // List with pagination
    $limit  = (int) ($_GET['limit'] ?? 100);
    $offset = (int) ($_GET['offset'] ?? 0);
    $contacts = $repo->getContacts($limit, $offset);

    echo json_encode([
        'data'   => array_map(fn($c) => $c->toArray(), $contacts),
        'count'  => count($contacts),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}

function handlePost(ContactRepository $repo): void
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        return;
    }

    $repo->create($input);

    http_response_code(201);
    echo json_encode(['message' => 'Contact created.']);
}

function handlePut(ContactRepository $repo): void
{
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id parameter.']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        return;
    }

    $repo->update($id, $input);

    echo json_encode(['message' => 'Contact updated.']);
}

function handleDelete(ContactRepository $repo): void
{
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id parameter.']);
        return;
    }

    $repo->delete([$id]);

    echo json_encode(['message' => 'Contact deleted.']);
}
