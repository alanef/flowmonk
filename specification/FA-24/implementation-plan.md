# Implementation Plan: FA-24

## Fix: Dunning process crashes on deleted subscribers

## Executive Summary

- **Problem**: The dunning process crashes with a fatal error when it encounters subscribers that have been deleted from the Listmonk API but still exist in the local SQLite database.
- **Root Cause**: `blocklistSubscriber()` calls `setSubscriberStatus()` without try-catch handling. When `getSubscriber()` returns a 404 error, it throws an unhandled exception.
- **Complexity**: Simple (single error handling fix)
- **Files to modify**: 1 file, ~15 lines changed

## Architecture Analysis

### Current Flow (Crashing)

```
advanceDunning() → blocklistSubscriber() → setSubscriberStatus() → getSubscriber()
                                                                       ↓
                                                              API 404 → FATAL CRASH
```

### Fixed Flow

```
advanceDunning() → blocklistSubscriber() → [try-catch] → setSubscriberStatus()
                         ↓                                     ↓
                   On Exception:                          Success → deleteDunning()
                   - Log warning                          Failure → log error
                   - deleteDunning() (cleanup stale record)
                   - Return false (continue processing)
```

### Existing Pattern to Follow

The codebase already handles this correctly in `advanceDunning()` at lines 291-327:

```php
if ($listmonkId) {
    try {
        $subscriber = $this->client->getSubscriber($listmonkId);
        // ... check status ...
    } catch (Exception $e) {
        $this->logger->warn("[$email] Could not check confirmation: " . $e->getMessage());
        // CONTINUES PROCESSING
    }
}
```

## Detailed Implementation Steps

### Step 1: Modify `blocklistSubscriber()` in DunningProcessor.php

**File**: `drip-controller/src/DunningProcessor.php`
**Lines**: 493-504

**Current Code** (lines 493-504):
```php
// Set status to blocklisted via Listmonk API
$success = $this->client->setSubscriberStatus($listmonkId, 'blocklisted');

if ($success) {
    // Delete the dunning record since we're done
    $this->db->deleteDunning($subscriberId, $listId);
    $this->logger->info("[$email] Blocklisted after 21 days unconfirmed");
} else {
    $this->logger->error("[$email] Failed to blocklist subscriber");
}

return $success;
```

**New Code**:
```php
// Set status to blocklisted via Listmonk API
try {
    $success = $this->client->setSubscriberStatus($listmonkId, 'blocklisted');

    if ($success) {
        // Delete the dunning record since we're done
        $this->db->deleteDunning($subscriberId, $listId);
        $this->logger->info("[$email] Blocklisted after 21 days unconfirmed");
    } else {
        $this->logger->error("[$email] Failed to blocklist subscriber");
    }

    return $success;
} catch (Exception $e) {
    // Subscriber may have been deleted from Listmonk - clean up local record
    $this->logger->warn("[$email] Could not blocklist (subscriber may be deleted): " . $e->getMessage());
    if (!$this->dryRun) {
        $this->db->deleteDunning($subscriberId, $listId);
    }
    return false;
}
```

## Testing Strategy

### Manual Test Steps

1. **Start with dry run to see affected subscribers**:
   ```bash
   docker exec -e DRY_RUN=true drip-controller php /app/drip-runner.php
   ```

2. **Run actual dunning process**:
   ```bash
   docker exec drip-controller php /app/drip-runner.php
   ```

3. **Verify logs show warnings instead of fatal errors**:
   ```bash
   docker logs -f drip-controller | grep -E "(Could not blocklist|Blocklisted after)"
   ```

4. **Verify healthcheck passes**:
   ```bash
   docker exec drip-controller cat /data/last-run
   ```

### Expected Behavior After Fix

- Script logs warning: `[email@example.com] Could not blocklist (subscriber may be deleted): API error (400): Subscriber not found`
- Local dunning record is deleted (cleanup)
- Script continues processing remaining subscribers
- `last-run` timestamp is updated
- Healthcheck passes

## Acceptance Criteria

- [ ] Dunning process handles "Subscriber not found" errors gracefully
- [ ] Script logs warning instead of crashing on 400/404 API errors
- [ ] Stale dunning records are cleaned up from SQLite when subscriber is missing
- [ ] Script completes processing of all remaining subscribers
- [ ] `last-run` timestamp is updated after successful completion
- [ ] Healthcheck passes after dunning run

## Risks & Considerations

- **Minimal risk**: This is a defensive error handling change
- **No breaking changes**: Existing behavior is preserved for valid subscribers
- **Data cleanup**: Stale records are cleaned up, which is the desired behavior
- **Logging**: Warnings are logged for visibility, allowing monitoring of deleted subscriber frequency

## Alternative Considered (Not Recommended)

**Option B: Modify `ListmonkClient::setSubscriberStatus()` to return false on error**

This would make it match `resendOptinConfirmation()` which already uses try-catch and returns bool. However, this changes the API contract and could mask errors in other callers. The targeted fix in `blocklistSubscriber()` is safer.