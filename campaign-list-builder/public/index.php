<?php
/**
 * FlowMonk - Listmonk Automation Suite
 *
 * Adds drip sequences, segmentation, and DOI dunning to Listmonk.
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Include classes
require_once __DIR__ . '/../src/Listmonk.php';
require_once __DIR__ . '/../src/QueryBuilder.php';
require_once __DIR__ . '/../src/Presets.php';
require_once __DIR__ . '/../shared/SequenceDatabase.php';

// Health check endpoint (no auth required)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/health') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'healthy',
        'service' => 'flowmonk',
        'timestamp' => date('c')
    ]);
    exit;
}

// Legacy domain redirect: verify.workflow.fw9.uk -> webhook handler
// Allows old plugins to continue working while they update to new endpoint
$host = $_SERVER['HTTP_HOST'] ?? '';
if (strpos($host, 'verify.workflow') !== false && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../src/WebhookHandler.php';

    $dryRun = filter_var(getenv('WEBHOOK_DRY_RUN'), FILTER_VALIDATE_BOOLEAN);
    $seqDb = new SequenceDatabase();
    $listmonk = new Listmonk();
    $handler = new WebhookHandler($seqDb, $listmonk, $dryRun);
    $handler->handle();
    exit;
}

// Webhook endpoint (no auth required - uses HMAC verification)
// Part of FA-4 epic: Consolidate Freemius webhook processing
$webhookPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($webhookPath === '/api/webhook/product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../src/WebhookHandler.php';

    $dryRun = filter_var(getenv('WEBHOOK_DRY_RUN'), FILTER_VALIDATE_BOOLEAN);
    $seqDb = new SequenceDatabase();
    $listmonk = new Listmonk();
    $handler = new WebhookHandler($seqDb, $listmonk, $dryRun);
    $handler->handle();
    exit;
}

// Basic auth check with timing-safe comparison
$appUser = getenv('APP_USER') ?: '';
$appPass = getenv('APP_PASS') ?: '';

if (!empty($appUser) && !empty($appPass)) {
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    // Use hash_equals for timing-safe comparison to prevent timing attacks
    $userValid = hash_equals($appUser, $providedUser);
    $passValid = hash_equals($appPass, $providedPass);

    if (!$userValid || !$passValid) {
        header('WWW-Authenticate: Basic realm="FlowMonk"');
        http_response_code(401);
        echo 'Authentication required';
        exit;
    }
}

// Parse the request
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Route the request
try {
    // API endpoints
    if (str_starts_with($uri, '/api/')) {
        header('Content-Type: application/json');
        handleApi($uri, $method);
        exit;
    }

    // Page routes
    switch ($uri) {
        case '/':
        case '/index.php':
            renderPage('home');
            break;

        case '/manage':
        case '/manage-lists':
            renderPage('manage-lists');
            break;

        case '/drip':
        case '/drip-sequences':
            renderPage('drip-sequences');
            break;

        case '/queue':
        case '/drip-queue':
            renderPage('drip-queue');
            break;

        case '/stats':
        case '/drip-stats':
            renderPage('drip-stats');
            break;

        case '/audit':
        case '/subscriber-audit':
            renderPage('subscriber-audit');
            break;

        default:
            http_response_code(404);
            echo 'Page not found';
    }
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    if (str_starts_with($uri, '/api/')) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo 'An error occurred: ' . htmlspecialchars($e->getMessage());
    }
}

/**
 * Handle API requests
 */
function handleApi(string $uri, string $method): void
{
    $listmonk = new Listmonk();
    $presets = new Presets();

    // Remove /api/ prefix
    $endpoint = substr($uri, 5);

    switch (true) {
        // Preview subscribers
        case $endpoint === 'preview' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $query = $input['query'] ?? '';
            $page = (int)($input['page'] ?? 1);
            $perPage = (int)($input['per_page'] ?? 50);

            $result = $listmonk->getSubscribers($query, $page, $perPage);
            echo json_encode($result);
            break;

        // Get campaign lists
        case $endpoint === 'lists' && $method === 'GET':
            $lists = $listmonk->getCampaignLists();
            echo json_encode(['data' => $lists]);
            break;

        // Create new list
        case $endpoint === 'create-list' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $query = $input['query'] ?? '';

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'List name is required']);
                break;
            }

            // Create the list
            $listResult = $listmonk->createList($name);
            $listId = $listResult['data']['id'] ?? null;

            if (!$listId) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create list']);
                break;
            }

            // Get subscriber IDs and add them
            $subscriberIds = $listmonk->getAllSubscriberIds($query);
            if (!empty($subscriberIds)) {
                $listmonk->addSubscribersToList($subscriberIds, $listId);
            }

            echo json_encode([
                'success' => true,
                'list_id' => $listId,
                'list_name' => $name,
                'subscriber_count' => count($subscriberIds)
            ]);
            break;

        // Update existing list
        case $endpoint === 'update-list' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $listId = (int)($input['list_id'] ?? 0);
            $query = $input['query'] ?? '';
            $mode = $input['mode'] ?? 'replace'; // 'replace' or 'add'

            if (!$listId) {
                http_response_code(400);
                echo json_encode(['error' => 'List ID is required']);
                break;
            }

            // Get subscriber IDs from query
            $subscriberIds = $listmonk->getAllSubscriberIds($query);

            if ($mode === 'replace') {
                $listmonk->replaceListSubscribers($listId, $subscriberIds);
            } else {
                $listmonk->addSubscribersToList($subscriberIds, $listId);
            }

            echo json_encode([
                'success' => true,
                'list_id' => $listId,
                'mode' => $mode,
                'subscriber_count' => count($subscriberIds)
            ]);
            break;

        // Delete list
        case preg_match('#^lists/(\d+)$#', $endpoint, $matches) && $method === 'DELETE':
            $listId = (int)$matches[1];
            $listmonk->deleteList($listId);
            echo json_encode(['success' => true]);
            break;

        // Get presets
        case $endpoint === 'presets' && $method === 'GET':
            echo json_encode(['data' => $presets->getAll()]);
            break;

        // Save preset
        case $endpoint === 'presets' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $query = $input['query'] ?? '';
            $conditions = $input['conditions'] ?? [];

            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Preset name is required']);
                break;
            }

            $preset = $presets->save_preset($name, $query, $conditions);
            echo json_encode(['success' => true, 'preset' => $preset]);
            break;

        // Delete preset
        case preg_match('#^presets/([a-z0-9-]+)$#', $endpoint, $matches) && $method === 'DELETE':
            $presetId = $matches[1];
            $success = $presets->delete($presetId);
            echo json_encode(['success' => $success]);
            break;

        // Get attributes and operators (for query builder)
        case $endpoint === 'query-config' && $method === 'GET':
            echo json_encode([
                'attributes' => QueryBuilder::getAttributes(),
                'operators' => QueryBuilder::getOperators()
            ]);
            break;

        // =====================================================================
        // Drip Sequence API Endpoints
        // =====================================================================

        // Get all products
        case $endpoint === 'drip/products' && $method === 'GET':
            $seqDb = new SequenceDatabase();
            echo json_encode(['data' => $seqDb->getProducts()]);
            break;

        // Add/update product
        case $endpoint === 'drip/products' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? '';
            $name = $input['name'] ?? '';
            $listId = !empty($input['list_id']) ? (int)$input['list_id'] : null;
            $enabled = $input['enabled'] ?? true;
            $type = $input['type'] ?? 'freemius';
            $hmacSecret = $input['hmac_secret'] ?? null;

            if (empty($id) || empty($name)) {
                http_response_code(400);
                echo json_encode(['error' => 'Product ID and name are required']);
                break;
            }

            // Validate type
            if (!in_array($type, ['freemius', 'freelib', 'other'])) {
                $type = 'freemius';
            }

            $seqDb = new SequenceDatabase();
            $seqDb->saveProductFull($id, $name, $enabled, $listId, $type, $hmacSecret);
            echo json_encode(['success' => true]);
            break;

        // Delete product
        case preg_match('#^drip/products/([a-zA-Z0-9_-]+)$#', $endpoint, $matches) && $method === 'DELETE':
            $productId = $matches[1];
            $seqDb = new SequenceDatabase();
            $success = $seqDb->deleteProduct($productId);
            echo json_encode(['success' => $success]);
            break;

        // Get sequences (optionally filtered by product)
        // Note: Query param 'plugin_id' kept for backward compat, accepts product ID
        case $endpoint === 'drip/sequences' && $method === 'GET':
            $productId = $_GET['plugin_id'] ?? $_GET['product_id'] ?? null;
            $seqDb = new SequenceDatabase();
            if ($productId) {
                $data = $seqDb->getSequencesByType($productId);
            } else {
                $data = $seqDb->getSequences();
            }
            echo json_encode(['data' => $data]);
            break;

        // Get sequences for a specific product
        case preg_match('#^drip/sequences/([a-zA-Z0-9_-]+)$#', $endpoint, $matches) && $method === 'GET':
            $productId = $matches[1];
            $seqDb = new SequenceDatabase();
            $data = $seqDb->getSequencesByType($productId);
            echo json_encode(['data' => $data]);
            break;

        // Add/update sequence step
        case $endpoint === 'drip/sequences' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);

            $required = ['plugin_id', 'type', 'stage', 'template_id', 'subject'];
            foreach ($required as $field) {
                if (empty($input[$field]) && $input[$field] !== 0) {
                    http_response_code(400);
                    echo json_encode(['error' => "Field '$field' is required"]);
                    return;
                }
            }

            $seqDb = new SequenceDatabase();

            // Auto-assign step_order if not provided
            if (empty($input['step_order'])) {
                $input['step_order'] = $seqDb->getNextStepOrder($input['plugin_id'], $input['type']);
            }

            $input['delay_days'] = (int)($input['delay_days'] ?? 0);
            $input['enabled'] = $input['enabled'] ?? 1;

            $id = $seqDb->saveSequenceStep($input);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // Delete sequence step
        case preg_match('#^drip/sequences/(\d+)$#', $endpoint, $matches) && $method === 'DELETE':
            $stepId = (int)$matches[1];
            $seqDb = new SequenceDatabase();
            $success = $seqDb->deleteSequenceStep($stepId);
            echo json_encode(['success' => $success]);
            break;

        // =====================================================================
        // FA-12: Sequence Types API Endpoints (Dynamic sequence types per product)
        // =====================================================================

        // Get sequence types for a product
        case preg_match('#^drip/sequence-types/([a-zA-Z0-9_-]+)$#', $endpoint, $matches) && $method === 'GET':
            $productId = $matches[1];
            $seqDb = new SequenceDatabase();
            $types = $seqDb->getSequenceTypes($productId);
            echo json_encode(['data' => $types]);
            break;

        // Add a new sequence type
        case $endpoint === 'drip/sequence-types' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $productId = $input['product_id'] ?? '';
            $typeName = $input['type_name'] ?? '';
            $displayName = $input['display_name'] ?? '';
            $sortOrder = (int)($input['sort_order'] ?? 0);

            if (empty($productId) || empty($typeName) || empty($displayName)) {
                http_response_code(400);
                echo json_encode(['error' => 'product_id, type_name, and display_name are required']);
                break;
            }

            // Normalize type_name to lowercase, no spaces
            $typeName = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $typeName));

            $seqDb = new SequenceDatabase();

            // Auto-assign sort_order if not provided
            if ($sortOrder === 0) {
                $sortOrder = $seqDb->getNextSequenceTypeSortOrder($productId);
            }

            try {
                $id = $seqDb->addSequenceType($productId, $typeName, $displayName, $sortOrder);
                echo json_encode(['success' => true, 'id' => $id]);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    http_response_code(409);
                    echo json_encode(['error' => "Sequence type '$typeName' already exists for this product"]);
                } else {
                    throw $e;
                }
            }
            break;

        // Update a sequence type
        case preg_match('#^drip/sequence-types/(\d+)$#', $endpoint, $matches) && $method === 'PUT':
            $typeId = (int)$matches[1];
            $input = json_decode(file_get_contents('php://input'), true);
            $displayName = $input['display_name'] ?? '';
            $sortOrder = (int)($input['sort_order'] ?? 0);

            if (empty($displayName)) {
                http_response_code(400);
                echo json_encode(['error' => 'display_name is required']);
                break;
            }

            $seqDb = new SequenceDatabase();
            $success = $seqDb->updateSequenceType($typeId, $displayName, $sortOrder);
            echo json_encode(['success' => $success]);
            break;

        // Delete a sequence type (and its sequences)
        case preg_match('#^drip/sequence-types/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)$#', $endpoint, $matches) && $method === 'DELETE':
            $productId = $matches[1];
            $typeName = $matches[2];
            $seqDb = new SequenceDatabase();
            $success = $seqDb->deleteSequenceType($productId, $typeName);
            echo json_encode(['success' => $success]);
            break;

        // Reorder sequence steps
        // Note: 'plugin_id' in payload kept for backward compat with sequences table
        case $endpoint === 'drip/sequences/reorder' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $productId = $input['plugin_id'] ?? $input['product_id'] ?? '';
            $type = $input['type'] ?? '';
            $stepIds = $input['step_ids'] ?? [];

            if (empty($productId) || empty($type) || empty($stepIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'product_id, type, and step_ids are required']);
                break;
            }

            $seqDb = new SequenceDatabase();
            $success = $seqDb->reorderSteps($productId, $type, $stepIds);
            echo json_encode(['success' => $success]);
            break;

        // =====================================================================
        // Drip Queue Monitoring API Endpoints
        // =====================================================================

        // Get queue stats (FA-16: from SQLite)
        case $endpoint === 'drip/queue/stats' && $method === 'GET':
            $seqDb = new SequenceDatabase();
            $stats = $seqDb->getQueueStats();
            echo json_encode(['data' => $stats]);
            break;

        // Get detailed stats for dashboard (FA-16: from SQLite)
        case $endpoint === 'drip/stats/detailed' && $method === 'GET':
            $seqDb = new SequenceDatabase();
            $products = $seqDb->getProducts();

            // Get detailed stats from SQLite
            $sqliteStats = $seqDb->getDetailedStats();

            $response = [
                'summary' => [
                    'total_active' => $sqliteStats['summary']['total_active'] ?? 0,
                    'completed_all_time' => $sqliteStats['summary']['completed_all_time'] ?? 0,
                    'completed_this_week' => $sqliteStats['summary']['completed_this_week'] ?? 0,
                    'completed_this_month' => $sqliteStats['summary']['completed_this_month'] ?? 0,
                    'errors' => $sqliteStats['summary']['errors'] ?? 0,
                    'blocked' => 0,
                    'blocklisted' => 0,
                    'disabled' => 0,
                    'unconfirmed_waiting' => 0
                ],
                'by_product' => [],
                'time_comparison' => [
                    'this_week' => ['completed' => $sqliteStats['summary']['completed_this_week'] ?? 0],
                    'last_week' => ['completed' => $sqliteStats['summary']['completed_last_week'] ?? 0]
                ]
            ];

            // Copy product data from SQLite stats
            foreach ($products as $product) {
                $productId = $product['id'];
                $sqliteProduct = $sqliteStats['by_product'][$productId] ?? null;

                $response['by_product'][$productId] = [
                    'name' => $product['name'],
                    'funnel' => $sqliteProduct['funnel'] ?? [],
                    'total_active' => $sqliteProduct['total_active'] ?? 0,
                    'this_week' => $sqliteProduct['this_week'] ?? ['completed' => 0],
                    'this_month' => $sqliteProduct['this_month'] ?? ['completed' => 0]
                ];
            }

            // Get list subscription stats for double opt-in lists (still from Listmonk)
            $productListIds = $seqDb->getProductListIds();
            $listStats = [];

            // Clean up old stats occasionally (1% chance per request)
            if (rand(1, 100) === 1) {
                $seqDb->cleanupOldStats();
            }

            foreach ($productListIds as $productId => $listId) {
                $list = $listmonk->getList($listId);
                if (!$list) continue;

                // Use subscriber_statuses values directly (subscriber_count is unreliable)
                $statuses = $list['subscriber_statuses'] ?? [];
                $statusConfirmed = $statuses['confirmed'] ?? 0;
                $statusUnconfirmed = $statuses['unconfirmed'] ?? 0;
                $unsubscribed = $statuses['unsubscribed'] ?? 0;
                $isDoubleOptIn = ($list['optin'] ?? 'single') === 'double';

                // Total = sum of statuses (matches listmonk UI)
                $subscriberCount = $statusConfirmed + $statusUnconfirmed + $unsubscribed;

                if ($isDoubleOptIn) {
                    // Double opt-in: use actual confirmed/unconfirmed counts
                    $confirmed = $statusConfirmed;
                    $unconfirmed = $statusUnconfirmed;
                } else {
                    // Single opt-in: all active subscribers are effectively confirmed
                    $confirmed = $statusConfirmed + $statusUnconfirmed;
                    $unconfirmed = 0;
                }

                // Record today's snapshot (will update if already exists)
                $seqDb->recordListStats($listId, $subscriberCount, $confirmed, $unconfirmed, $unsubscribed);

                // Calculate deltas
                $deltas = $seqDb->getListStatsDelta($listId, $confirmed);

                $listStats[$listId] = [
                    'name' => $list['name'] ?? "List $listId",
                    'optin' => $list['optin'] ?? 'single',
                    'product_id' => $productId,
                    'product_name' => $response['by_product'][$productId]['name'] ?? $productId,
                    'total' => $subscriberCount,
                    'confirmed' => $confirmed,
                    'unconfirmed' => $unconfirmed,
                    'unsubscribed' => $unsubscribed,
                    'delta_week' => $deltas['week'],
                    'delta_month' => $deltas['month']
                ];
            }

            $response['list_stats'] = $listStats;

            // Get dunning stats from SQLite
            $response['dunning'] = $sqliteStats['dunning'] ?? [
                'total_in_dunning' => 0,
                'by_stage' => []
            ];

            echo json_encode(['data' => $response]);
            break;

        // Get queue subscribers (FA-16: from SQLite)
        case $endpoint === 'drip/queue' && $method === 'GET':
            $productId = $_GET['product_id'] ?? $_GET['plugin_id'] ?? null; // Accept both for backward compat
            $status = $_GET['status'] ?? 'active'; // 'due', 'error', 'active', 'completed'
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 50))); // Cap at 500

            // Validate status to prevent injection
            $validStatuses = ['due', 'error', 'active', 'completed'];
            if (!in_array($status, $validStatuses)) {
                $status = 'active';
            }

            $seqDb = new SequenceDatabase();

            // Get queue items from SQLite
            $result = $seqDb->getQueueItems($productId, $status, $page, $perPage);
            $items = $result['data'] ?? [];

            // Cache list optin types and subscriber list statuses
            $listOptinCache = [];
            $productListIds = $seqDb->getProductListIds();

            // Group items by subscriber to get their list subscription status from Listmonk
            $listmonkIds = array_unique(array_filter(array_column($items, 'listmonk_id')));
            $subscriberListStatus = [];

            // Fetch list subscription status for each subscriber from Listmonk
            foreach ($listmonkIds as $lmId) {
                $sub = $listmonk->getSubscriberById($lmId);
                if ($sub) {
                    $lists = $sub['lists'] ?? [];
                    foreach ($lists as $list) {
                        $listId = $list['id'] ?? null;
                        if ($listId !== null) {
                            $subscriberListStatus[$lmId][$listId] = $list['subscription_status'] ?? 'unconfirmed';
                        }
                    }
                }
            }

            // Transform results to include list status info
            $transformed = [];
            $groupedBySubscriber = [];

            foreach ($items as $item) {
                $email = $item['email'];
                $listmonkId = $item['listmonk_id'];
                $productId = $item['product_id'];
                $listId = $item['list_id'] ?? null;

                // Determine list status
                $listStatus = 'no list';
                if ($listId) {
                    // Get opt-in type (cache it)
                    if (!isset($listOptinCache[$listId])) {
                        $listOptinCache[$listId] = $listmonk->isDoubleOptIn($listId);
                    }
                    $isDoubleOptIn = $listOptinCache[$listId];

                    if ($isDoubleOptIn) {
                        $listStatus = $subscriberListStatus[$listmonkId][$listId] ?? 'not subscribed';
                    } else {
                        $listStatus = 'direct';
                    }
                }

                // Group drips by subscriber
                if (!isset($groupedBySubscriber[$email])) {
                    $groupedBySubscriber[$email] = [
                        'id' => $listmonkId,
                        'email' => $email,
                        'name' => '',
                        'status' => 'enabled',
                        'drips' => []
                    ];
                }

                $groupedBySubscriber[$email]['drips'][] = [
                    'product_id' => $productId,
                    'product_name' => $item['product_name'] ?? $productId,
                    'stage' => $item['stage'] ?? '',
                    'next' => $item['next_send'] ?? null,
                    'failures' => (int)($item['failures'] ?? 0),
                    'status_type' => $item['status'] ?? 'free',
                    'list_id' => $listId,
                    'list_status' => $listStatus
                ];
            }

            $transformed = array_values($groupedBySubscriber);

            echo json_encode([
                'data' => $transformed,
                'total' => $result['total'] ?? 0,
                'page' => $page,
                'per_page' => $perPage
            ]);
            break;

        // Reset a subscriber's drip state (FA-16: uses SQLite)
        // Note: 'plugin_id' in payload accepted for backward compat
        case $endpoint === 'drip/queue/reset' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $subscriberId = (int)($input['subscriber_id'] ?? 0);
            $productId = $input['plugin_id'] ?? $input['product_id'] ?? '';

            if (!$subscriberId || !$productId) {
                http_response_code(400);
                echo json_encode(['error' => 'subscriber_id and product_id are required']);
                break;
            }

            $seqDb = new SequenceDatabase();
            $success = $seqDb->resetDrip($subscriberId, $productId);

            if (!$success) {
                http_response_code(404);
                echo json_encode(['error' => 'Drip not found for subscriber/product']);
                break;
            }

            echo json_encode(['success' => true]);
            break;

        // =====================================================================
        // Subscriber Audit API Endpoint
        // =====================================================================

        // Audit a subscriber by email (FA-16: uses SQLite for drip/dunning state)
        case $endpoint === 'drip/audit' && $method === 'GET':
            $email = $_GET['email'] ?? '';

            if (empty($email)) {
                http_response_code(400);
                echo json_encode(['error' => 'Email address is required']);
                break;
            }

            // Find subscriber in Listmonk (for list membership, status, bounces)
            $sub = $listmonk->findSubscriberByEmail($email);
            if (!$sub) {
                http_response_code(404);
                echo json_encode(['error' => 'Subscriber not found', 'email' => $email]);
                break;
            }

            $listmonkId = $sub['id'];
            $attribs = $sub['attribs'] ?? [];

            // Get bounces for this subscriber
            $bounces = $listmonk->getSubscriberBounces($listmonkId);

            // Get sequence database and SQLite subscriber data
            $seqDb = new SequenceDatabase();
            $sqliteSubscriber = $seqDb->getSubscriberByEmail($email);
            $products = $seqDb->getProducts();
            $productListIds = $seqDb->getProductListIds();

            // Get drips and dunning from SQLite
            $sqliteDrips = [];
            $sqliteDunning = [];
            if ($sqliteSubscriber) {
                $dripsRaw = $seqDb->getSubscriberDrips((int)$sqliteSubscriber['id']);
                foreach ($dripsRaw as $drip) {
                    $sqliteDrips[$drip['product_id']] = $drip;
                }
                $dunningRaw = $seqDb->getSubscriberDunning((int)$sqliteSubscriber['id']);
                foreach ($dunningRaw as $dn) {
                    $sqliteDunning[$dn['list_id']] = $dn;
                }
            }

            // Get sequences for each product
            $sequences = [];
            foreach ($products as $product) {
                $sequences[$product['id']] = $seqDb->getSequencesByType($product['id']);
            }

            // Build list subscription status map from subscriber's lists
            $subscriberLists = $sub['lists'] ?? [];
            $listSubscriptionStatus = [];
            $listDetails = [];
            foreach ($subscriberLists as $list) {
                $listId = $list['id'] ?? null;
                if ($listId !== null) {
                    $listSubscriptionStatus[$listId] = $list['subscription_status'] ?? 'unconfirmed';
                    $listDetails[$listId] = [
                        'id' => $listId,
                        'uuid' => $list['uuid'] ?? null,
                        'name' => $list['name'] ?? "List $listId",
                        'type' => $list['type'] ?? 'private',
                        'subscription_status' => $list['subscription_status'] ?? 'unconfirmed',
                        'created_at' => $list['created_at'] ?? null
                    ];
                }
            }

            $now = new DateTime('now', new DateTimeZone('UTC'));
            $nowStr = $now->format('c');

            // Check marketing_allowed
            $marketingAllowed = $attribs['marketing_allowed'] ?? false;
            $marketingAllowedBool = ($marketingAllowed === true || $marketingAllowed === 'true');

            // Subscriber global status
            $subscriberStatus = $sub['status'] ?? 'enabled';
            $isBlocked = ($subscriberStatus === 'blocklisted' || $subscriberStatus === 'disabled');

            // Build audit data for each product using SQLite data
            $productAudits = [];
            foreach ($products as $product) {
                $productId = $product['id'];

                // Get SQLite drip data for this product
                $drip = $sqliteDrips[$productId] ?? null;

                // Skip if no drip data in SQLite for this product
                if (!$drip) {
                    continue;
                }

                $stage = $drip['stage'] ?? '';
                $nextDateStr = $drip['next_send'] ?? '';
                $failures = (int)($drip['failures'] ?? 0);
                $statusType = $drip['status'] ?? '';
                $startedDate = $drip['started_at'] ?? '';
                $isActive = (bool)($drip['is_active'] ?? 1);
                $lastEvent = $drip['last_event'] ?? '';

                // Get list info for this product
                $productListId = $productListIds[$productId] ?? null;
                $listInfo = null;
                $listSubscription = null;
                $isDoubleOptIn = false;

                if ($productListId) {
                    $listData = $listmonk->getList($productListId);
                    $isDoubleOptIn = ($listData['optin'] ?? 'single') === 'double';
                    $listSubscription = $listSubscriptionStatus[$productListId] ?? null;
                    $listInfo = [
                        'id' => $productListId,
                        'name' => $listData['name'] ?? "List $productListId",
                        'optin_type' => $listData['optin'] ?? 'single',
                        'subscription_status' => $listSubscription,
                        'is_subscribed' => isset($listSubscriptionStatus[$productListId])
                    ];
                }

                // Determine what would happen on next drip run
                $wouldSend = false;
                $blockReasons = [];
                $isDue = false;

                // Parse next date
                $nextDate = null;
                if (!empty($nextDateStr)) {
                    try {
                        $nextDate = new DateTime($nextDateStr, new DateTimeZone('UTC'));
                        $isDue = ($nextDate <= $now);
                    } catch (Exception $e) {
                        $blockReasons[] = "Invalid next date format: $nextDateStr";
                    }
                }

                // Check all blocking conditions (in order of processor logic)
                if (!$marketingAllowedBool) {
                    $blockReasons[] = 'marketing_allowed is false or not set';
                }
                if ($isBlocked) {
                    $blockReasons[] = "Subscriber status is '$subscriberStatus' (blocklisted or disabled)";
                }
                if (!$isActive) {
                    $blockReasons[] = 'Subscriber is inactive for this product';
                }
                if (in_array($stage, ['complete', 'stopped', 'error', 'none', 'imported', ''])) {
                    $blockReasons[] = "Stage is inactive: '" . ($stage ?: 'empty') . "'";
                }
                if (empty($nextDateStr)) {
                    $blockReasons[] = 'No next date set (next_send is empty)';
                }
                if ($failures >= 3) {
                    $blockReasons[] = "Max failures reached ($failures >= 3)";
                }
                if ($productListId && $listSubscription === 'unsubscribed') {
                    $blockReasons[] = "Unsubscribed from product list (list ID: $productListId)";
                }
                if ($productListId && $isDoubleOptIn && ($listSubscription === 'unconfirmed' || $listSubscription === null)) {
                    $blockReasons[] = "Waiting for double opt-in confirmation on list $productListId (status: " . ($listSubscription ?? 'not subscribed') . ")";
                }
                if (!$isDue && empty($blockReasons)) {
                    $blockReasons[] = "Not due yet (scheduled for " . ($nextDate ? $nextDate->format('Y-m-d H:i:s T') : $nextDateStr) . ")";
                }

                // Would send if no blocking reasons
                $wouldSend = empty($blockReasons);

                // Get current step info from sequences
                $currentStep = null;
                $nextStep = null;
                $sequenceType = null;
                $templateInfo = null;

                if (!empty($stage)) {
                    // Determine sequence type from stage prefix
                    if (str_starts_with($stage, 'free_')) {
                        $sequenceType = 'free';
                    } elseif (str_starts_with($stage, 'trial_')) {
                        $sequenceType = 'trial';
                    } elseif (str_starts_with($stage, 'premium_')) {
                        $sequenceType = 'premium';
                    }

                    if ($sequenceType && !empty($sequences[$productId][$sequenceType])) {
                        $steps = $sequences[$productId][$sequenceType];
                        $foundCurrent = false;
                        foreach ($steps as $step) {
                            if ($step['stage'] === $stage) {
                                $currentStep = $step;
                                $foundCurrent = true;

                                // Get template info for current step
                                if (!empty($step['template_id'])) {
                                    $template = $listmonk->getTemplate((int)$step['template_id']);
                                    if ($template) {
                                        $templateInfo = [
                                            'id' => $template['id'],
                                            'name' => $template['name'],
                                            'type' => $template['type'] ?? 'campaign'
                                        ];
                                    }
                                }
                            } elseif ($foundCurrent && !$nextStep) {
                                $nextStep = $step;
                            }
                        }
                    }
                }

                // Get all stages in this product's current sequence
                $allStages = [];
                if ($sequenceType && !empty($sequences[$productId][$sequenceType])) {
                    foreach ($sequences[$productId][$sequenceType] as $step) {
                        $allStages[] = [
                            'stage' => $step['stage'],
                            'subject' => $step['subject'],
                            'delay_days' => $step['delay_days'],
                            'template_id' => $step['template_id'],
                            'enabled' => (bool)$step['enabled'],
                            'is_current' => ($step['stage'] === $stage)
                        ];
                    }
                }

                $productAudits[$productId] = [
                    'product_name' => $product['name'],
                    'product_enabled' => (bool)$product['enabled'],
                    'status_type' => $statusType ?: '(not set)',
                    'drip' => [
                        'stage' => $stage ?: '(not set)',
                        'next_date_raw' => $nextDateStr,
                        'next_date_human' => $nextDate ? $nextDate->format('Y-m-d H:i:s T') : null,
                        'is_due' => $isDue,
                        'failures' => $failures,
                        'started' => $startedDate ?: '(not set)'
                    ],
                    'list' => $listInfo,
                    'decision' => [
                        'would_send_now' => $wouldSend,
                        'block_reasons' => $blockReasons,
                        'summary' => $wouldSend ? 'WOULD SEND' : 'BLOCKED: ' . implode('; ', $blockReasons)
                    ],
                    'current_step' => $currentStep ? [
                        'stage' => $currentStep['stage'],
                        'subject' => $currentStep['subject'],
                        'template_id' => $currentStep['template_id'],
                        'delay_days' => $currentStep['delay_days'],
                        'enabled' => (bool)$currentStep['enabled']
                    ] : null,
                    'template' => $templateInfo,
                    'next_step' => $nextStep ? [
                        'stage' => $nextStep['stage'],
                        'subject' => $nextStep['subject'],
                        'delay_days' => $nextStep['delay_days']
                    ] : ($stage === 'complete' ? 'Sequence completed' : null),
                    'sequence' => [
                        'type' => $sequenceType,
                        'total_steps' => count($allStages),
                        'stages' => $allStages
                    ]
                ];
            }

            // Build dunning state info from SQLite (FA-16)
            $dunningInfo = [];
            foreach ($sqliteDunning as $listId => $dunning) {
                $doiStage = $dunning['stage'] ?? '';
                $doiNext = $dunning['next_reminder'] ?? '';
                $doiStarted = $dunning['started_at'] ?? '';

                $doiNextDate = null;
                $doiNextHuman = null;
                if (!empty($doiNext)) {
                    try {
                        $doiNextDate = new DateTime($doiNext, new DateTimeZone('UTC'));
                        $doiNextHuman = $doiNextDate->format('Y-m-d H:i:s T');
                    } catch (Exception $e) {
                        $doiNextHuman = $doiNext;
                    }
                }

                $doiStartedHuman = null;
                if (!empty($doiStarted)) {
                    try {
                        $doiStartedDate = new DateTime($doiStarted, new DateTimeZone('UTC'));
                        $doiStartedHuman = $doiStartedDate->format('Y-m-d H:i:s T');
                    } catch (Exception $e) {
                        $doiStartedHuman = $doiStarted;
                    }
                }

                $doiListName = null;
                $doiList = $listmonk->getList($listId);
                $doiListName = $doiList['name'] ?? "List $listId";

                $dunningInfo[] = [
                    'stage' => $doiStage,
                    'stage_human' => str_replace('_', ' ', ucfirst($doiStage)),
                    'next_raw' => $doiNext,
                    'next_human' => $doiNextHuman,
                    'is_due' => $doiNextDate ? ($doiNextDate <= $now) : false,
                    'started_raw' => $doiStarted,
                    'started_human' => $doiStartedHuman,
                    'list_id' => $listId,
                    'list_name' => $doiListName
                ];
            }

            // Build complete audit response
            $audit = [
                'subscriber' => [
                    'id' => $sub['id'],
                    'uuid' => $sub['uuid'] ?? null,
                    'email' => $sub['email'],
                    'name' => $sub['name'] ?? '',
                    'status' => $subscriberStatus,
                    'created_at' => $sub['created_at'] ?? null,
                    'updated_at' => $sub['updated_at'] ?? null
                ],
                'global_checks' => [
                    'marketing_allowed' => $marketingAllowedBool,
                    'marketing_allowed_raw' => $marketingAllowed,
                    'subscriber_status' => $subscriberStatus,
                    'is_blocked' => $isBlocked,
                    'can_receive_drips' => $marketingAllowedBool && !$isBlocked,
                    'audit_time' => $nowStr,
                    'audit_time_human' => $now->format('Y-m-d H:i:s T')
                ],
                'lists' => array_values($listDetails),
                'bounces' => $bounces,
                'dunning' => $dunningInfo,
                'products' => $productAudits,
                'listmonk_attributes' => $attribs,
                'sqlite_data' => [
                    'subscriber' => $sqliteSubscriber,
                    'drips' => array_values($sqliteDrips),
                    'dunning' => array_values($sqliteDunning)
                ]
            ];

            echo json_encode(['data' => $audit], JSON_PRETTY_PRINT);
            break;

        // Retry all error subscribers for a product (FA-16: uses SQLite)
        // Note: 'plugin_id' in payload accepted for backward compat
        case $endpoint === 'drip/queue/retry-errors' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $productId = $input['plugin_id'] ?? $input['product_id'] ?? '';

            if (!$productId) {
                http_response_code(400);
                echo json_encode(['error' => 'product_id is required']);
                break;
            }

            $seqDb = new SequenceDatabase();
            $resetCount = $seqDb->retryProductErrors($productId);

            echo json_encode(['success' => true, 'reset_count' => $resetCount]);
            break;

        // =====================================================================
        // Segment Builder API Endpoints (Campaign List Builder)
        // =====================================================================

        // Get products (alias for drip/products for segment builder)
        case $endpoint === 'products' && $method === 'GET':
            $seqDb = new SequenceDatabase();
            echo json_encode(['data' => $seqDb->getProducts()]);
            break;

        // Get segment options (distinct statuses and stages from SQLite)
        case $endpoint === 'segment/options' && $method === 'GET':
            $productId = $_GET['product_id'] ?? null;
            $seqDb = new SequenceDatabase();

            $statuses = $seqDb->getDistinctDripValues('status', $productId);
            $stages = $seqDb->getDistinctDripValues('stage', $productId);

            // Filter out only empty strings
            $stages = array_values(array_filter($stages, fn($s) => $s !== ''));

            echo json_encode([
                'statuses' => $statuses,
                'stages' => $stages
            ]);
            break;

        // Preview segment (execute SQLite query + filter by marketing)
        case $endpoint === 'segment/preview' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $sql = $input['sql'] ?? '';
            $marketingRequired = $input['marketing_required'] ?? true;
            $page = max(1, (int)($input['page'] ?? 1));
            $perPage = min(500, max(1, (int)($input['per_page'] ?? 50))); // Cap at 500

            if (empty($sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'SQL query is required']);
                break;
            }

            // Security: only allow SELECT statements
            if (!preg_match('/^\s*SELECT\s/i', $sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'Only SELECT queries are allowed']);
                break;
            }

            // Block dangerous keywords
            if (preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'Query contains forbidden keywords']);
                break;
            }

            $seqDb = new SequenceDatabase();

            try {
                // Get total count first (fast)
                $countSql = preg_replace('/SELECT.*?FROM/is', 'SELECT COUNT(DISTINCT s.email) as cnt FROM', $sql);
                $countSql = preg_replace('/ORDER BY.*$/i', '', $countSql);
                $countResult = $seqDb->executeRawQuery($countSql);
                $sqliteTotal = $countResult[0]['cnt'] ?? 0;

                // Add pagination to SQLite query for speed
                $offset = ($page - 1) * $perPage;
                $pagedSql = $sql . " LIMIT $perPage OFFSET $offset";
                $results = $seqDb->executeRawQuery($pagedSql);

                // Get product names for display
                $products = $seqDb->getProducts();
                $productNames = [];
                foreach ($products as $p) {
                    $productNames[$p['id']] = $p['name'];
                }

                // Only check marketing for this page's results (fast)
                if ($marketingRequired && !empty($results)) {
                    $listmonkIds = array_filter(array_column($results, 'listmonk_id'));
                    if (!empty($listmonkIds)) {
                        $marketingAllowed = $listmonk->getMarketingAllowedIds($listmonkIds);

                        // Filter results to only those with marketing allowed
                        $results = array_filter($results, function($row) use ($marketingAllowed) {
                            return isset($row['listmonk_id']) && in_array($row['listmonk_id'], $marketingAllowed);
                        });
                        $results = array_values($results);
                    }
                }

                // Get subscriber names only for this page's results (fast)
                $listmonkIds = array_filter(array_column($results, 'listmonk_id'));
                $subscriberInfo = [];
                if (!empty($listmonkIds)) {
                    $subscriberInfo = $listmonk->getSubscriberNames($listmonkIds);
                }

                foreach ($results as &$row) {
                    $row['product_name'] = $productNames[$row['product_id']] ?? $row['product_id'];
                    $row['name'] = $subscriberInfo[$row['listmonk_id']]['name'] ?? '';
                }
                unset($row);

                echo json_encode([
                    'data' => $results,
                    'total' => $sqliteTotal,
                    'page' => $page,
                    'per_page' => $perPage,
                    'note' => $marketingRequired ? 'Total is pre-marketing filter; actual may be less' : null
                ]);
            } catch (PDOException $e) {
                http_response_code(400);
                echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
            }
            break;

        // Create list from segment
        case $endpoint === 'segment/create-list' && $method === 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $sql = $input['sql'] ?? '';
            $marketingRequired = $input['marketing_required'] ?? true;
            $listMode = $input['list_mode'] ?? 'new';
            $listName = $input['list_name'] ?? '';
            $listId = (int)($input['list_id'] ?? 0);
            $updateMode = $input['update_mode'] ?? 'replace';

            if (empty($sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'SQL query is required']);
                break;
            }

            // Security checks
            if (!preg_match('/^\s*SELECT\s/i', $sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'Only SELECT queries are allowed']);
                break;
            }
            if (preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|CREATE|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
                http_response_code(400);
                echo json_encode(['error' => 'Query contains forbidden keywords']);
                break;
            }

            if ($listMode === 'new' && empty($listName)) {
                http_response_code(400);
                echo json_encode(['error' => 'List name is required for new list']);
                break;
            }
            if ($listMode === 'update' && !$listId) {
                http_response_code(400);
                echo json_encode(['error' => 'List ID is required for update']);
                break;
            }

            $seqDb = new SequenceDatabase();

            try {
                // Execute SQLite query
                $results = $seqDb->executeRawQuery($sql);

                // Filter by marketing if required
                $listmonkIds = array_filter(array_column($results, 'listmonk_id'));

                if ($marketingRequired && !empty($listmonkIds)) {
                    $marketingAllowed = $listmonk->getMarketingAllowedIds($listmonkIds);
                    $listmonkIds = array_intersect($listmonkIds, $marketingAllowed);
                }

                $listmonkIds = array_values(array_unique($listmonkIds));

                if (empty($listmonkIds)) {
                    echo json_encode([
                        'success' => true,
                        'list_name' => $listName ?: 'existing list',
                        'subscriber_count' => 0,
                        'message' => 'No subscribers matched the criteria'
                    ]);
                    break;
                }

                // Create or update list
                if ($listMode === 'new') {
                    $listResult = $listmonk->createList($listName);
                    $listId = $listResult['data']['id'] ?? null;

                    if (!$listId) {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to create list']);
                        break;
                    }

                    $listmonk->addSubscribersToList($listmonkIds, $listId);
                } else {
                    // Get list name for response
                    $lists = $listmonk->getCampaignLists();
                    foreach ($lists as $list) {
                        if ($list['id'] === $listId) {
                            $listName = $list['name'];
                            break;
                        }
                    }

                    if ($updateMode === 'replace') {
                        $listmonk->replaceListSubscribers($listId, $listmonkIds);
                    } else {
                        $listmonk->addSubscribersToList($listmonkIds, $listId);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'list_id' => $listId,
                    'list_name' => $listName,
                    'subscriber_count' => count($listmonkIds)
                ]);
            } catch (PDOException $e) {
                http_response_code(400);
                echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

/**
 * Render a page
 */
function renderPage(string $page): void
{
    $appName = getenv('APP_NAME') ?: 'FlowMonk';
    $presets = new Presets();

    // Get data for templates
    $data = [
        'appName' => $appName,
        'presets' => $presets->getAll(),
        'attributes' => QueryBuilder::getAttributes(),
        'operators' => QueryBuilder::getOperators(),
    ];

    // Start output buffering
    ob_start();

    // Include the page template
    $templateFile = __DIR__ . "/../templates/{$page}.php";
    if (file_exists($templateFile)) {
        extract($data);
        include $templateFile;
    } else {
        echo "Template not found: $page";
    }

    $content = ob_get_clean();

    // Include the layout
    include __DIR__ . '/../templates/layout.php';
}
