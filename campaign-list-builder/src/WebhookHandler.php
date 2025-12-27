<?php
/**
 * Webhook Handler
 *
 * Handles incoming product webhooks from Freemius and Freelib products.
 * FA-16: Uses SQLite for subscriber state management, Listmonk for list membership only.
 *
 * Note: Webhook payload uses 'plugin_id' field (from Freemius) but internally we treat
 * these as products. Variable names like $productId match internal terminology.
 *
 * Part of FA-4 epic: Consolidate Freemius webhook processing into email-helpers
 */

class WebhookHandler
{
    private SequenceDatabase $db;
    private Listmonk $listmonk;
    private bool $dryRun;

    public function __construct(SequenceDatabase $db, Listmonk $listmonk, bool $dryRun = false)
    {
        $this->db = $db;
        $this->listmonk = $listmonk;
        $this->dryRun = $dryRun;
    }

    /**
     * Handle incoming webhook request
     */
    public function handle(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, ['error' => 'Method not allowed']);
            return;
        }

        // Get raw payload and signature header
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $timestamp = date('c');

        // Parse payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('warning', "Invalid JSON payload from $clientIp");
            $this->respond(400, ['error' => 'Invalid JSON payload']);
            return;
        }

        // Extract product_id (called plugin_id in Freemius webhook payload)
        $productId = $data['plugin_id'] ?? null;
        if (empty($productId)) {
            $this->log('warning', "Missing plugin_id from $clientIp");
            $this->respond(400, ['error' => 'Missing plugin_id']);
            return;
        }

        // Log incoming webhook
        $eventType = $data['type'] ?? 'unknown';
        $this->log('info', "Webhook received: product=$productId, event=$eventType, ip=$clientIp");

        // Look up product in database
        $product = $this->db->getProductById($productId);
        if (!$product) {
            $this->log('warning', "Unknown product_id: $productId from $clientIp");
            $this->respond(400, ['error' => 'Unknown plugin_id', 'plugin_id' => $productId]);
            return;
        }

        // Verify based on product type
        $productType = $product['type'] ?? 'freemius';

        if ($productType === 'freemius') {
            // HMAC verification required
            $secret = $product['hmac_secret'] ?? '';

            if (empty($secret)) {
                $this->log('error', "No HMAC secret configured for freemius product $productId");
                $this->respond(500, ['error' => 'HMAC secret not configured for this product']);
                return;
            }

            if (empty($signature)) {
                $this->log('warning', "Missing X-Signature header for freemius product $productId from $clientIp");
                $this->respond(401, ['error' => 'Missing signature']);
                return;
            }

            // Calculate expected signature
            $calculated = hash_hmac('sha256', $payload, $secret);

            // Timing-safe comparison
            if (!hash_equals($calculated, $signature)) {
                $this->log('warning', "HMAC verification failed for product $productId from $clientIp");
                $this->respond(401, ['error' => 'Invalid signature']);
                return;
            }

            $this->log('debug', "HMAC verification passed for product $productId");
        } else {
            // freelib/other: Product exists in DB = valid (no HMAC needed)
            $this->log('debug', "Product $productId ($productType) validated by existence");
        }

        // Webhook verified - transform payload
        $this->log('info', "Webhook verified: product=$productId, type=$productType, event=$eventType");

        // Check if webhook has user data with email - skip if not
        $user = $data['objects']['user'] ?? null;
        if (empty($user) || empty($user['email'])) {
            $this->log('info', "Skipping webhook: no user email data (event=$eventType, product=$productId)");
            $this->respond(200, [
                'success' => true,
                'skipped' => true,
                'message' => 'Webhook skipped: no user email data',
                'plugin_id' => $productId,
                'event_type' => $eventType
            ]);
            return;
        }

        // Transform to subscriber format
        $transformed = $this->transformPayload($data, $productId);

        if ($this->dryRun) {
            $this->log('debug', "[DRY-RUN] Would process webhook for product $productId");
            $this->log('debug', "[DRY-RUN] Transformed payload: " . json_encode($transformed));
            $this->respond(200, [
                'success' => true,
                'dry_run' => true,
                'message' => 'Webhook verified and transformed (dry run - not processed)',
                'plugin_id' => $productId,
                'product_type' => $productType,
                'event_type' => $eventType,
                'transformed' => $transformed
            ]);
            return;
        }

        // FA-16: Create/update subscriber in SQLite and Listmonk
        try {
            $result = $this->processSubscriber($transformed, $productId, $eventType);

            $this->respond(200, [
                'success' => true,
                'message' => 'Webhook processed successfully',
                'plugin_id' => $productId,
                'product_type' => $productType,
                'event_type' => $eventType,
                'action' => $result['action'],
                'subscriber_id' => $result['subscriber_id'],
                'listmonk_id' => $result['listmonk_id'] ?? null
            ]);
        } catch (Exception $e) {
            $this->log('error', "Failed to process subscriber: " . $e->getMessage());
            $this->respond(500, [
                'error' => 'Failed to process subscriber',
                'message' => $e->getMessage(),
                'plugin_id' => $productId,
                'event_type' => $eventType
            ]);
        }
    }

    /**
     * Process subscriber in SQLite and Listmonk
     * FA-16: SQLite is source of truth for drip state, Listmonk for list membership
     */
    private function processSubscriber(array $transformed, string $productId, string $eventType): array
    {
        $email = $transformed['email'];
        $name = $transformed['name'];
        $listmonkAttribs = $transformed['listmonk_attribs']; // Minimal attribs for Listmonk
        $listId = $transformed['list_id'];
        $status = $transformed['status'];
        $isActive = $transformed['is_active'];
        $shouldInitDrip = $transformed['init_drip'];

        $this->log('debug', "Processing subscriber: $email");

        // 1. Find or create subscriber in Listmonk (for list membership)
        $listmonkId = null;
        $action = 'created';
        $existing = $this->listmonk->findSubscriberByEmail($email);

        if ($existing) {
            $listmonkId = $existing['id'];
            $action = 'updated';
            $this->log('debug', "Found existing subscriber: $email (Listmonk ID: $listmonkId)");

            // FA-16: Only update Listmonk if something actually changed AND safe to update
            // Avoid updateSubscriber calls which can trigger DOI re-confirmation emails
            $existingAttribs = $existing['attribs'] ?? [];
            $existingName = $existing['name'] ?? '';

            // Check if marketing_allowed changed
            $existingMarketingAllowed = $existingAttribs['marketing_allowed'] ?? false;
            $newMarketingAllowed = $listmonkAttribs['marketing_allowed'] ?? false;
            $existingMarketingBool = ($existingMarketingAllowed === true || $existingMarketingAllowed === 'true');
            $newMarketingBool = ($newMarketingAllowed === true || $newMarketingAllowed === 'true');
            $marketingChanged = ($existingMarketingBool !== $newMarketingBool);

            // Check if name changed
            $nameChanged = ($existingName !== $name && !empty($name));

            if ($marketingChanged || $nameChanged) {
                // Something changed - check if safe to update
                $isSafeToUpdate = true;

                if ($listId) {
                    $isDoubleOptIn = $this->listmonk->isDoubleOptIn($listId);
                    if ($isDoubleOptIn) {
                        // Check subscriber's status on this list
                        $subStatus = $this->getSubscriptionStatus($existing, $listId);
                        if ($subStatus === 'unconfirmed') {
                            $isSafeToUpdate = false;
                            $this->log('info', "[$email] Skipping Listmonk update: DOI list + unconfirmed (would trigger re-confirmation)");
                        }
                    }
                }

                if ($isSafeToUpdate) {
                    $changes = [];
                    if ($marketingChanged) $changes[] = "marketing_allowed: $existingMarketingBool -> $newMarketingBool";
                    if ($nameChanged) $changes[] = "name: '$existingName' -> '$name'";
                    $this->log('info', "[$email] Updating Listmonk: " . implode(', ', $changes));
                    $this->listmonk->updateSubscriber($listmonkId, $listmonkAttribs, $name);
                }
            } else {
                $this->log('debug', "[$email] No changes detected, skipping Listmonk update");
            }

            // Add to list if not already a member
            if ($listId && !$this->listmonk->isSubscriberInList($existing, $listId)) {
                $this->log('debug', "Adding subscriber $listmonkId to list $listId");
                $this->listmonk->addSubscribersToList([$listmonkId], $listId);
            }
        } else {
            // Create new subscriber in Listmonk
            $this->log('info', "Creating new subscriber: $email");
            $listIds = $listId ? [$listId] : [];
            $newSubscriber = $this->listmonk->createSubscriber($email, $name, $listmonkAttribs, $listIds);
            $listmonkId = $newSubscriber['id'] ?? null;
        }

        // 2. Create/update subscriber in SQLite
        $subscriber = $this->db->getOrCreateSubscriber($email, $listmonkId);
        $subscriberId = (int)$subscriber['id'];

        $this->log('debug', "SQLite subscriber ID: $subscriberId, Listmonk ID: $listmonkId");

        // 3. Initialize or update drip state in SQLite
        if ($shouldInitDrip) {
            $firstStage = $status . '_1'; // e.g., 'free_1', 'trial_1', 'premium_1'
            $nextSend = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

            $this->db->initializeDrip($subscriberId, $productId, $status, $firstStage, $nextSend);
            $this->log('info', "[$email] Initialized drip for product $productId: status=$status, stage=$firstStage");
        } else {
            // Just update the existing drip record with new event/status
            $existingDrip = $this->db->getDrip($subscriberId, $productId);
            if ($existingDrip) {
                $updates = [
                    'last_event' => $eventType,
                    'is_active' => $isActive ? 1 : 0,
                ];

                // Handle uninstall - mark as inactive but don't stop drip
                if ($eventType === 'install.uninstalled') {
                    $updates['is_active'] = 0;
                }

                $this->db->updateDrip((int)$existingDrip['id'], $updates);
                $this->log('debug', "[$email] Updated drip for product $productId: event=$eventType");
            } else {
                // No existing drip and not initializing - create one anyway for tracking
                $this->db->getOrCreateDrip($subscriberId, $productId, $status);
                $this->log('debug', "[$email] Created tracking drip for product $productId (no sequence)");
            }
        }

        return [
            'action' => $action,
            'subscriber_id' => $subscriberId,
            'listmonk_id' => $listmonkId
        ];
    }

    /**
     * Transform webhook payload
     * FA-16: Separates SQLite state from Listmonk attributes
     */
    private function transformPayload(array $data, string $productId): array
    {
        $user = $data['objects']['user'] ?? [];
        $install = $data['objects']['install'] ?? [];
        $eventType = $data['type'] ?? 'unknown';

        // Extract user info
        $email = $user['email'] ?? null;
        $firstName = $user['first'] ?? '';
        $lastName = $user['last'] ?? '';
        $name = trim("$firstName $lastName");
        $marketingAllowed = $user['is_marketing_allowed'] ?? false;
        $freemiusUserId = $user['id'] ?? null;

        // Extract install info
        $country = $install['country_code'] ?? null;
        $isActive = $install['is_active'] ?? true;

        // Determine status (free, trial, premium)
        $status = $this->determineStatus($install);

        // Check if this is a status change event
        $statusChanged = $this->isStatusChangeEvent($eventType);

        // Determine if drip should be initialized
        $shouldInitDrip = $this->shouldInitializeDrip($eventType, $statusChanged);

        // Minimal attributes for Listmonk (only what's needed there)
        $listmonkAttribs = [
            'marketing_allowed' => $marketingAllowed,
        ];

        // Add freemius user ID if present (for cross-referencing)
        if ($freemiusUserId) {
            $listmonkAttribs['freemius_user_id'] = $freemiusUserId;
        }

        // Add country if present
        if ($country) {
            $listmonkAttribs['country'] = $country;
        }

        return [
            'email' => $email,
            'name' => $name,
            'listmonk_attribs' => $listmonkAttribs,
            'list_id' => $this->db->getProductById($productId)['list_id'] ?? null,
            'status' => $status,
            'is_active' => $isActive,
            'init_drip' => $shouldInitDrip,
        ];
    }

    /**
     * Determine subscriber status based on install data
     */
    private function determineStatus(array $install): string
    {
        // Check for trial first
        if (!empty($install['trial_plan_id'])) {
            return 'trial';
        }

        // Check for premium (has license)
        if (!empty($install['is_premium']) && !empty($install['license_id'])) {
            return 'premium';
        }

        return 'free';
    }

    /**
     * Check if event type indicates a status change
     */
    private function isStatusChangeEvent(string $eventType): bool
    {
        $statusChangeEvents = [
            'license.created',
            'license.activated',
            'license.cancelled',
            'license.expired',
            'license.refunded',
            'install.trial_started',
            'subscription.created',
            'subscription.cancelled',
        ];
        return in_array($eventType, $statusChangeEvents);
    }

    /**
     * Check if drip should be initialized for this event
     */
    private function shouldInitializeDrip(string $eventType, bool $statusChanged): bool
    {
        // Always initialize on new install
        if ($eventType === 'install.installed') {
            return true;
        }

        // Initialize on status changes (upgrade/downgrade)
        if ($statusChanged) {
            return true;
        }

        return false;
    }

    /**
     * Get subscriber's subscription status for a specific list
     */
    private function getSubscriptionStatus(array $subscriber, int $listId): ?string
    {
        $lists = $subscriber['lists'] ?? [];
        foreach ($lists as $list) {
            if (($list['id'] ?? null) === $listId) {
                return $list['subscription_status'] ?? null;
            }
        }
        return null;
    }

    /**
     * Send JSON response
     */
    private function respond(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Log message
     */
    private function log(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $prefix = strtoupper($level);
        error_log("[$timestamp] [$prefix] [WebhookHandler] $message");
    }
}