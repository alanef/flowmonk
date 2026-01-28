#!/usr/bin/env php
<?php
/**
 * Drip Runner - Main Entry Point
 *
 * Cron job entry point that processes due drips and dunning from SQLite.
 * FA-16: Uses SQLite for state management, Listmonk for email delivery only.
 *
 * Usage:
 *   php drip-runner.php                    # Normal run
 *   php drip-runner.php --email=test@x.com # Process single subscriber (legacy)
 *   php drip-runner.php --dry-run          # Log only, don't update SQLite or send
 *   php drip-runner.php --no-email         # Update SQLite but skip sending emails
 *   php drip-runner.php --legacy           # Use legacy Listmonk attributes mode
 *
 * Environment variables:
 *   DRY_RUN=true   - Same as --dry-run
 *   NO_EMAIL=true  - Same as --no-email
 */

// Configuration
define('LOG_DIR', '/var/log/drip');
define('LOCK_FILE', LOG_DIR . '/runner.lock');
define('LAST_RUN_FILE', LOG_DIR . '/last-run');
define('LOCK_TIMEOUT', 600); // 10 minutes
define('DATA_DIR', '/data');

// Load classes
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ListmonkNotFoundException.php';
require_once __DIR__ . '/src/ListmonkClient.php';
require_once __DIR__ . '/src/SequenceManager.php';
require_once __DIR__ . '/shared/SequenceDatabase.php';
require_once __DIR__ . '/src/DripProcessor.php';
require_once __DIR__ . '/src/DunningProcessor.php';

// Parse command line arguments
$options = getopt('', ['email:', 'dry-run', 'legacy', 'no-email']);
$singleEmail = $options['email'] ?? null;
$dryRun = isset($options['dry-run']) || (getenv('DRY_RUN') === 'true');
$legacyMode = isset($options['legacy']); // Use legacy Listmonk attributes
$noEmail = isset($options['no-email']) || (getenv('NO_EMAIL') === 'true'); // Update SQLite but skip sending

// Initialize logger
$logLevel = getenv('LOG_LEVEL') ?: 'info';
$logger = new Logger(LOG_DIR, $logLevel);

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

/**
 * Acquire lock to prevent overlapping runs
 */
function acquireLock(Logger $logger): bool
{
    // Check for existing lock
    if (file_exists(LOCK_FILE)) {
        $lockAge = time() - filemtime(LOCK_FILE);

        // Check if lock is stale (older than timeout)
        if ($lockAge > LOCK_TIMEOUT) {
            $logger->warn("Removing stale lock file (age: {$lockAge}s)");
            unlink(LOCK_FILE);
        } else {
            $pid = file_get_contents(LOCK_FILE);
            $logger->warn("Lock file exists, another process may be running (PID: $pid, age: {$lockAge}s)");
            return false;
        }
    }

    // Create lock file with PID
    file_put_contents(LOCK_FILE, getmypid());
    return true;
}

/**
 * Release lock
 */
function releaseLock(): void
{
    if (file_exists(LOCK_FILE)) {
        unlink(LOCK_FILE);
    }
}

/**
 * Update last run timestamp
 */
function updateLastRun(): void
{
    file_put_contents(LAST_RUN_FILE, time());
}

/**
 * Build query for subscribers with due drips (legacy mode only)
 */
function buildDueSubscribersQuery(array $pluginIds): string
{
    $now = (new DateTime('now', new DateTimeZone('UTC')))->format('c');

    $conditions = [];
    foreach ($pluginIds as $pluginId) {
        $conditions[] = "(subscribers.attribs->>'p{$pluginId}_drip_next' IS NOT NULL " .
                       "AND subscribers.attribs->>'p{$pluginId}_drip_next' != '' " .
                       "AND subscribers.attribs->>'p{$pluginId}_drip_next' <= '$now')";
    }

    $query = "subscribers.attribs->>'marketing_allowed' = 'true' AND (" .
             implode(' OR ', $conditions) . ")";

    return $query;
}

// Main execution
try {
    $logger->info("Drip runner started" . ($dryRun ? ' [DRY RUN]' : '') . ($noEmail ? ' [NO EMAIL]' : '') . ($legacyMode ? ' [LEGACY]' : ''));

    // Acquire lock (unless single email mode)
    if (!$singleEmail && !acquireLock($logger)) {
        $logger->info("Exiting - could not acquire lock");
        exit(0);
    }

    // Register shutdown handler to release lock
    register_shutdown_function(function () {
        releaseLock();
    });

    // Load sequences configuration from SQLite database
    $sequenceManager = SequenceManager::fromDatabase(DATA_DIR);
    $sequenceDb = new SequenceDatabase(DATA_DIR);

    // Initialize client
    $client = new ListmonkClient();

    // Test connection
    if (!$client->testConnection()) {
        $logger->error("Cannot connect to Listmonk API");
        exit(1);
    }

    // Initialize processor with SQLite database
    $processor = new DripProcessor($client, $sequenceManager, $sequenceDb, $logger, $dryRun, $noEmail);

    // Track totals
    $totals = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    // Single email mode (uses legacy method)
    if ($singleEmail) {
        $logger->info("Processing single subscriber (legacy mode): $singleEmail");
        $query = "subscribers.email = '$singleEmail'";
        $response = $client->querySubscribers($query, 1, 1);
        $subscribers = $response['data']['results'] ?? [];

        if (empty($subscribers)) {
            $logger->warn("Subscriber not found: $singleEmail");
            exit(1);
        }

        $result = $processor->processSubscriber($subscribers[0]);
        $totals['processed'] = 1;
        $totals['sent'] = $result['sent'];
        $totals['failed'] = $result['failed'];
        $totals['skipped'] = $result['skipped'];

    } elseif ($legacyMode) {
        // Legacy mode - query Listmonk for subscribers with due drips
        $logger->info("Running in legacy mode (Listmonk attributes)");
        $query = buildDueSubscribersQuery($sequenceManager->getPluginIds());
        $logger->debug("Query: $query");

        $page = 1;
        do {
            $response = $client->querySubscribers($query, $page, 100);
            $subscribers = $response['data']['results'] ?? [];
            $total = $response['data']['total'] ?? 0;

            if ($page === 1) {
                $logger->info("Found $total subscribers with due drips");
            }

            foreach ($subscribers as $subscriber) {
                $result = $processor->processSubscriber($subscriber);
                $totals['processed']++;
                $totals['sent'] += $result['sent'];
                $totals['failed'] += $result['failed'];
                $totals['skipped'] += $result['skipped'];
            }

            $page++;
        } while (count($subscribers) === 100);

    } else {
        // Normal mode - query SQLite for due drips (FA-16)
        $logger->info("Processing due drips from SQLite");
        $result = $processor->processDueDrips();
        $totals['sent'] = $result['sent'];
        $totals['failed'] = $result['failed'];
        $totals['skipped'] = $result['skipped'];
        $totals['processed'] = $result['sent'] + $result['failed'] + $result['skipped'];
    }

    // Log summary
    $logger->info(sprintf(
        "Drip runner completed. Processed: %d, Sent: %d, Failed: %d, Skipped: %d",
        $totals['processed'],
        $totals['sent'],
        $totals['failed'],
        $totals['skipped']
    ));

    // Process dunning for double opt-in subscribers
    $logger->info("Starting dunning process for double opt-in confirmations...");
    $dunningProcessor = new DunningProcessor($client, $sequenceDb, $logger, $dryRun, $noEmail);
    $dunningResult = $dunningProcessor->processDunning();
    $logger->info(sprintf(
        "Dunning completed. Initiated: %d, Sent: %d, Deleted: %d, Confirmed: %d, Skipped: %d",
        $dunningResult['initiated'],
        $dunningResult['sent'],
        $dunningResult['deleted'],
        $dunningResult['confirmed'],
        $dunningResult['skipped']
    ));

    // Update last run timestamp
    updateLastRun();

    // Clean old logs (once per run)
    $deleted = $logger->cleanOldLogs(30);
    if ($deleted > 0) {
        $logger->debug("Cleaned up $deleted old log files");
    }

} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage());
    exit(1);
}