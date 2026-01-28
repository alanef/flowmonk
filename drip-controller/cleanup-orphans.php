#!/usr/bin/env php
<?php
/**
 * Cleanup Orphaned Subscribers
 *
 * Finds subscribers in SQLite whose listmonk_id no longer exists in Listmonk
 * and marks their drips as 'deleted'.
 *
 * Usage:
 *   php cleanup-orphans.php           # Dry run (show what would be deleted)
 *   php cleanup-orphans.php --run     # Actually mark as deleted
 */

require_once __DIR__ . '/src/ListmonkClient.php';
require_once __DIR__ . '/shared/SequenceDatabase.php';

$dryRun = !in_array('--run', $argv);

if ($dryRun) {
    echo "=== DRY RUN MODE (use --run to execute) ===\n\n";
}

try {
    $db = new SequenceDatabase();
    $listmonk = new ListmonkClient();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Get all subscribers with listmonk_ids that have non-deleted drips
$stmt = $db->getDb()->query("
    SELECT DISTINCT s.id, s.listmonk_id, s.email
    FROM subscribers s
    JOIN subscriber_drips d ON s.id = d.subscriber_id
    WHERE s.listmonk_id IS NOT NULL
      AND d.stage != 'deleted'
    ORDER BY s.id
");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount = count($subscribers);
echo "Found $totalCount subscribers with active drips to check\n\n";

if ($totalCount === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$orphanCount = 0;
$errorCount = 0;
$batchSize = 100;
$batches = array_chunk($subscribers, $batchSize);

foreach ($batches as $batchIndex => $batch) {
    $batchNum = $batchIndex + 1;
    $totalBatches = count($batches);
    echo "Processing batch $batchNum/$totalBatches...\n";

    $listmonkIds = array_column($batch, 'listmonk_id');
    $idList = implode(',', array_map('intval', $listmonkIds));

    // Query Listmonk for all IDs in batch (any status)
    try {
        $query = "subscribers.id IN ($idList)";
        $existingIds = [];

        $page = 1;
        do {
            $result = $listmonk->querySubscribers($query, $page, 100);
            $found = $result['data']['results'] ?? [];

            foreach ($found as $sub) {
                $existingIds[] = $sub['id'];
            }

            $page++;
            $hasMore = count($found) === 100;
        } while ($hasMore);

        // Find orphans (IDs not in Listmonk)
        $orphans = array_filter($batch, function($sub) use ($existingIds) {
            return !in_array($sub['listmonk_id'], $existingIds);
        });

        foreach ($orphans as $orphan) {
            $orphanCount++;
            echo "  ORPHAN: {$orphan['email']} (listmonk_id: {$orphan['listmonk_id']})\n";

            if (!$dryRun) {
                $db->markSubscriberDeletedByListmonkId($orphan['listmonk_id']);
                echo "    -> Marked as deleted\n";
            }
        }

    } catch (Exception $e) {
        $errorCount++;
        echo "  ERROR: " . $e->getMessage() . " (skipping batch)\n";
        continue;
    }

    // Small delay between batches to avoid hammering API
    if ($batchIndex < count($batches) - 1) {
        usleep(100000); // 100ms
    }
}

echo "\n=== Summary ===\n";
echo "Total checked: $totalCount\n";
echo "Orphans found: $orphanCount\n";
echo "Errors: $errorCount\n";

if ($dryRun && $orphanCount > 0) {
    echo "\nRun with --run to mark orphans as deleted.\n";
}