<?php
/**
 * Listmonk API Client for Drip Controller
 *
 * Handles all communication with the Listmonk API including
 * subscriber queries, attribute updates, and transactional emails.
 */

class ListmonkClient
{
    private string $baseUrl;
    private string $user;
    private string $pass;
    private int $retryDelay = 1000; // milliseconds
    private array $listCache = []; // Cache for list info

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('LISTMONK_URL') ?: '', '/');
        $this->user = getenv('LISTMONK_USER') ?: '';
        $this->pass = getenv('LISTMONK_PASS') ?: '';

        if (empty($this->baseUrl) || empty($this->user) || empty($this->pass)) {
            throw new RuntimeException('Listmonk credentials not configured');
        }
    }

    /**
     * Make an API request to Listmonk with retry support
     */
    private function request(string $method, string $endpoint, ?array $data = null, int $maxRetries = 3): array
    {
        $url = $this->baseUrl . '/api/' . ltrim($endpoint, '/');
        $attempts = 0;

        while ($attempts < $maxRetries) {
            $attempts++;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->pass);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                if ($attempts < $maxRetries) {
                    usleep($this->retryDelay * 1000 * $attempts);
                    continue;
                }
                throw new RuntimeException("Curl error: $error");
            }

            $decoded = json_decode($response, true);

            // Handle rate limiting with exponential backoff
            if ($httpCode === 429) {
                if ($attempts < $maxRetries) {
                    $delay = min($this->retryDelay * pow(2, $attempts), 30000);
                    usleep($delay * 1000);
                    continue;
                }
                throw new RuntimeException("Rate limited after $maxRetries attempts");
            }

            if ($httpCode >= 400) {
                $message = $decoded['message'] ?? $response;
                throw new RuntimeException("API error ($httpCode): $message");
            }

            return $decoded ?? [];
        }

        throw new RuntimeException("Request failed after $maxRetries attempts");
    }

    /**
     * Query subscribers with a SQL expression
     */
    public function querySubscribers(string $query, int $page = 1, int $perPage = 100): array
    {
        $params = http_build_query([
            'page' => $page,
            'per_page' => $perPage,
            'query' => $query
        ]);

        return $this->request('GET', "subscribers?$params");
    }

    /**
     * Get a single subscriber by ID
     */
    public function getSubscriber(int $id): array
    {
        return $this->request('GET', "subscribers/$id");
    }

    /**
     * Update subscriber attributes
     *
     * @deprecated FA-16: Attribute updates are moving to SQLite.
     * This method is only used by legacy mode. New code should update SQLite directly.
     * NOTE: This still works but should be avoided for drip/dunning state.
     */
    public function updateSubscriber(int $id, array $attribs): bool
    {
        // Get current subscriber data
        $subscriber = $this->getSubscriber($id);
        $data = $subscriber['data'] ?? [];
        $currentAttribs = $data['attribs'] ?? [];

        // Merge new attributes with existing ones
        $mergedAttribs = array_merge($currentAttribs, $attribs);

        // Preserve list IDs - extract from current lists
        $listIds = [];
        foreach ($data['lists'] ?? [] as $list) {
            if (isset($list['id'])) {
                $listIds[] = $list['id'];
            }
        }

        // Update subscriber - include lists to preserve them
        // NOTE: preconfirm_subscriptions=false preserves 'unconfirmed' status.
        // Our patched Listmonk only sends opt-in emails for NEW subscriptions,
        // not existing ones, so this won't spam subscribers.
        $this->request('PUT', "subscribers/$id", [
            'email' => $data['email'],
            'name' => $data['name'] ?? '',
            'status' => $data['status'] ?? 'enabled',
            'attribs' => $mergedAttribs,
            'lists' => $listIds,
            'preconfirm_subscriptions' => false
        ]);

        return true;
    }

    /**
     * Send a transactional email
     *
     * @param string $email Recipient email address
     * @param int $templateId Listmonk template ID
     * @param array $data Template variables
     * @return bool Success status
     */
    public function sendTx(string $email, int $templateId, array $data = []): bool
    {
        try {
            $payload = [
                'subscriber_email' => $email,
                'template_id' => $templateId,
                'data' => $data,
                'content_type' => 'html',
            ];

            $this->request('POST', 'tx', $payload);
            return true;
        } catch (Exception $e) {
            throw $e; // Re-throw so caller can handle
        }
    }

    /**
     * Add a small delay between API calls (rate limiting protection)
     */
    public function delay(int $milliseconds = 100): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Test API connection
     */
    public function testConnection(): bool
    {
        try {
            $this->request('GET', 'health');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get list info by ID (cached)
     */
    public function getList(int $listId): ?array
    {
        // Return from cache if available
        if (isset($this->listCache[$listId])) {
            return $this->listCache[$listId];
        }

        try {
            $result = $this->request('GET', "lists/$listId");
            $listData = $result['data'] ?? null;

            // Cache the result
            if ($listData) {
                $this->listCache[$listId] = $listData;
            }

            return $listData;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if a list is double opt-in
     */
    public function isDoubleOptIn(int $listId): bool
    {
        $list = $this->getList($listId);
        return ($list['optin'] ?? 'single') === 'double';
    }

    /**
     * Add subscribers to a list
     *
     * @param array $subscriberIds Array of subscriber IDs
     * @param int $listId List ID to add them to
     * @return array API response
     */
    public function addSubscribersToList(array $subscriberIds, int $listId): array
    {
        return $this->request('PUT', 'subscribers/lists', [
            'ids' => $subscriberIds,
            'action' => 'add',
            'target_list_ids' => [$listId]
        ]);
    }

    /**
     * Set subscriber status (enabled, blocklisted, disabled)
     *
     * @param int $id Subscriber ID
     * @param string $status New status: 'enabled', 'blocklisted', or 'disabled'
     * @return bool Success status
     */
    public function setSubscriberStatus(int $id, string $status): bool
    {
        $validStatuses = ['enabled', 'blocklisted', 'disabled'];
        if (!in_array($status, $validStatuses)) {
            throw new InvalidArgumentException("Invalid status: $status. Must be one of: " . implode(', ', $validStatuses));
        }

        // Get current subscriber data
        $subscriber = $this->getSubscriber($id);
        $data = $subscriber['data'] ?? [];

        // Preserve list IDs - extract from current lists
        $listIds = [];
        foreach ($data['lists'] ?? [] as $list) {
            if (isset($list['id'])) {
                $listIds[] = $list['id'];
            }
        }

        // Update subscriber with new status - preserve everything else
        // NOTE: preconfirm_subscriptions=false preserves 'unconfirmed' status.
        // Our patched Listmonk only sends opt-in emails for NEW subscriptions.
        $this->request('PUT', "subscribers/$id", [
            'email' => $data['email'],
            'name' => $data['name'] ?? '',
            'status' => $status,
            'attribs' => $data['attribs'] ?? [],
            'lists' => $listIds,
            'preconfirm_subscriptions' => false
        ]);

        return true;
    }

    /**
     * Get all lists from Listmonk
     *
     * @return array List of list objects
     */
    public function getLists(): array
    {
        $result = $this->request('GET', 'lists?per_page=100');
        return $result['data']['results'] ?? [];
    }

    /**
     * Resend opt-in confirmation email to a subscriber
     *
     * Uses Listmonk's built-in endpoint to resend the double opt-in confirmation.
     *
     * @param int $subscriberId Subscriber ID
     * @return bool Success status
     */
    public function resendOptinConfirmation(int $subscriberId): bool
    {
        try {
            $result = $this->request('POST', "subscribers/$subscriberId/optin", []);
            return ($result['data'] ?? false) === true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get all confirmed subscriber IDs for given DOI lists (batch query)
     *
     * This is much faster than checking each subscriber individually.
     * Uses Listmonk's query API to fetch confirmed subscribers in batches.
     *
     * @param array $listIds List IDs to check
     * @return array Map of listmonk_id => [list_ids where confirmed]
     */
    public function getConfirmedSubscribersForLists(array $listIds): array
    {
        if (empty($listIds)) {
            return [];
        }

        $confirmed = [];

        // Query each list for confirmed subscribers
        foreach ($listIds as $listId) {
            $page = 1;
            $perPage = 500; // Larger batches for efficiency

            do {
                // Query subscribers on this list
                $query = "subscribers.id IN (SELECT subscriber_id FROM subscriber_lists WHERE list_id = $listId AND status = 'confirmed')";
                $result = $this->querySubscribers($query, $page, $perPage);

                $subscribers = $result['data']['results'] ?? [];

                foreach ($subscribers as $sub) {
                    $id = $sub['id'];
                    if (!isset($confirmed[$id])) {
                        $confirmed[$id] = [];
                    }
                    $confirmed[$id][] = $listId;
                }

                $page++;
                $this->delay(50); // Small delay between pages

            } while (count($subscribers) === $perPage);
        }

        return $confirmed;
    }

    /**
     * Get all unconfirmed subscriber IDs for given DOI lists (batch query)
     *
     * @param array $listIds List IDs to check
     * @return array Map of listmonk_id => [list_ids where unconfirmed]
     */
    public function getUnconfirmedSubscribersForLists(array $listIds): array
    {
        if (empty($listIds)) {
            return [];
        }

        $unconfirmed = [];

        // Query each list for unconfirmed subscribers
        foreach ($listIds as $listId) {
            $page = 1;
            $perPage = 500;

            do {
                $query = "subscribers.id IN (SELECT subscriber_id FROM subscriber_lists WHERE list_id = $listId AND status = 'unconfirmed')";
                $result = $this->querySubscribers($query, $page, $perPage);

                $subscribers = $result['data']['results'] ?? [];

                foreach ($subscribers as $sub) {
                    $id = $sub['id'];
                    if (!isset($unconfirmed[$id])) {
                        $unconfirmed[$id] = [];
                    }
                    $unconfirmed[$id][] = $listId;
                }

                $page++;
                $this->delay(50);

            } while (count($subscribers) === $perPage);
        }

        return $unconfirmed;
    }
}
