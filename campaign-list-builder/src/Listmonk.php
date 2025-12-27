<?php
/**
 * Listmonk API Client
 *
 * Handles all communication with the Listmonk API
 */

class Listmonk
{
    private string $baseUrl;
    private string $user;
    private string $pass;
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
     * Make an API request to Listmonk
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $url = $this->baseUrl . '/api/' . ltrim($endpoint, '/');

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
            throw new RuntimeException("Curl error: $error");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? $response;
            throw new RuntimeException("API error ($httpCode): $message");
        }

        return $decoded ?? [];
    }

    /**
     * Get subscribers matching a query with pagination
     */
    public function getSubscribers(string $query = '', int $page = 1, int $perPage = 50): array
    {
        $params = http_build_query([
            'page' => $page,
            'per_page' => $perPage,
            'query' => $query
        ]);

        return $this->request('GET', "subscribers?$params");
    }

    /**
     * Get all subscriber IDs matching a query (handles pagination)
     */
    public function getAllSubscriberIds(string $query = ''): array
    {
        $ids = [];
        $page = 1;
        $perPage = 1000;

        do {
            $response = $this->getSubscribers($query, $page, $perPage);
            $results = $response['data']['results'] ?? [];

            foreach ($results as $subscriber) {
                $ids[] = $subscriber['id'];
            }

            $total = $response['data']['total'] ?? 0;
            $page++;
        } while (count($ids) < $total);

        return $ids;
    }

    /**
     * Get all lists
     */
    public function getLists(): array
    {
        $response = $this->request('GET', 'lists?per_page=1000');
        return $response['data']['results'] ?? [];
    }

    /**
     * Get campaign lists (lists with campaign- prefix or campaign tag)
     */
    public function getCampaignLists(): array
    {
        $lists = $this->getLists();
        return array_filter($lists, function ($list) {
            return str_starts_with($list['name'], 'campaign-')
                || in_array('campaign', $list['tags'] ?? []);
        });
    }

    /**
     * Create a new list
     */
    public function createList(string $name, string $type = 'private', string $optin = 'single', array $tags = ['campaign']): array
    {
        return $this->request('POST', 'lists', [
            'name' => $name,
            'type' => $type,
            'optin' => $optin,
            'tags' => $tags
        ]);
    }

    /**
     * Delete a list
     */
    public function deleteList(int $listId): array
    {
        return $this->request('DELETE', "lists/$listId");
    }

    /**
     * Add subscribers to a list
     */
    public function addSubscribersToList(array $subscriberIds, int $listId): array
    {
        if (empty($subscriberIds)) {
            return ['message' => 'No subscribers to add'];
        }

        return $this->request('PUT', 'subscribers/lists', [
            'ids' => $subscriberIds,
            'action' => 'add',
            'target_list_ids' => [$listId]
        ]);
    }

    /**
     * Remove subscribers from a list
     */
    public function removeSubscribersFromList(array $subscriberIds, int $listId): array
    {
        if (empty($subscriberIds)) {
            return ['message' => 'No subscribers to remove'];
        }

        return $this->request('PUT', 'subscribers/lists', [
            'ids' => $subscriberIds,
            'action' => 'remove',
            'target_list_ids' => [$listId]
        ]);
    }

    /**
     * Get subscribers in a specific list
     */
    public function getListSubscriberIds(int $listId): array
    {
        $query = "subscribers.id IN (SELECT subscriber_id FROM subscriber_lists WHERE list_id = $listId)";
        return $this->getAllSubscriberIds($query);
    }

    /**
     * Replace all subscribers in a list with new ones
     */
    public function replaceListSubscribers(int $listId, array $newSubscriberIds): array
    {
        // Get current subscribers
        $currentIds = $this->getListSubscriberIds($listId);

        // Remove all current subscribers
        if (!empty($currentIds)) {
            $this->removeSubscribersFromList($currentIds, $listId);
        }

        // Add new subscribers
        if (!empty($newSubscriberIds)) {
            return $this->addSubscribersToList($newSubscriberIds, $listId);
        }

        return ['message' => 'List updated successfully'];
    }

    /**
     * Get a single list by ID (cached)
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
     * Get the opt-in type for a list ('single' or 'double')
     */
    public function getListOptinType(int $listId): string
    {
        $list = $this->getList($listId);
        return $list['optin'] ?? 'single';
    }

    /**
     * Get a single subscriber by ID (full data including lists)
     */
    public function getSubscriberById(int $subscriberId): ?array
    {
        try {
            $result = $this->request('GET', "subscribers/$subscriberId");
            return $result['data'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Find a subscriber by email address
     * Returns subscriber data if found, null otherwise
     */
    public function findSubscriberByEmail(string $email): ?array
    {
        $query = "subscribers.email = '" . addslashes($email) . "'";
        $result = $this->getSubscribers($query, 1, 1);
        $subscribers = $result['data']['results'] ?? [];

        if (empty($subscribers)) {
            return null;
        }

        return $subscribers[0];
    }

    /**
     * Create a new subscriber
     *
     * @param string $email Subscriber email
     * @param string $name Subscriber name
     * @param array $attribs Subscriber attributes
     * @param array $listIds List IDs to add subscriber to
     * @param string $status Status: enabled, disabled, blocklisted
     * @return array Created subscriber data
     */
    public function createSubscriber(string $email, string $name = '', array $attribs = [], array $listIds = [], string $status = 'enabled'): array
    {
        $payload = [
            'email' => $email,
            'name' => $name,
            'status' => $status,
            'attribs' => $attribs,
            'lists' => $listIds
        ];

        $result = $this->request('POST', 'subscribers', $payload);
        return $result['data'] ?? [];
    }

    /**
     * Update subscriber with merged attributes
     *
     * @param int $id Subscriber ID
     * @param array $attribs New attributes (merged with existing)
     * @param string|null $name Optional new name
     * @return bool Success status
     */
    public function updateSubscriber(int $id, array $attribs, ?string $name = null): bool
    {
        // Get current subscriber data
        $subscriber = $this->getSubscriberById($id);
        if (!$subscriber) {
            return false;
        }

        $currentAttribs = $subscriber['attribs'] ?? [];

        // Merge new attributes with existing ones (new values win)
        $mergedAttribs = array_merge($currentAttribs, $attribs);

        // Preserve list IDs - extract from current lists
        $listIds = [];
        foreach ($subscriber['lists'] ?? [] as $list) {
            if (isset($list['id'])) {
                $listIds[] = $list['id'];
            }
        }

        // Update subscriber - include lists to preserve them
        $this->request('PUT', "subscribers/$id", [
            'email' => $subscriber['email'],
            'name' => $name ?? ($subscriber['name'] ?? ''),
            'status' => $subscriber['status'] ?? 'enabled',
            'attribs' => $mergedAttribs,
            'lists' => $listIds
        ]);

        return true;
    }

    /**
     * Check if subscriber is a member of a specific list
     *
     * @param array $subscriber Subscriber data with 'lists' array
     * @param int $listId List ID to check
     * @return bool True if member
     */
    public function isSubscriberInList(array $subscriber, int $listId): bool
    {
        foreach ($subscriber['lists'] ?? [] as $list) {
            if (($list['id'] ?? 0) === $listId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get subscriber bounces
     */
    public function getSubscriberBounces(int $subscriberId): array
    {
        try {
            $result = $this->request('GET', "subscribers/$subscriberId/bounces");
            return $result['data'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get a template by ID
     */
    public function getTemplate(int $templateId): ?array
    {
        try {
            $result = $this->request('GET', "templates/$templateId");
            return $result['data'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get all templates
     */
    public function getTemplates(): array
    {
        try {
            $result = $this->request('GET', 'templates');
            return $result['data'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get IDs of subscribers who have marketing_allowed = true
     * Used by segment builder to filter SQLite results
     */
    public function getMarketingAllowedIds(array $listmonkIds): array
    {
        if (empty($listmonkIds)) {
            return [];
        }

        $allowedIds = [];
        $batchSize = 100;
        $batches = array_chunk($listmonkIds, $batchSize);

        foreach ($batches as $batch) {
            // Build query for subscriber IDs
            $idList = implode(',', array_map('intval', $batch));
            $query = "subscribers.id IN ($idList) AND subscribers.attribs->>'marketing_allowed' = 'true'";

            $page = 1;
            do {
                $result = $this->getSubscribers($query, $page, 100);
                $subscribers = $result['data']['results'] ?? [];

                foreach ($subscribers as $sub) {
                    $allowedIds[] = $sub['id'];
                }

                $page++;
                $hasMore = count($subscribers) === 100;
            } while ($hasMore);
        }

        return $allowedIds;
    }

    /**
     * Get names for a list of subscriber IDs
     * Returns: [listmonk_id => ['name' => name], ...]
     */
    public function getSubscriberNames(array $listmonkIds): array
    {
        if (empty($listmonkIds)) {
            return [];
        }

        $result = [];
        $batchSize = 100;
        $batches = array_chunk($listmonkIds, $batchSize);

        foreach ($batches as $batch) {
            $idList = implode(',', array_map('intval', $batch));
            $query = "subscribers.id IN ($idList)";

            $page = 1;
            do {
                $response = $this->getSubscribers($query, $page, 100);
                $subscribers = $response['data']['results'] ?? [];

                foreach ($subscribers as $sub) {
                    $result[$sub['id']] = [
                        'name' => $sub['name'] ?? '',
                        'email' => $sub['email'] ?? ''
                    ];
                }

                $page++;
                $hasMore = count($subscribers) === 100;
            } while ($hasMore);
        }

        return $result;
    }
}
