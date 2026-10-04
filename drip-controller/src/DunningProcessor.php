<?php
/**
 * Dunning Processor
 *
 * Handles double opt-in confirmation reminders for unconfirmed subscribers.
 * FA-16: Uses SQLite for dunning state management.
 *
 * Dunning Schedule:
 * - Day 1: First reminder (dunning_1)
 * - Day 3: Second reminder (dunning_2)
 * - Day 7: Third reminder (dunning_3)
 * - Day 14: Final reminder (dunning_4)
 * - Day 21: Blocklist subscriber (dunning_blocklist)
 * Shared inboxes (info@, contact@, ...) get no reminders: dunning_quiet, then blocklist at day 21.
 *
 * Expired subscribers are blocklisted in Listmonk, not deleted, so the address
 * stays suppressed: Listmonk won't mail it and the webhook won't re-add it.
 * Every step is recorded in dunning_events for per-step conversion stats.
 */

class DunningProcessor
{
    private ListmonkClient $client;
    private SequenceDatabase $db;
    private Logger $logger;
    private bool $dryRun;
    private bool $noEmail;
    private ?array $doubleOptInListIdsCache = null;

    // Shared inboxes: the original opt-in email is sent, but no reminders. On a
    // one-person site info@ is often the owner, but on a larger one the person
    // reading it never signed up and is the likeliest to report spam.
    // Admin/noreply-type addresses are rejected earlier by FreelibGuard.
    private const NO_REMINDER_LOCAL_PARTS = ['info', 'contact', 'hello', 'office', 'sales', 'support', 'enquiries', 'inquiries', 'mail', 'team'];
    private const DUNNING_EXPIRY_DAYS = 21;

    // Dunning schedule: stage => [delay to next stage, next stage name]
    // delay_days is days UNTIL next stage (not cumulative from start)
    private const SCHEDULE = [
        'dunning_1' => ['delay_days' => 2, 'next_stage' => 'dunning_2'],      // Day 1 -> Day 3
        'dunning_2' => ['delay_days' => 4, 'next_stage' => 'dunning_3'],      // Day 3 -> Day 7
        'dunning_3' => ['delay_days' => 7, 'next_stage' => 'dunning_4'],      // Day 7 -> Day 14
        'dunning_4' => ['delay_days' => 7, 'next_stage' => 'dunning_blocklist'], // Day 14 -> Day 21
        'dunning_blocklist' => ['delay_days' => 0, 'next_stage' => null],     // Final action
        'dunning_quiet' => ['delay_days' => 0, 'next_stage' => null],         // Shared inbox: expiry only, no reminders
    ];

    public function __construct(ListmonkClient $client, SequenceDatabase $db, Logger $logger, bool $dryRun = false, bool $noEmail = false)
    {
        $this->client = $client;
        $this->db = $db;
        $this->logger = $logger;
        $this->dryRun = $dryRun;
        $this->noEmail = $noEmail;
    }

    /**
     * Process all dunning actions using SQLite
     *
     * @return array ['initiated' => int, 'sent' => int, 'expired' => int, 'deleted' => int, 'confirmed' => int, 'skipped' => int]
     */
    public function processDunning(): array
    {
        $result = ['initiated' => 0, 'sent' => 0, 'expired' => 0, 'deleted' => 0, 'confirmed' => 0, 'skipped' => 0];

        // 1. Check all active dunning records for confirmed subscribers (BATCH approach)
        $this->logger->debug("Checking active dunning records for confirmations...");
        $activeDunning = $this->db->getActiveDunning();
        $this->logger->info("Checking " . count($activeDunning) . " active dunning records");

        // Get unique list IDs from active dunning
        $dunningListIds = array_unique(array_column($activeDunning, 'list_id'));
        $this->logger->debug("Fetching confirmed subscribers for " . count($dunningListIds) . " lists");

        // Batch query Listmonk for all confirmed subscribers on these lists
        $confirmedMap = $this->client->getConfirmedSubscribersForLists($dunningListIds);
        $this->logger->info("Found " . count($confirmedMap) . " confirmed subscribers across DOI lists");

        // Now clear dunning for confirmed subscribers (fast - no more API calls)
        foreach ($activeDunning as $dunning) {
            $listmonkId = $dunning['listmonk_id'];
            $listId = (int)$dunning['list_id'];
            $subscriberId = (int)$dunning['subscriber_id'];
            $email = $dunning['email'];

            // Check if this subscriber is confirmed on this list
            if (isset($confirmedMap[$listmonkId]) && in_array($listId, $confirmedMap[$listmonkId])) {
                if (!$this->dryRun) {
                    $this->db->deleteDunning($subscriberId, $listId);
                    $this->db->recordDunningEvent($subscriberId, $listId, 'confirmed', $dunning['stage']);
                }
                $this->logger->info("[$email] Subscriber confirmed on list $listId - cleared dunning");
                $result['confirmed']++;
            }
        }

        // 2. Find new unconfirmed subscribers and initiate dunning (BATCH approach)
        $this->logger->debug("Checking for new unconfirmed subscribers...");
        $doubleOptInLists = $this->getDoubleOptInListIds();
        if (!empty($doubleOptInLists)) {
            // Get unconfirmed subscribers directly from Listmonk (batch query)
            $unconfirmedMap = $this->client->getUnconfirmedSubscribersForLists($doubleOptInLists);
            $this->logger->info("Found " . count($unconfirmedMap) . " unconfirmed subscribers across DOI lists");

            // Get subscribers from SQLite that don't have dunning and are in unconfirmed map
            $subscribersWithoutDunning = $this->db->getSubscribersWithoutDunning($doubleOptInLists);
            $this->logger->debug("Checking " . count($subscribersWithoutDunning) . " SQLite subscribers for dunning initiation");

            foreach ($subscribersWithoutDunning as $subscriber) {
                $listmonkId = $subscriber['listmonk_id'];
                $email = $subscriber['email'];
                $subscriberId = (int)$subscriber['id'];

                if (!$listmonkId || !isset($unconfirmedMap[$listmonkId])) {
                    continue; // Not unconfirmed or not in Listmonk
                }

                // Get the first unconfirmed DOI list for this subscriber
                $unconfirmedListId = $unconfirmedMap[$listmonkId][0] ?? null;
                if (!$unconfirmedListId) {
                    continue;
                }

                // Check marketing_allowed from batch data (if available) or skip for now
                // Note: We'll do a quick check on marketing for new initiations
                try {
                    $sub = $this->client->getSubscriber($listmonkId);
                    $attribs = $sub['data']['attribs'] ?? [];
                    $marketingAllowed = $attribs['marketing_allowed'] ?? false;
                    if ($marketingAllowed !== true && $marketingAllowed !== 'true') {
                        continue;
                    }
                    $status = $sub['data']['status'] ?? 'enabled';
                    if ($status !== 'enabled') {
                        continue;
                    }
                } catch (Exception $e) {
                    continue;
                }

                // Initiate dunning. Shared inboxes skip straight to expiry: no reminders.
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $noReminders = self::isSharedInbox($email);
                $firstStage = $noReminders ? 'dunning_quiet' : 'dunning_1';
                $firstDate = (clone $now)->modify($noReminders ? '+' . self::DUNNING_EXPIRY_DAYS . ' days' : '+1 day');
                $note = $noReminders ? ' (shared inbox - no reminders)' : '';

                if ($this->dryRun) {
                    $this->logger->info("[$email] [DRY-RUN] Would initiate dunning for list $unconfirmedListId$note");
                    $result['initiated']++;
                    continue;
                }

                $this->db->getOrCreateDunning(
                    $subscriberId,
                    $unconfirmedListId,
                    $firstStage,
                    $firstDate->format('Y-m-d\TH:i:s\Z')
                );
                $this->db->recordDunningEvent($subscriberId, $unconfirmedListId, 'initiated', $firstStage);
                $this->logger->info("[$email] Initiated dunning for list $unconfirmedListId$note");
                $result['initiated']++;

                $this->client->delay(50);
            }
        }

        // 3. Process due dunning reminders
        $this->logger->debug("Processing due dunning reminders...");
        $dueDunning = $this->db->getDueDunning();
        $this->logger->info("Found " . count($dueDunning) . " due dunning reminders");

        foreach ($dueDunning as $dunning) {
            $advanceResult = $this->advanceDunning($dunning);

            switch ($advanceResult) {
                case 'sent':
                    $result['sent']++;
                    break;
                case 'expired':
                    $result['expired']++;
                    break;
                case 'deleted':
                    $result['deleted']++;
                    break;
                case 'confirmed':
                    $result['confirmed']++;
                    break;
                default:
                    $result['skipped']++;
            }

            $this->client->delay(100);
        }

        return $result;
    }

    /**
     * Check if subscriber has confirmed and clear dunning if so
     */
    private function checkAndClearConfirmed(array $dunning): bool
    {
        $email = $dunning['email'];
        $listmonkId = $dunning['listmonk_id'];
        $listId = (int)$dunning['list_id'];
        $subscriberId = (int)$dunning['subscriber_id'];

        if (!$listmonkId) {
            return false;
        }

        try {
            $subscriber = $this->client->getSubscriber($listmonkId);
            $subscriberData = $subscriber['data'] ?? [];

            if ($this->isConfirmedOnList($subscriberData, $listId)) {
                // Subscriber has confirmed - delete dunning record
                if (!$this->dryRun) {
                    $this->db->deleteDunning($subscriberId, $listId);
                }
                $this->logger->info("[$email] Subscriber confirmed on list $listId - cleared dunning");
                return true;
            }
        } catch (Exception $e) {
            $this->logger->warn("[$email] Could not check confirmation status: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Check if subscriber is unconfirmed and initiate dunning if needed
     */
    private function initiateIfUnconfirmed(array $subscriber, array $doubleOptInLists): bool
    {
        $email = $subscriber['email'];
        $listmonkId = $subscriber['listmonk_id'];
        $subscriberId = (int)$subscriber['id'];

        if (!$listmonkId) {
            return false;
        }

        try {
            $listmonkSubscriber = $this->client->getSubscriber($listmonkId);
            $subscriberData = $listmonkSubscriber['data'] ?? [];

            // Check marketing_allowed
            $attribs = $subscriberData['attribs'] ?? [];
            $marketingAllowed = $attribs['marketing_allowed'] ?? false;
            if ($marketingAllowed !== true && $marketingAllowed !== 'true') {
                return false;
            }

            // Check subscriber status
            $status = $subscriberData['status'] ?? 'enabled';
            if ($status !== 'enabled') {
                return false;
            }

            // Find unconfirmed DOI list
            $unconfirmedListId = $this->findUnconfirmedDoiList($subscriberData, $doubleOptInLists);
            if (!$unconfirmedListId) {
                return false;
            }

            // Initiate dunning
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $firstReminderDate = (clone $now)->modify('+1 day');

            if ($this->dryRun) {
                $this->logger->info("[$email] [DRY-RUN] Would initiate dunning for list $unconfirmedListId");
                return true;
            }

            $this->db->getOrCreateDunning(
                $subscriberId,
                $unconfirmedListId,
                'dunning_1',
                $firstReminderDate->format('Y-m-d\TH:i:s\Z')
            );
            $this->logger->info("[$email] Initiated dunning for list $unconfirmedListId, first reminder: " . $firstReminderDate->format('Y-m-d H:i:s'));
            return true;

        } catch (Exception $e) {
            $this->logger->warn("[$email] Could not check for dunning initiation: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Advance dunning for a subscriber with due reminder
     *
     * @return string 'sent', 'blocklisted', 'confirmed', or 'skipped'
     */
    private function advanceDunning(array $dunning): string
    {
        $email = $dunning['email'];
        $listmonkId = $dunning['listmonk_id'];
        $listId = (int)$dunning['list_id'];
        $subscriberId = (int)$dunning['subscriber_id'];
        $currentStage = $dunning['stage'];
        $dunningId = (int)$dunning['id'];

        if (!isset(self::SCHEDULE[$currentStage])) {
            $this->logger->warning("[$email] Unknown dunning stage: $currentStage");
            return 'skipped';
        }

        // Check subscriber status before advancing dunning
        if ($listmonkId) {
            try {
                $subscriber = $this->client->getSubscriber($listmonkId);
                $subscriberData = $subscriber['data'] ?? [];

                // Check if subscriber is blocklisted - clear dunning
                $subscriberStatus = $subscriberData['status'] ?? 'enabled';
                if ($subscriberStatus === 'blocklisted') {
                    if (!$this->dryRun) {
                        $this->db->deleteDunning($subscriberId, $listId);
                        $this->db->recordDunningEvent($subscriberId, $listId, 'blocklisted', $currentStage);
                    }
                    $this->logger->info("[$email] Subscriber blocklisted - cleared dunning");
                    return 'skipped';
                }

                // Check if subscriber has unsubscribed from the list - clear dunning
                if ($this->isUnsubscribedFromList($subscriberData, $listId)) {
                    if (!$this->dryRun) {
                        $this->db->deleteDunning($subscriberId, $listId);
                        $this->db->recordDunningEvent($subscriberId, $listId, 'unsubscribed', $currentStage);
                    }
                    $this->logger->info("[$email] Unsubscribed from list $listId - cleared dunning");
                    return 'skipped';
                }

                // Check if subscriber has confirmed - clear dunning
                if ($this->isConfirmedOnList($subscriberData, $listId)) {
                    if (!$this->dryRun) {
                        $this->db->deleteDunning($subscriberId, $listId);
                        $this->db->recordDunningEvent($subscriberId, $listId, 'confirmed', $currentStage);
                    }
                    $this->logger->info("[$email] Subscriber confirmed - cleared dunning");
                    return 'confirmed';
                }
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $this->logger->warn("[$email] Could not check confirmation: " . $errorMsg);

                // If subscriber was deleted from Listmonk, clean up SQLite
                if (stripos($errorMsg, 'not found') !== false) {
                    $this->logger->info("[$email] Subscriber deleted from Listmonk, cleaning up dunning record");
                    if (!$this->dryRun) {
                        $this->db->deleteDunning($subscriberId, $listId);
                        $this->db->markSubscriberDeletedByListmonkId($listmonkId);
                        $this->db->recordDunningEvent($subscriberId, $listId, 'deleted', $currentStage);
                    }
                    return 'deleted';
                }
            }
        }

        // Final stage: never confirmed after 21 days - blocklist as suppression
        if ($currentStage === 'dunning_blocklist' || $currentStage === 'dunning_quiet') {
            $success = $this->expireUnconfirmedSubscriber($dunning);
            return $success ? 'expired' : 'skipped';
        }

        // Send reminder email (resend opt-in confirmation)
        $sent = $this->sendDunningEmail($dunning, $currentStage);
        if (!$sent) {
            return 'skipped';
        }

        // Advance to next stage
        $schedule = self::SCHEDULE[$currentStage];
        $nextStage = $schedule['next_stage'];
        $delayDays = $schedule['delay_days'];

        $nextDate = (new DateTime('now', new DateTimeZone('UTC')))
            ->modify("+$delayDays days")
            ->format('Y-m-d\TH:i:s\Z');

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would advance to $nextStage");
            return 'sent';
        }

        $this->db->updateDunning($dunningId, [
            'stage' => $nextStage,
            'next_reminder' => $nextDate,
        ]);
        $this->db->recordDunningEvent($subscriberId, $listId, 'sent', $currentStage);
        $this->logger->info("[$email] Advanced dunning to $nextStage, next: $nextDate");

        return 'sent';
    }

    /**
     * Whether the address is a shared inbox that gets no dunning reminders
     */
    public static function isSharedInbox(string $email): bool
    {
        $local = strtolower(explode('@', trim($email), 2)[0]);
        $local = explode('+', $local, 2)[0];
        return in_array($local, self::NO_REMINDER_LOCAL_PARTS, true);
    }

    /**
     * Get all double opt-in list IDs (cached)
     */
    private function getDoubleOptInListIds(): array
    {
        if ($this->doubleOptInListIdsCache !== null) {
            return $this->doubleOptInListIdsCache;
        }

        $lists = $this->client->getLists();
        $doubleOptIn = [];
        foreach ($lists as $list) {
            if (($list['optin'] ?? 'single') === 'double') {
                $doubleOptIn[] = $list['id'];
            }
        }

        $this->doubleOptInListIdsCache = $doubleOptIn;
        return $doubleOptIn;
    }

    /**
     * Check if subscriber is confirmed on a specific list
     */
    private function isConfirmedOnList(array $subscriberData, int $listId): bool
    {
        $lists = $subscriberData['lists'] ?? [];
        foreach ($lists as $list) {
            if (($list['id'] ?? null) === $listId) {
                return ($list['subscription_status'] ?? 'unconfirmed') === 'confirmed';
            }
        }
        return false; // Not on list at all
    }

    /**
     * Check if subscriber has unsubscribed from a specific list
     */
    private function isUnsubscribedFromList(array $subscriberData, int $listId): bool
    {
        $lists = $subscriberData['lists'] ?? [];
        foreach ($lists as $list) {
            if (($list['id'] ?? null) === $listId) {
                return ($list['subscription_status'] ?? '') === 'unsubscribed';
            }
        }
        return true; // Not on list at all = treat as unsubscribed
    }

    /**
     * Find the first unconfirmed DOI list for a subscriber
     */
    private function findUnconfirmedDoiList(array $subscriberData, array $doubleOptInLists): ?int
    {
        $lists = $subscriberData['lists'] ?? [];
        foreach ($lists as $list) {
            $listId = $list['id'] ?? null;
            if ($listId !== null &&
                in_array($listId, $doubleOptInLists) &&
                ($list['subscription_status'] ?? '') === 'unconfirmed') {
                return $listId;
            }
        }
        return null;
    }

    /**
     * Send dunning reminder email using Listmonk's built-in opt-in confirmation
     */
    private function sendDunningEmail(array $dunning, string $stage): bool
    {
        $email = $dunning['email'];
        $listmonkId = $dunning['listmonk_id'];

        if (!$listmonkId) {
            $this->logger->error("[$email] Cannot send dunning - no Listmonk ID");
            return false;
        }

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would resend optin confirmation ($stage)");
            return true;
        }

        // No email mode - skip sending but continue with SQLite updates
        if ($this->noEmail) {
            $this->logger->info("[$email] [NO EMAIL] Skipping optin confirmation resend ($stage)");
            return true; // Return true so SQLite gets updated
        }

        // Use Listmonk's built-in optin confirmation resend API
        $success = $this->client->resendOptinConfirmation($listmonkId);

        if ($success) {
            $this->logger->info("[$email] Resent optin confirmation ($stage)");
        } else {
            $this->logger->error("[$email] Failed to resend optin confirmation ($stage)");
        }

        return $success;
    }

    /**
     * Blocklist a subscriber who never confirmed after 21 days.
     *
     * Blocklisting (rather than deleting) keeps the address as a suppression record:
     * Listmonk won't send to it on any path, it is unsubscribed from all lists, and
     * the webhook won't re-add it, so a timed-out address can't be dunned again.
     */
    private function expireUnconfirmedSubscriber(array $dunning): bool
    {
        $email = $dunning['email'];
        $listmonkId = $dunning['listmonk_id'];
        $subscriberId = (int)$dunning['subscriber_id'];
        $listId = (int)$dunning['list_id'];

        if (!$listmonkId) {
            $this->logger->error("[$email] Cannot blocklist - no Listmonk ID");
            return false;
        }

        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY-RUN] Would blocklist (21 days unconfirmed)");
            return true;
        }

        // No email mode - leave Listmonk untouched; drop the SQLite subscriber so dunning
        // isn't re-initiated for an address that is still unconfirmed in Listmonk
        if ($this->noEmail) {
            $this->logger->info("[$email] [NO EMAIL] Skipping Listmonk blocklist, deleting subscriber from SQLite only");
            $this->db->recordDunningEvent($subscriberId, $listId, 'expired', $dunning['stage']);
            $this->db->deleteSubscriber($subscriberId);
            return true;
        }

        try {
            if (!$this->client->blocklistSubscriber($listmonkId)) {
                $this->logger->error("[$email] Failed to blocklist in Listmonk");
                return false;
            }
        } catch (Exception $e) {
            $this->logger->error("[$email] Failed to blocklist in Listmonk: " . $e->getMessage());
            return false;
        }

        $this->db->deleteDunning($subscriberId, $listId);
        $this->db->recordDunningEvent($subscriberId, $listId, 'expired', $dunning['stage']);
        $this->logger->info("[$email] Blocklisted after 21 days unconfirmed");
        return true;
    }
}
