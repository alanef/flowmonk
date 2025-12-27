#!/usr/bin/env php
<?php
/**
 * FA-16: Migration Script
 *
 * Migrates subscriber drip and dunning state from Listmonk attributes to SQLite.
 * Run this once to populate the SQLite database before switching to SQLite-based processing.
 *
 * Usage:
 *   php migrate-to-sqlite.php                    # Normal run
 *   php migrate-to-sqlite.php --dry-run          # Preview only, no changes
 *   php migrate-to-sqlite.php --product=1330     # Migrate single product only
 */

// Configuration
define('DATA_DIR', '/data');

// Load classes
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ListmonkClient.php';
require_once __DIR__ . '/shared/SequenceDatabase.php';

// Parse command line arguments
$options = getopt('', ['dry-run', 'product:']);
$dryRun = isset($options['dry-run']);
$singleProduct = $options['product'] ?? null;

// Initialize logger
$logLevel = getenv('LOG_LEVEL') ?: 'info';
$logger = new Logger('/var/log/drip', $logLevel);

echo "FA-16 Migration: Listmonk Attributes -> SQLite\n";
echo "================================================\n";
if ($dryRun) {
    echo "[DRY-RUN MODE - No changes will be made]\n";
}
echo "\n";

try {
    // Initialize clients
    $client = new ListmonkClient();
    $db = new SequenceDatabase(DATA_DIR);

    // Test connection
    if (!$client->testConnection()) {
        echo "ERROR: Cannot connect to Listmonk API\n";
        exit(1);
    }
    echo "Connected to Listmonk\n\n";

    // Get products from database
    $products = $db->getProducts();
    if ($singleProduct) {
        $products = array_filter($products, fn($p) => $p['id'] === $singleProduct);
        if (empty($products)) {
            echo "ERROR: Product '$singleProduct' not found\n";
            exit(1);
        }
    }
    echo "Products to migrate: " . count($products) . "\n\n";

    // Counters
    $stats = [
        'subscribers_imported' => 0,
        'subscribers_updated' => 0,
        'drips_migrated' => 0,
        'drips_skipped' => 0,
        'dunning_migrated' => 0,
        'errors' => 0
    ];

    // 0. Import ALL Listmonk subscribers first (so everyone is in SQLite)
    echo "Phase 0: Importing ALL Listmonk subscribers...\n";
    $page = 1;
    $perPage = 100;
    $totalImported = 0;

    do {
        // Empty query returns all subscribers
        $response = $client->querySubscribers('1=1', $page, $perPage);
        $subscribers = $response['data']['results'] ?? [];
        $total = $response['data']['total'] ?? 0;

        if ($page === 1) {
            echo "  Total subscribers in Listmonk: $total\n";
        }

        foreach ($subscribers as $sub) {
            $email = $sub['email'];
            $listmonkId = $sub['id'];

            if ($dryRun) {
                $stats['subscribers_imported']++;
                continue;
            }

            try {
                // Create or update subscriber in SQLite
                $existing = $db->getSubscriberByEmail($email);
                if ($existing) {
                    // Update listmonk_id if needed
                    if (empty($existing['listmonk_id']) || $existing['listmonk_id'] != $listmonkId) {
                        $db->updateSubscriber((int)$existing['id'], ['listmonk_id' => $listmonkId]);
                        $stats['subscribers_updated']++;
                    }
                } else {
                    $db->getOrCreateSubscriber($email, $listmonkId);
                    $stats['subscribers_imported']++;
                }
            } catch (Exception $e) {
                echo "  ERROR importing $email: " . $e->getMessage() . "\n";
                $stats['errors']++;
            }
        }

        $page++;
        $totalImported += count($subscribers);

        // Progress indicator every 500
        if ($totalImported % 500 < $perPage) {
            echo "  Processed $totalImported / $total subscribers...\n";
        }
    } while (count($subscribers) === $perPage);

    echo "  Imported {$stats['subscribers_imported']} new, updated {$stats['subscribers_updated']} existing\n\n";

    // 1. Migrate drip state for each product
    foreach ($products as $product) {
        $productId = $product['id'];
        $productName = $product['name'];
        echo "Processing product: $productName ($productId)\n";

        // Query subscribers with drip state for this product
        $stageKey = "p{$productId}_drip_stage";
        $query = "subscribers.attribs->>'$stageKey' IS NOT NULL AND subscribers.attribs->>'$stageKey' != ''";

        $page = 1;
        $perPage = 100;
        $productCount = 0;

        do {
            $response = $client->querySubscribers($query, $page, $perPage);
            $subscribers = $response['data']['results'] ?? [];
            $total = $response['data']['total'] ?? 0;

            if ($page === 1) {
                echo "  Found $total subscribers with drip state\n";
            }

            foreach ($subscribers as $sub) {
                $email = $sub['email'];
                $listmonkId = $sub['id'];
                $attribs = $sub['attribs'] ?? [];

                // Get drip attributes for this product
                $stage = $attribs["p{$productId}_drip_stage"] ?? '';
                $nextSend = $attribs["p{$productId}_drip_next"] ?? '';
                $failures = (int)($attribs["p{$productId}_drip_failures"] ?? 0);
                $status = $attribs["p{$productId}_status"] ?? 'free';
                $started = $attribs["p{$productId}_drip_started"] ?? null;
                $active = $attribs["p{$productId}_active"] ?? true;
                $lastEvent = $attribs["p{$productId}_last_event"] ?? null;

                // Skip empty stages
                if (empty($stage)) {
                    $stats['drips_skipped']++;
                    continue;
                }

                if ($dryRun) {
                    echo "  [DRY-RUN] Would migrate: $email - stage=$stage, status=$status\n";
                    $stats['drips_migrated']++;
                    $productCount++;
                    continue;
                }

                try {
                    // Get subscriber from SQLite (should already exist from Phase 0)
                    $subscriber = $db->getOrCreateSubscriber($email, $listmonkId);

                    // Determine is_active
                    $isActive = $active === true || $active === 'true' || $active === 1 || $active === '1';

                    // Initialize drip in SQLite
                    $db->initializeDrip(
                        (int)$subscriber['id'],
                        $productId,
                        $status,
                        $stage,
                        !empty($nextSend) ? $nextSend : null
                    );

                    // Update additional fields including original started_at
                    $drip = $db->getDrip((int)$subscriber['id'], $productId);
                    if ($drip) {
                        $updates = [
                            'failures' => $failures,
                            'is_active' => $isActive ? 1 : 0,
                            'last_event' => $lastEvent
                        ];
                        // Preserve original started_at from Listmonk
                        if (!empty($started)) {
                            $updates['started_at'] = $started;
                        }
                        $db->updateDrip((int)$drip['id'], $updates);
                    }

                    $stats['drips_migrated']++;
                    $productCount++;

                } catch (Exception $e) {
                    echo "  ERROR migrating $email: " . $e->getMessage() . "\n";
                    $stats['errors']++;
                }
            }

            $page++;
        } while (count($subscribers) === $perPage);

        echo "  Migrated $productCount drips for $productName\n\n";
    }

    // 2. Migrate dunning state
    echo "Migrating dunning state...\n";

    // Query subscribers with dunning state
    $query = "subscribers.attribs->>'doi_stage' IS NOT NULL AND subscribers.attribs->>'doi_stage' != ''";

    $page = 1;
    $perPage = 100;

    do {
        $response = $client->querySubscribers($query, $page, $perPage);
        $subscribers = $response['data']['results'] ?? [];
        $total = $response['data']['total'] ?? 0;

        if ($page === 1) {
            echo "  Found $total subscribers with dunning state\n";
        }

        foreach ($subscribers as $sub) {
            $email = $sub['email'];
            $listmonkId = $sub['id'];
            $attribs = $sub['attribs'] ?? [];

            // Get dunning attributes
            $stage = $attribs['doi_stage'] ?? '';
            $nextReminder = $attribs['doi_next'] ?? '';
            $started = $attribs['doi_started'] ?? '';
            $listId = (int)($attribs['doi_list_id'] ?? 0);

            // Skip if no stage or dunning complete
            if (empty($stage) || $stage === 'dunning_complete') {
                continue;
            }

            // Skip if no list_id
            if ($listId <= 0) {
                echo "  WARNING: $email has dunning but no list_id\n";
                continue;
            }

            if ($dryRun) {
                echo "  [DRY-RUN] Would migrate dunning: $email - stage=$stage, list=$listId\n";
                $stats['dunning_migrated']++;
                continue;
            }

            try {
                // Create/update subscriber in SQLite
                $subscriber = $db->getOrCreateSubscriber($email, $listmonkId);

                // Create dunning record with original started_at
                $dunning = $db->getOrCreateDunning(
                    (int)$subscriber['id'],
                    $listId,
                    $stage,
                    !empty($nextReminder) ? $nextReminder : (new DateTime('+1 day'))->format('Y-m-d\TH:i:s\Z')
                );

                // Update with original started_at if available
                if (!empty($started) && $dunning) {
                    $db->updateDunning((int)$dunning['id'], [
                        'started_at' => $started
                    ]);
                }

                $stats['dunning_migrated']++;

            } catch (Exception $e) {
                echo "  ERROR migrating dunning for $email: " . $e->getMessage() . "\n";
                $stats['errors']++;
            }
        }

        $page++;
    } while (count($subscribers) === $perPage);

    // Print summary
    echo "\n";
    echo "================================================\n";
    echo "Migration Complete\n";
    echo "================================================\n";
    echo "Subscribers imported: {$stats['subscribers_imported']}\n";
    echo "Subscribers updated: {$stats['subscribers_updated']}\n";
    echo "Drips migrated: {$stats['drips_migrated']}\n";
    echo "Drips skipped: {$stats['drips_skipped']}\n";
    echo "Dunning migrated: {$stats['dunning_migrated']}\n";
    echo "Errors: {$stats['errors']}\n";

    if ($dryRun) {
        echo "\n[DRY-RUN] No changes were made to the database\n";
    }

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}