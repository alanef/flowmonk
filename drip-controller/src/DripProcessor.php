<?php
/**
 * Drip Processor
 *
 * Processes drip emails using SQLite for state management.
 * FA-16: Uses SQLite subscriber_drips table instead of Listmonk attributes.
 */

class DripProcessor
{
    private ListmonkClient $client;
    private SequenceManager $sequences;
    private SequenceDatabase $db;
    private Logger $logger;
    private bool $dryRun;
    private bool $noEmail;
    private int $maxFailures = 3;

    public function __construct(
        ListmonkClient $client,
        SequenceManager $sequences,
        SequenceDatabase $db,
        Logger $logger,
        bool $dryRun = false,
        bool $noEmail = false
    ) {
        $this->client = $client;
        $this->sequences = $sequences;
        $this->db = $db;
        $this->logger = $logger;
        $this->dryRun = $dryRun;
        $this->noEmail = $noEmail;
    }

    /**
     * Process all due drips from SQLite
     *
     * @return array ['sent' => int, 'failed' => int, 'skipped' => int]
     */
    public function processDueDrips(): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        // Get all due drips from SQLite
        $dueDrips = $this->db->getDueDrips();
        $this->logger->info("Found " . count($dueDrips) . " due drips to process");

        foreach ($dueDrips as $drip) {
            $processResult = $this->processDrip($drip);

            switch ($processResult) {
                case 'sent':
                    $result['sent']++;
                    break;
                case 'failed':
                    $result['failed']++;
                    break;
                default:
                    $result['skipped']++;
            }

            // Small delay between processing
            $this->client->delay(100);
        }

        return $result;
    }

    /**
     * Process a single drip record
     *
     * @return string 'sent', 'failed', or 'skipped'
     */
    private function processDrip(array $drip): string
    {
        $email = $drip['email'];
        $listmonkId = $drip['listmonk_id'];
        $productId = $drip['product_id'];
        $stage = $drip['stage'];
        $failures = (int)$drip['failures'];
        $dripId = (int)$drip['id'];

        // Check failure count
        if ($failures >= $this->maxFailures) {
            $this->logger->warn("[$email] Skipping product $productId - max failures reached ($failures)");
            return 'skipped';
        }

        // Get the list ID for this product
        $listId = $this->sequences->getListId($productId);

        // Check subscriber status in Listmonk if we have a listmonk_id
        if ($listmonkId !== null) {
            try {
                $subscriber = $this->client->getSubscriber($listmonkId);
                $subscriberData = $subscriber['data'] ?? [];

                // Check marketing_allowed
                $attribs = $subscriberData['attribs'] ?? [];
                $marketingAllowed = $attribs['marketing_allowed'] ?? false;
                if ($marketingAllowed !== true && $marketingAllowed !== 'true') {
                    $this->logger->debug("[$email] Skipping - marketing not allowed");
                    return 'skipped';
                }

                // Check subscriber status
                $subscriberStatus = $subscriberData['status'] ?? 'enabled';
                if ($subscriberStatus === 'blocklisted' || $subscriberStatus === 'disabled') {
                    $this->logger->debug("[$email] Skipping - subscriber status is '$subscriberStatus'");
                    // Mark drip as stopped
                    $this->db->updateDrip($dripId, ['stage' => 'stopped', 'next_send' => null]);
                    return 'skipped';
                }

                // Check list subscription status
                if ($listId !== null) {
                    $subscriptionStatus = $this->getSubscriptionStatus($subscriberData, $listId);

                    // If unsubscribed - stop drip permanently
                    if ($subscriptionStatus === 'unsubscribed') {
                        $this->logger->debug("[$email] Stopping product $productId - unsubscribed from list $listId");
                        $this->db->updateDrip($dripId, ['stage' => 'stopped', 'next_send' => null]);
                        return 'skipped';
                    }

                    // If not on list, add them
                    if ($subscriptionStatus === null) {
                        $this->logger->info("[$email] Adding to list $listId for product $productId");
                        if (!$this->dryRun) {
                            try {
                                $this->client->addSubscribersToList([$listmonkId], $listId);
                                $isDoubleOptIn = $this->client->isDoubleOptIn($listId);
                                $subscriptionStatus = $isDoubleOptIn ? 'unconfirmed' : 'confirmed';
                            } catch (Exception $e) {
                                $this->logger->warn("[$email] Failed to add to list $listId: " . $e->getMessage());
                            }
                        }
                    }

                    // Check DOI status
                    $isDoubleOptIn = $this->client->isDoubleOptIn($listId);
                    if ($isDoubleOptIn && $subscriptionStatus === 'unconfirmed') {
                        // Hold off - reschedule 15 minutes from now
                        $newNextDate = $this->sequences->calculateNextDateMinutes(15);
                        $this->logger->debug("[$email] Holding off product $productId - list $listId not confirmed, rescheduling to $newNextDate");

                        if (!$this->dryRun) {
                            $this->db->updateDrip($dripId, ['next_send' => $newNextDate]);
                        }
                        return 'skipped';
                    }
                }
            } catch (Exception $e) {
                $this->logger->warn("[$email] Could not fetch subscriber from Listmonk: " . $e->getMessage());
                // Continue without Listmonk check if we can't reach it
            }
        }

        // Get current step from sequence config
        $step = $this->sequences->getStep($productId, $stage);
        if (!$step) {
            $this->logger->warn("[$email] Unknown stage for product $productId: $stage");
            return 'skipped';
        }

        // Send and advance
        $success = $this->sendAndAdvance($drip, $step);

        return $success ? 'sent' : 'failed';
    }

    /**
     * Get subscription status for a list from subscriber data
     */
    private function getSubscriptionStatus(array $subscriberData, int $listId): ?string
    {
        $lists = $subscriberData['lists'] ?? [];
        foreach ($lists as $list) {
            if (($list['id'] ?? null) === $listId) {
                return $list['subscription_status'] ?? 'unconfirmed';
            }
        }
        return null; // Not on list
    }

    /**
     * Send a drip email and update SQLite state
     */
    private function sendAndAdvance(array $drip, array $step): bool
    {
        $email = $drip['email'];
        $dripId = (int)$drip['id'];
        $productId = $drip['product_id'];
        $stage = $step['stage'];
        $templateId = $step['template_id'];

        // Prepare template data
        $templateData = [
            'subscriber_email' => $email,
            'product_id' => $productId,
            'stage' => $stage,
        ];

        // Dry run mode - no changes at all
        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY RUN] Would send product $productId $stage (template $templateId)");
            return true;
        }

        // No email mode - skip sending but update SQLite
        if ($this->noEmail) {
            $this->logger->info("[$email] [NO EMAIL] Skipping send for product $productId $stage (template $templateId)");
            // Fall through to update SQLite below
        } else {
            // Send the email
            try {
                $this->client->sendTx($email, $templateId, $templateData);
                $this->logger->info("[$email] Sent product $productId $stage (template $templateId)");
            } catch (Exception $e) {
                $this->logger->error("[$email] Failed to send product $productId $stage: " . $e->getMessage());

                // Increment failure count
                $failures = (int)$drip['failures'] + 1;
                $updates = ['failures' => $failures];

                // If max failures, set to error state
                if ($failures >= $this->maxFailures) {
                    $updates['stage'] = 'error';
                    $updates['next_send'] = null;
                    $this->logger->error("[$email] Product $productId drip stopped - max failures reached");
                }

                $this->db->updateDrip($dripId, $updates);
                return false;
            }
        }

        // Calculate next step
        $nextStep = $this->sequences->getNextStep($productId, $stage);
        $updates = ['failures' => 0]; // Reset failures on success

        if ($nextStep) {
            // More steps - advance to next
            $updates['stage'] = $nextStep['stage'];
            $updates['next_send'] = $this->sequences->calculateNextDate($nextStep['delay_days']);
            $this->logger->info("[$email] Advanced product $productId to {$nextStep['stage']}, next: {$updates['next_send']}");
        } else {
            // Sequence complete
            $updates['stage'] = 'complete';
            $updates['next_send'] = null;
            $this->logger->info("[$email] Product $productId sequence complete");
        }

        // Update SQLite
        $this->db->updateDrip($dripId, $updates);

        return true;
    }

    /**
     * Legacy method for backward compatibility during migration
     * Process all due drips for a subscriber (using Listmonk attributes)
     *
     * @deprecated Use processDueDrips() instead
     * @return array ['sent' => int, 'failed' => int, 'skipped' => int]
     */
    public function processSubscriber(array $subscriber): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $email = $subscriber['email'] ?? 'unknown';
        $attribs = $subscriber['attribs'] ?? [];
        $subscriberId = $subscriber['id'];

        // Check marketing_allowed (can be boolean true or string 'true')
        $marketingAllowed = $attribs['marketing_allowed'] ?? false;
        if ($marketingAllowed !== true && $marketingAllowed !== 'true') {
            $this->logger->debug("[$email] Skipping - marketing not allowed");
            $result['skipped']++;
            return $result;
        }

        // Check subscriber status - do NOT send to blocklisted or disabled subscribers
        $subscriberStatus = $subscriber['status'] ?? 'enabled';
        if ($subscriberStatus === 'blocklisted' || $subscriberStatus === 'disabled') {
            $this->logger->debug("[$email] Skipping - subscriber status is '$subscriberStatus'");
            $result['skipped']++;
            return $result;
        }

        // Build a map of list_id => subscription_status for quick lookup
        $lists = $subscriber['lists'] ?? [];
        $listSubscriptionStatus = [];
        foreach ($lists as $list) {
            $listId = $list['id'] ?? null;
            if ($listId !== null) {
                $listSubscriptionStatus[$listId] = $list['subscription_status'] ?? 'unconfirmed';
            }
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));

        // Check each plugin for due drips
        foreach ($this->sequences->getPluginIds() as $pluginId) {
            $stageKey = "p{$pluginId}_drip_stage";
            $nextKey = "p{$pluginId}_drip_next";
            $failuresKey = "p{$pluginId}_drip_failures";

            $stage = $attribs[$stageKey] ?? '';
            $nextDateStr = $attribs[$nextKey] ?? '';
            $failures = (int)($attribs[$failuresKey] ?? 0);

            // Skip if no next date or inactive stage
            if (empty($nextDateStr) || $this->sequences->isInactiveStage($stage)) {
                continue;
            }

            // Check if due
            try {
                $nextDate = new DateTime($nextDateStr, new DateTimeZone('UTC'));
            } catch (Exception $e) {
                $this->logger->warn("[$email] Invalid date for $nextKey: $nextDateStr");
                continue;
            }

            if ($nextDate > $now) {
                continue; // Not due yet
            }

            // Check failure count
            if ($failures >= $this->maxFailures) {
                $this->logger->warn("[$email] Skipping p$pluginId - max failures reached ($failures)");
                $result['skipped']++;
                continue;
            }

            // Check subscription status for this plugin's list
            $listId = $this->sequences->getListId($pluginId);
            if ($listId !== null) {
                $subscriptionStatus = $listSubscriptionStatus[$listId] ?? null;

                // If unsubscribed from this list - skip permanently (do not send)
                if ($subscriptionStatus === 'unsubscribed') {
                    $this->logger->debug("[$email] Skipping p$pluginId - unsubscribed from list $listId");
                    $result['skipped']++;
                    continue;
                }

                // If not on list at all, add them (self-healing for imports/edge cases)
                if ($subscriptionStatus === null) {
                    $this->logger->info("[$email] Adding to list $listId for p$pluginId (was not subscribed)");
                    if (!$this->dryRun) {
                        try {
                            $this->client->addSubscribersToList([$subscriberId], $listId);
                            // After adding: will be unconfirmed for double opt-in, confirmed for single
                            $isDoubleOptIn = $this->client->isDoubleOptIn($listId);
                            $subscriptionStatus = $isDoubleOptIn ? 'unconfirmed' : 'confirmed';
                        } catch (Exception $e) {
                            $this->logger->warn("[$email] Failed to add to list $listId: " . $e->getMessage());
                        }
                    }
                }

                // Only check confirmation status if the list is double opt-in
                $isDoubleOptIn = $this->client->isDoubleOptIn($listId);
                if ($isDoubleOptIn) {
                    // If unconfirmed - hold off, push next time 15 mins into future
                    if ($subscriptionStatus === 'unconfirmed') {
                        $newNextDate = $this->sequences->calculateNextDateMinutes(15);
                        $this->logger->debug("[$email] Holding off p$pluginId - list $listId (double opt-in) not confirmed, rescheduling to $newNextDate");

                        if (!$this->dryRun) {
                            try {
                                $this->client->updateSubscriber($subscriberId, [$nextKey => $newNextDate]);
                            } catch (Exception $e) {
                                $this->logger->warn("[$email] Failed to reschedule p$pluginId: " . $e->getMessage());
                            }
                        }
                        $result['skipped']++;
                        continue;
                    }
                }
                // Single opt-in list or confirmed - proceed with sending
            }

            // Get current step
            $step = $this->sequences->getStep($pluginId, $stage);
            if (!$step) {
                $this->logger->warn("[$email] Unknown stage for p$pluginId: $stage");
                $result['skipped']++;
                continue;
            }

            // Send and advance (using legacy attribute-based method)
            $success = $this->sendAndAdvanceLegacy($subscriber, $pluginId, $step, $attribs);

            if ($success) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }

            // Small delay between sends
            $this->client->delay(100);
        }

        return $result;
    }

    /**
     * Legacy send method using Listmonk attributes
     * @deprecated Will be removed after migration
     */
    private function sendAndAdvanceLegacy(
        array $subscriber,
        string $pluginId,
        array $step,
        array $currentAttribs
    ): bool {
        $email = $subscriber['email'];
        $subscriberId = $subscriber['id'];
        $stage = $step['stage'];
        $templateId = $step['template_id'];

        $stageKey = "p{$pluginId}_drip_stage";
        $nextKey = "p{$pluginId}_drip_next";
        $failuresKey = "p{$pluginId}_drip_failures";

        // Prepare template data
        $templateData = [
            'subscriber_name' => $subscriber['name'] ?? '',
            'subscriber_email' => $email,
            'plugin_id' => $pluginId,
            'stage' => $stage,
        ];

        // Dry run mode - log but don't send
        if ($this->dryRun) {
            $this->logger->info("[$email] [DRY RUN] Would send p$pluginId $stage (template $templateId)");
            return true;
        }

        // Send the email
        try {
            $this->client->sendTx($email, $templateId, $templateData);
            $this->logger->info("[$email] Sent p$pluginId $stage (template $templateId)");
        } catch (Exception $e) {
            $this->logger->error("[$email] Failed to send p$pluginId $stage: " . $e->getMessage());

            // Increment failure count
            $failures = (int)($currentAttribs[$failuresKey] ?? 0) + 1;
            $newAttribs = [$failuresKey => (string)$failures];

            // If max failures, set to error state
            if ($failures >= $this->maxFailures) {
                $newAttribs[$stageKey] = 'error';
                $newAttribs[$nextKey] = '';
                $this->logger->error("[$email] p$pluginId drip stopped - max failures reached");
            }

            $this->client->updateSubscriber($subscriberId, $newAttribs);
            return false;
        }

        // Calculate next step
        $nextStep = $this->sequences->getNextStep($pluginId, $stage);
        $newAttribs = [
            $failuresKey => '0', // Reset failures on success
        ];

        if ($nextStep) {
            // More steps - advance to next
            $newAttribs[$stageKey] = $nextStep['stage'];
            $newAttribs[$nextKey] = $this->sequences->calculateNextDate($nextStep['delay_days']);
            $this->logger->info("[$email] Advanced p$pluginId to {$nextStep['stage']}, next: {$newAttribs[$nextKey]}");
        } else {
            // Sequence complete
            $newAttribs[$stageKey] = 'complete';
            $newAttribs[$nextKey] = '';
            $this->logger->info("[$email] p$pluginId sequence complete");
        }

        // Update subscriber
        try {
            $this->client->updateSubscriber($subscriberId, $newAttribs);
        } catch (Exception $e) {
            $this->logger->error("[$email] Failed to update attributes: " . $e->getMessage());
            // Email was sent, so return true even if update failed
        }

        return true;
    }
}