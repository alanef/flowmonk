# Implementation Plan: FA-3 - Double Opt-In Dunning Process

## Executive Summary
- **Feature:** Automated reminder emails for unconfirmed double opt-in subscribers with eventual blocklisting
- **Scope:** Global system (one dunning sequence for ALL double opt-in lists)
- **Complexity:** Medium
- **Files to modify:** 3 files in drip-controller (DripProcessor.php unchanged)
- **Template approach:** Use Listmonk's built-in `POST /api/subscribers/{id}/optin` to resend confirmation (no custom templates needed)

## Dunning Schedule

| Days Since Added | Stage | Action |
|-----------------|-------|--------|
| 1 day | `dunning_1` | First reminder |
| 3 days | `dunning_2` | Second reminder |
| 7 days | `dunning_3` | Third reminder |
| 14 days | `dunning_4` | Final reminder |
| 21 days | `dunning_blocklist` | Set status to blocklisted |

## Architecture Decision: Global Dunning

Unlike plugin-specific drips (`p{id}_drip_stage`), dunning uses **global attributes**:
- `doi_stage` - Current dunning stage
- `doi_next` - Next dunning email date (ISO 8601)
- `doi_started` - When added to double opt-in list (ISO 8601)
- `doi_list_id` - The double opt-in list they're on (for template context)

**Rationale:** A subscriber only needs ONE dunning sequence regardless of which double opt-in list they're on. Once confirmed on any list, they're no longer "unconfirmed."

---

## Files to Modify

### 1. `drip-controller/src/DunningProcessor.php` (NEW FILE)

Create a dedicated processor for dunning, separate from DripProcessor.

**Key methods:**
```php
class DunningProcessor {
    private ListmonkClient $client;
    private Logger $logger;

    // Dunning schedule (could be config but hardcoded is fine for now)
    private const SCHEDULE = [
        'dunning_1' => ['delay_days' => 1, 'next_stage' => 'dunning_2'],
        'dunning_2' => ['delay_days' => 2, 'next_stage' => 'dunning_3'],  // +2 from day 1 = day 3
        'dunning_3' => ['delay_days' => 4, 'next_stage' => 'dunning_4'],  // +4 from day 3 = day 7
        'dunning_4' => ['delay_days' => 7, 'next_stage' => 'dunning_blocklist'],  // +7 from day 7 = day 14
        'dunning_blocklist' => ['delay_days' => 7, 'next_stage' => null],  // +7 from day 14 = day 21
    ];

    public function processDunning(): array;
    private function queryDueUnconfirmed(): array;
    private function initiateDunning(array $subscriber, int $listId): void;
    private function advanceDunning(array $subscriber): bool;
    private function sendDunningEmail(array $subscriber, string $stage): bool;
    private function blocklistSubscriber(int $subscriberId): void;
}
```

### 2. `drip-controller/src/ListmonkClient.php`

Add method to update subscriber status:

```php
// Around line 150, add:
public function setSubscriberStatus(int $id, string $status): bool
{
    // status: 'enabled', 'blocklisted', 'disabled'
    $data = $this->getSubscriber($id);
    if (!$data) return false;

    // Preserve lists
    $listIds = array_map(fn($l) => $l['id'], $data['lists'] ?? []);

    $response = $this->request('PUT', "subscribers/$id", [
        'email' => $data['email'],
        'name' => $data['name'] ?? '',
        'status' => $status,
        'attribs' => $data['attribs'] ?? [],
        'lists' => $listIds
    ]);

    return $response !== null;
}
```

### 3. `drip-controller/drip-runner.php`

Add dunning processing call:

```php
// After existing drip processing (around line 180)

// Process dunning for unconfirmed double opt-in subscribers
$dunningProcessor = new DunningProcessor($client, $logger);
$dunningResults = $dunningProcessor->processDunning();

$logger->info("Dunning processing complete", $dunningResults);
```

### 4. `drip-controller/src/DripProcessor.php` - NO CHANGES NEEDED

~~Modify to skip dunning-stage subscribers~~ **REMOVED**

**Rationale:** DripProcessor already handles unconfirmed DOI subscribers correctly:
- Lines 129-147 check if subscriber is unconfirmed on a double opt-in list
- If unconfirmed → holds off and reschedules 15 mins later
- If confirmed → proceeds to send drip

Adding a skip for `doi_stage` would be:
1. **Redundant** - the unconfirmed check already handles it
2. **Harmful** - if subscriber confirms while in dunning, drips would be delayed until the next dunning run clears `doi_stage`

The two processors work independently without coupling:
- DunningProcessor: manages confirmation reminders and blocklisting
- DripProcessor: sends drips, holds off if unconfirmed (regardless of dunning state)

---

## Implementation Steps

### Step 1: Create DunningProcessor.php

```php
<?php
namespace App;

class DunningProcessor
{
    private ListmonkClient $client;
    private $logger;
    private bool $dryRun;

    private const SCHEDULE = [
        'dunning_1' => ['delay_days' => 1, 'next_stage' => 'dunning_2', 'template_id' => null],
        'dunning_2' => ['delay_days' => 2, 'next_stage' => 'dunning_3', 'template_id' => null],
        'dunning_3' => ['delay_days' => 4, 'next_stage' => 'dunning_4', 'template_id' => null],
        'dunning_4' => ['delay_days' => 7, 'next_stage' => 'dunning_blocklist', 'template_id' => null],
        'dunning_blocklist' => ['delay_days' => 7, 'next_stage' => null, 'template_id' => null],
    ];

    public function __construct(ListmonkClient $client, $logger, bool $dryRun = false)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->dryRun = $dryRun;
    }

    public function processDunning(): array
    {
        $result = ['initiated' => 0, 'sent' => 0, 'blocklisted' => 0, 'skipped' => 0];

        // 1. Find NEW unconfirmed subscribers (no doi_stage yet) - initiate dunning
        $newUnconfirmed = $this->queryNewUnconfirmed();
        foreach ($newUnconfirmed as $subscriber) {
            $this->initiateDunning($subscriber);
            $result['initiated']++;
        }

        // 2. Find subscribers with due dunning (doi_next <= now)
        $dueDunning = $this->queryDueDunning();
        foreach ($dueDunning as $subscriber) {
            $advanced = $this->advanceDunning($subscriber);
            if ($advanced) {
                if ($subscriber['attribs']['doi_stage'] === 'dunning_4') {
                    $result['blocklisted']++;
                } else {
                    $result['sent']++;
                }
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    private function queryNewUnconfirmed(): array
    {
        // Find subscribers who are:
        // - On a double opt-in list with status 'unconfirmed'
        // - Do NOT have doi_stage attribute yet
        // - status = 'enabled' (not already blocklisted)
        // - marketing_allowed = true

        $doubleOptInLists = $this->getDoubleOptInListIds();
        if (empty($doubleOptInLists)) return [];

        $listIdsCsv = implode(',', $doubleOptInLists);

        // Query: unconfirmed on DOI lists, no doi_stage, enabled, marketing_allowed
        $query = "subscribers.status = 'enabled' " .
                 "AND (subscribers.attribs->>'marketing_allowed')::boolean = true " .
                 "AND subscribers.attribs->>'doi_stage' IS NULL " .
                 "AND EXISTS (SELECT 1 FROM subscriber_lists sl " .
                 "WHERE sl.subscriber_id = subscribers.id " .
                 "AND sl.list_id IN ($listIdsCsv) " .
                 "AND sl.status = 'unconfirmed')";

        return $this->client->querySubscribers($query);
    }

    private function queryDueDunning(): array
    {
        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        // Subscribers with doi_next <= now AND doi_stage is set
        $query = "subscribers.status = 'enabled' " .
                 "AND subscribers.attribs->>'doi_stage' IS NOT NULL " .
                 "AND subscribers.attribs->>'doi_next' IS NOT NULL " .
                 "AND subscribers.attribs->>'doi_next' <= '$now'";

        return $this->client->querySubscribers($query);
    }

    private function getDoubleOptInListIds(): array
    {
        $lists = $this->client->getLists();
        $doubleOptIn = [];
        foreach ($lists as $list) {
            if (($list['optin'] ?? 'single') === 'double') {
                $doubleOptIn[] = $list['id'];
            }
        }
        return $doubleOptIn;
    }

    private function initiateDunning(array $subscriber): void
    {
        $email = $subscriber['email'];
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        // Find which DOI list they're unconfirmed on
        $doiListId = null;
        foreach ($subscriber['lists'] ?? [] as $list) {
            if ($this->client->isDoubleOptIn($list['id']) &&
                ($list['subscription_status'] ?? '') === 'unconfirmed') {
                $doiListId = $list['id'];
                break;
            }
        }

        if (!$doiListId) {
            $this->logger->warning("[$email] Could not find DOI list for initiation");
            return;
        }

        // Calculate first reminder date (1 day from now)
        $firstReminderDate = (clone $now)->modify('+1 day');

        $attribs = [
            'doi_stage' => 'dunning_1',
            'doi_next' => $firstReminderDate->format('Y-m-d\TH:i:s\Z'),
            'doi_started' => $now->format('Y-m-d\TH:i:s\Z'),
            'doi_list_id' => (string)$doiListId,
        ];

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would initiate dunning", $attribs);
            return;
        }

        $this->client->updateSubscriber($subscriber['id'], $attribs);
        $this->logger->info("[$email] Initiated dunning sequence", $attribs);
    }

    private function advanceDunning(array $subscriber): bool
    {
        $email = $subscriber['email'];
        $attribs = $subscriber['attribs'] ?? [];
        $currentStage = $attribs['doi_stage'] ?? '';

        if (!isset(self::SCHEDULE[$currentStage])) {
            $this->logger->warning("[$email] Unknown dunning stage: $currentStage");
            return false;
        }

        $schedule = self::SCHEDULE[$currentStage];

        // Check if subscriber confirmed (no longer unconfirmed on any DOI list)
        if ($this->isConfirmed($subscriber)) {
            $this->clearDunning($subscriber);
            $this->logger->info("[$email] Subscriber confirmed - cleared dunning");
            return false;
        }

        // Special handling for blocklist stage
        if ($currentStage === 'dunning_blocklist') {
            return $this->blocklistSubscriber($subscriber);
        }

        // Send reminder email
        $sent = $this->sendDunningEmail($subscriber, $currentStage);
        if (!$sent) {
            return false;
        }

        // Advance to next stage
        $nextStage = $schedule['next_stage'];
        $delayDays = self::SCHEDULE[$nextStage]['delay_days'] ?? 7;
        $nextDate = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify("+$delayDays days")
            ->format('Y-m-d\TH:i:s\Z');

        $newAttribs = [
            'doi_stage' => $nextStage,
            'doi_next' => $nextDate,
        ];

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would advance to $nextStage");
            return true;
        }

        $this->client->updateSubscriber($subscriber['id'], $newAttribs);
        $this->logger->info("[$email] Advanced dunning to $nextStage, next: $nextDate");

        return true;
    }

    private function isConfirmed(array $subscriber): bool
    {
        foreach ($subscriber['lists'] ?? [] as $list) {
            if ($this->client->isDoubleOptIn($list['id']) &&
                ($list['subscription_status'] ?? '') === 'unconfirmed') {
                return false; // Still unconfirmed on at least one DOI list
            }
        }
        return true; // Confirmed on all DOI lists (or not on any)
    }

    private function clearDunning(array $subscriber): void
    {
        if ($this->dryRun) return;

        // Clear dunning attributes
        $this->client->updateSubscriber($subscriber['id'], [
            'doi_stage' => '',
            'doi_next' => '',
            'doi_started' => '',
            'doi_list_id' => '',
        ]);
    }

    private function sendDunningEmail(array $subscriber, string $stage): bool
    {
        $email = $subscriber['email'];

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would resend optin confirmation ($stage)");
            return true;
        }

        // Use Listmonk's built-in optin confirmation resend API
        $success = $this->client->resendOptinConfirmation($subscriber['id']);

        if ($success) {
            $this->logger->info("[$email] Resent optin confirmation ($stage)");
        } else {
            $this->logger->error("[$email] Failed to resend optin confirmation ($stage)");
        }

        return $success;
    }

    private function blocklistSubscriber(array $subscriber): bool
    {
        $email = $subscriber['email'];

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would blocklist (21 days unconfirmed)");
            return true;
        }

        // Set status to blocklisted
        $success = $this->client->setSubscriberStatus($subscriber['id'], 'blocklisted');

        if ($success) {
            // Clear dunning attributes
            $this->client->updateSubscriber($subscriber['id'], [
                'doi_stage' => 'dunning_complete',
                'doi_next' => '',
            ]);
            $this->logger->info("[$email] Blocklisted after 21 days unconfirmed");
        } else {
            $this->logger->error("[$email] Failed to blocklist");
        }

        return $success;
    }
}
```

### Step 2: Add setSubscriberStatus to ListmonkClient.php

Add after `updateSubscriber()` method (around line 150):

```php
/**
 * Set subscriber status (enabled, blocklisted, disabled)
 */
public function setSubscriberStatus(int $id, string $status): bool
{
    $data = $this->getSubscriber($id);
    if (!$data) return false;

    // Preserve lists
    $listIds = [];
    foreach ($data['lists'] ?? [] as $list) {
        if (isset($list['id'])) {
            $listIds[] = $list['id'];
        }
    }

    $response = $this->request('PUT', "subscribers/$id", [
        'email' => $data['email'],
        'name' => $data['name'] ?? '',
        'status' => $status,
        'attribs' => $data['attribs'] ?? [],
        'lists' => $listIds
    ]);

    return $response !== null;
}

/**
 * Get all lists
 */
public function getLists(): array
{
    $response = $this->request('GET', 'lists', ['per_page' => 100]);
    return $response['data']['results'] ?? [];
}

/**
 * Resend opt-in confirmation email to subscriber
 * Uses Listmonk's built-in confirmation email
 */
public function resendOptinConfirmation(int $subscriberId): bool
{
    try {
        $response = $this->request('POST', "subscribers/$subscriberId/optin", []);
        return ($response['data'] ?? false) === true;
    } catch (Exception $e) {
        return false;
    }
}
```

### Step 3: Integrate into drip-runner.php

Add after existing drip processing:

```php
// === DUNNING PROCESSING ===
$logger->info("Starting dunning processing for unconfirmed DOI subscribers");

$dunningProcessor = new \App\DunningProcessor($client, $logger, $dryRun);
$dunningResults = $dunningProcessor->processDunning();

$logger->info("Dunning processing complete", [
    'initiated' => $dunningResults['initiated'],
    'sent' => $dunningResults['sent'],
    'blocklisted' => $dunningResults['blocklisted'],
    'skipped' => $dunningResults['skipped'],
]);
```

---

## Template Strategy

**Option A (Recommended for MVP):** Use Listmonk's API to resend the opt-in confirmation

Listmonk may have an endpoint to resend confirmation. Check:
```bash
curl -X POST "https://email.fw9.uk/api/subscribers/{id}/optin" -u "api:PASSWORD"
```

**Option B:** Create custom transactional templates

Create 4 templates in Listmonk:
- `doi-reminder-1` - Friendly first reminder
- `doi-reminder-2` - Second reminder, more urgent
- `doi-reminder-3` - Third reminder, consequences mentioned
- `doi-reminder-4` - Final warning

Each template should include the confirmation link using Listmonk's template variables.

---

## Testing Plan

1. **Dry run test:**
   ```bash
   php drip-runner.php --dry-run
   ```

2. **Single subscriber test:**
   - Add test subscriber to DOI list
   - Manually set `doi_stage` and `doi_next` in past
   - Run processor
   - Verify advancement

3. **Full cycle test:**
   - Create test subscriber
   - Run processor daily for 21 days
   - Verify reminders sent at correct intervals
   - Verify blocklisting at day 21

---

## Acceptance Criteria

- [ ] New unconfirmed DOI subscribers get `doi_stage` initialized after 1 day
- [ ] Reminders sent at 1, 3, 7, 14 days
- [ ] Subscriber blocklisted at 21 days if still unconfirmed
- [ ] Confirmed subscribers have dunning cleared
- [ ] No duplicate reminders sent
- [ ] Blocklisted subscribers excluded from future processing
- [ ] Dry-run mode works correctly
- [ ] All actions logged
