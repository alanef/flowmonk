<?php
/**
 * Sequence Database
 *
 * SQLite-based storage for drip sequence configuration.
 * Shared between campaign-list-builder (UI) and drip-controller (processor).
 */

class SequenceDatabase
{
    private PDO $db;
    private string $dbPath;

    public function __construct(string $dataDir = '/var/www/html/data')
    {
        $this->dbPath = rtrim($dataDir, '/') . '/drip-config.db';

        // Ensure directory exists
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Connect to SQLite
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Initialize tables
        $this->initializeTables();
    }

    /**
     * Create tables if they don't exist
     */
    private function initializeTables(): void
    {
        // Migration: Rename plugins table to products (FA-11)
        try {
            $this->db->exec("ALTER TABLE plugins RENAME TO products");
        } catch (PDOException $e) {
            // Table already renamed or doesn't exist yet
        }

        // Products table (formerly plugins)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS products (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                list_id INTEGER,
                enabled INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Add list_id column if missing (for existing databases)
        try {
            $this->db->exec("ALTER TABLE products ADD COLUMN list_id INTEGER");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Add type column if missing (freemius, freelib, other)
        try {
            $this->db->exec("ALTER TABLE products ADD COLUMN type TEXT DEFAULT 'freemius'");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Add hmac_secret column if missing (for freemius webhook verification)
        try {
            $this->db->exec("ALTER TABLE products ADD COLUMN hmac_secret TEXT");
        } catch (PDOException $e) {
            // Column already exists, ignore
        }

        // Migration: set known freelib products to type 'freelib'
        $this->db->exec("
            UPDATE products SET type = 'freelib'
            WHERE id IN ('swegts', 'ssfgm', 'lhf', 'sue', 'rshfd', 'faum', 'fss', 'mmt', 'cscf')
            AND (type IS NULL OR type = 'freemius')
        ");

        // List stats history table for tracking deltas
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS list_stats_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                list_id INTEGER NOT NULL,
                recorded_at DATE NOT NULL,
                total INTEGER DEFAULT 0,
                confirmed INTEGER DEFAULT 0,
                unconfirmed INTEGER DEFAULT 0,
                unsubscribed INTEGER DEFAULT 0,
                UNIQUE(list_id, recorded_at)
            )
        ");

        // Sequences table (plugin_id column name kept for backward compatibility)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sequences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plugin_id TEXT NOT NULL,
                type TEXT NOT NULL,
                stage TEXT NOT NULL,
                template_id INTEGER NOT NULL,
                delay_days INTEGER NOT NULL DEFAULT 0,
                subject TEXT NOT NULL,
                step_order INTEGER NOT NULL,
                enabled INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (plugin_id) REFERENCES products(id),
                UNIQUE(plugin_id, stage)
            )
        ");

        // FA-12: Sequence types table for dynamic/configurable sequence types per product
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sequence_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id TEXT NOT NULL,
                type_name TEXT NOT NULL,
                display_name TEXT NOT NULL,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id),
                UNIQUE(product_id, type_name)
            )
        ");

        // FA-12: Auto-populate sequence_types from existing sequences data
        $this->db->exec("
            INSERT OR IGNORE INTO sequence_types (product_id, type_name, display_name, sort_order)
            SELECT DISTINCT plugin_id, type,
                   UPPER(SUBSTR(type, 1, 1)) || SUBSTR(type, 2),
                   CASE type WHEN 'free' THEN 1 WHEN 'trial' THEN 2 WHEN 'premium' THEN 3 ELSE 4 END
            FROM sequences WHERE type IS NOT NULL AND type != ''
        ");

        // FA-16: Subscribers table (links email to Listmonk subscriber ID)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS subscribers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                listmonk_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // FA-16: Drip state per subscriber per product
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS subscriber_drips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subscriber_id INTEGER NOT NULL,
                product_id TEXT NOT NULL,
                status TEXT NOT NULL,
                stage TEXT,
                next_send TEXT,
                failures INTEGER DEFAULT 0,
                started_at TEXT,
                last_event TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(subscriber_id, product_id),
                FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
                FOREIGN KEY (product_id) REFERENCES products(id)
            )
        ");

        // FA-16: Dunning state for DOI confirmation tracking
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS subscriber_dunning (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subscriber_id INTEGER NOT NULL,
                list_id INTEGER NOT NULL,
                stage TEXT NOT NULL,
                next_reminder TEXT,
                started_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(subscriber_id, list_id),
                FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
            )
        ");

        // FA-16: Create indexes for efficient querying
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_subscriber_drips_next_send ON subscriber_drips(next_send)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_subscriber_drips_status ON subscriber_drips(status, stage)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_subscriber_dunning_next ON subscriber_dunning(next_reminder)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_listmonk_id ON subscribers(listmonk_id)");

        // Seed default data if tables are empty
        $count = $this->db->query("SELECT COUNT(*) FROM products")->fetchColumn();
        if ($count == 0) {
            $this->seedDefaultData();
        }
    }

    /**
     * Seed default products and sequences
     */
    private function seedDefaultData(): void
    {
        // Default products
        $products = [
            ['1330', 'Display Eventbrite'],
            ['5065', 'Fullworks Anti-Spam'],
            ['5623', 'Quick PayPal Payments'],
        ];

        $stmt = $this->db->prepare("INSERT INTO products (id, name) VALUES (?, ?)");
        foreach ($products as $product) {
            $stmt->execute($product);
        }

        // Default sequences
        $sequences = [
            // Display Eventbrite (1330)
            ['1330', 'free', 'free_1', 1, 0, 'Welcome to Display Eventbrite', 1],
            ['1330', 'free', 'free_2', 2, 3, 'Getting the most from Display Eventbrite', 2],
            ['1330', 'free', 'free_3', 3, 7, 'Pro tips for Display Eventbrite', 3],
            ['1330', 'free', 'free_4', 4, 30, 'Unlock premium features', 4],
            ['1330', 'trial', 'trial_1', 5, 0, 'Your trial has started', 1],
            ['1330', 'trial', 'trial_2', 6, 3, 'Making the most of your trial', 2],
            ['1330', 'trial', 'trial_3', 7, 7, 'Trial ending soon', 3],
            ['1330', 'premium', 'premium_1', 8, 0, 'Thank you for your purchase', 1],

            // Anti-Spam (5065)
            ['5065', 'free', 'free_1', 10, 0, 'Welcome to Fullworks Anti-Spam', 1],
            ['5065', 'free', 'free_2', 11, 3, 'Protecting your site from spam', 2],
            ['5065', 'free', 'free_3', 12, 7, 'Advanced spam protection tips', 3],
            ['5065', 'free', 'free_4', 13, 30, 'Unlock premium protection', 4],
            ['5065', 'trial', 'trial_1', 14, 0, 'Your Anti-Spam trial has started', 1],
            ['5065', 'trial', 'trial_2', 15, 3, 'Getting the most from your trial', 2],
            ['5065', 'trial', 'trial_3', 16, 7, 'Trial ending soon', 3],
            ['5065', 'premium', 'premium_1', 17, 0, 'Thank you for upgrading', 1],

            // QPP (5623)
            ['5623', 'free', 'free_1', 20, 0, 'Welcome to Quick PayPal Payments', 1],
            ['5623', 'free', 'free_2', 21, 3, 'Setting up your first payment button', 2],
            ['5623', 'free', 'free_3', 22, 7, 'Tips for increasing conversions', 3],
            ['5623', 'free', 'free_4', 23, 30, 'Unlock premium features', 4],
            ['5623', 'trial', 'trial_1', 24, 0, 'Your QPP trial has started', 1],
            ['5623', 'trial', 'trial_2', 25, 3, 'Making the most of your trial', 2],
            ['5623', 'trial', 'trial_3', 26, 7, 'Trial ending soon', 3],
            ['5623', 'premium', 'premium_1', 27, 0, 'Thank you for your purchase', 1],
        ];

        $stmt = $this->db->prepare("
            INSERT INTO sequences (plugin_id, type, stage, template_id, delay_days, subject, step_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($sequences as $seq) {
            $stmt->execute($seq);
        }
    }

    // =========================================================================
    // Product Methods
    // =========================================================================

    /**
     * Get all products
     */
    public function getProducts(): array
    {
        return $this->db->query("SELECT * FROM products ORDER BY name")->fetchAll();
    }

    /**
     * Get a single product by ID
     */
    public function getProduct(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Add or update a product
     */
    public function saveProduct(string $id, string $name, bool $enabled = true, ?int $listId = null): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO products (id, name, list_id, enabled) VALUES (?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET name = excluded.name, list_id = excluded.list_id, enabled = excluded.enabled
        ");
        return $stmt->execute([$id, $name, $listId, $enabled ? 1 : 0]);
    }

    /**
     * Get list_id for a product
     */
    public function getProductListId(string $productId): ?int
    {
        $stmt = $this->db->prepare("SELECT list_id FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $result = $stmt->fetchColumn();
        return $result !== false && $result !== null ? (int)$result : null;
    }

    /**
     * Get all product list_id mappings
     * Returns: ['product_id' => list_id, ...]
     */
    public function getProductListIds(): array
    {
        $rows = $this->db->query("SELECT id, list_id FROM products WHERE enabled = 1")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            if ($row['list_id'] !== null) {
                $map[$row['id']] = (int)$row['list_id'];
            }
        }
        return $map;
    }

    /**
     * Get a product by ID with all fields including type and hmac_secret
     * Used by webhook handler for verification
     */
    public function getProductById(string $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get the type of a product (freemius, freelib, or other)
     */
    public function getProductType(string $productId): ?string
    {
        $stmt = $this->db->prepare("SELECT type FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Check if a product requires HMAC verification
     */
    public function requiresHmac(string $productId): bool
    {
        return $this->getProductType($productId) === 'freemius';
    }

    /**
     * Save product with type and hmac_secret
     */
    public function saveProductFull(
        string $id,
        string $name,
        bool $enabled = true,
        ?int $listId = null,
        string $type = 'freemius',
        ?string $hmacSecret = null
    ): bool {
        $stmt = $this->db->prepare("
            INSERT INTO products (id, name, list_id, enabled, type, hmac_secret)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                list_id = excluded.list_id,
                enabled = excluded.enabled,
                type = excluded.type,
                hmac_secret = excluded.hmac_secret
        ");
        return $stmt->execute([$id, $name, $listId, $enabled ? 1 : 0, $type, $hmacSecret]);
    }

    /**
     * Delete a product and its sequences and sequence types
     */
    public function deleteProduct(string $id): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM sequences WHERE plugin_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM sequence_types WHERE product_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // =========================================================================
    // Sequence Type Methods (FA-12)
    // =========================================================================

    /**
     * Get all sequence types for a product
     */
    public function getSequenceTypes(string $productId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM sequence_types
            WHERE product_id = ?
            ORDER BY sort_order, type_name
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * Add a new sequence type for a product
     */
    public function addSequenceType(string $productId, string $typeName, string $displayName, int $sortOrder = 0): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO sequence_types (product_id, type_name, display_name, sort_order)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$productId, $typeName, $displayName, $sortOrder]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an existing sequence type
     */
    public function updateSequenceType(int $id, string $displayName, int $sortOrder): bool
    {
        $stmt = $this->db->prepare("
            UPDATE sequence_types SET display_name = ?, sort_order = ?
            WHERE id = ?
        ");
        return $stmt->execute([$displayName, $sortOrder, $id]);
    }

    /**
     * Delete a sequence type and its associated sequences
     */
    public function deleteSequenceType(string $productId, string $typeName): bool
    {
        $this->db->beginTransaction();
        try {
            // Delete associated sequences first
            $stmt = $this->db->prepare("DELETE FROM sequences WHERE plugin_id = ? AND type = ?");
            $stmt->execute([$productId, $typeName]);

            // Delete the type itself
            $stmt = $this->db->prepare("DELETE FROM sequence_types WHERE product_id = ? AND type_name = ?");
            $stmt->execute([$productId, $typeName]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get next sort order for a product's sequence types
     */
    public function getNextSequenceTypeSortOrder(string $productId): int
    {
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM sequence_types WHERE product_id = ?");
        $stmt->execute([$productId]);
        $max = $stmt->fetchColumn();
        return ($max ?? 0) + 1;
    }

    // =========================================================================
    // Sequence Methods
    // =========================================================================

    /**
     * Get all sequences, optionally filtered by plugin
     * Sorted by sequence_types.sort_order to preserve type ordering (free, trial, premium)
     */
    public function getSequences(?string $pluginId = null): array
    {
        if ($pluginId) {
            $stmt = $this->db->prepare("
                SELECT s.* FROM sequences s
                LEFT JOIN sequence_types st ON s.plugin_id = st.product_id AND s.type = st.type_name
                WHERE s.plugin_id = ?
                ORDER BY COALESCE(st.sort_order, 999), s.step_order
            ");
            $stmt->execute([$pluginId]);
            return $stmt->fetchAll();
        }

        return $this->db->query("
            SELECT s.* FROM sequences s
            LEFT JOIN sequence_types st ON s.plugin_id = st.product_id AND s.type = st.type_name
            ORDER BY s.plugin_id, COALESCE(st.sort_order, 999), s.step_order
        ")->fetchAll();
    }

    /**
     * Get sequences for a product grouped by type (FA-12: uses dynamic types)
     */
    public function getSequencesByType(string $productId): array
    {
        // Get all dynamic types for this product
        $types = $this->getSequenceTypes($productId);

        // Initialize grouped array with all defined types
        $grouped = [];
        foreach ($types as $type) {
            $grouped[$type['type_name']] = [];
        }

        // Populate with sequences
        $sequences = $this->getSequences($productId);
        foreach ($sequences as $seq) {
            // Ensure the type key exists (handles orphaned sequences)
            if (!isset($grouped[$seq['type']])) {
                $grouped[$seq['type']] = [];
            }
            $grouped[$seq['type']][] = $seq;
        }

        return $grouped;
    }

    /**
     * Get a single sequence step by ID
     */
    public function getSequenceStep(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sequences WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Add or update a sequence step
     */
    public function saveSequenceStep(array $data): int
    {
        if (!empty($data['id'])) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE sequences SET
                    plugin_id = ?,
                    type = ?,
                    stage = ?,
                    template_id = ?,
                    delay_days = ?,
                    subject = ?,
                    step_order = ?,
                    enabled = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['plugin_id'],
                $data['type'],
                $data['stage'],
                $data['template_id'],
                $data['delay_days'],
                $data['subject'],
                $data['step_order'],
                $data['enabled'] ?? 1,
                $data['id']
            ]);
            return $data['id'];
        } else {
            // Insert new
            $stmt = $this->db->prepare("
                INSERT INTO sequences (plugin_id, type, stage, template_id, delay_days, subject, step_order, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['plugin_id'],
                $data['type'],
                $data['stage'],
                $data['template_id'],
                $data['delay_days'],
                $data['subject'],
                $data['step_order'],
                $data['enabled'] ?? 1
            ]);
            return (int)$this->db->lastInsertId();
        }
    }

    /**
     * Delete a sequence step
     */
    public function deleteSequenceStep(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sequences WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Reorder sequence steps
     */
    public function reorderSteps(string $pluginId, string $type, array $stepIds): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE sequences SET step_order = ? WHERE id = ?");
            foreach ($stepIds as $order => $id) {
                $stmt->execute([$order + 1, $id]);
            }
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Get next step order for a plugin/type
     */
    public function getNextStepOrder(string $pluginId, string $type): int
    {
        $stmt = $this->db->prepare("
            SELECT MAX(step_order) FROM sequences WHERE plugin_id = ? AND type = ?
        ");
        $stmt->execute([$pluginId, $type]);
        $max = $stmt->fetchColumn();
        return ($max ?? 0) + 1;
    }

    // =========================================================================
    // Export Methods (for drip-controller compatibility)
    // =========================================================================

    /**
     * Get all sequences in the format expected by SequenceManager
     * Returns: ['product_id' => ['type' => [steps...]]]
     * FA-12: Now uses dynamic sequence types instead of hardcoded types
     */
    public function getSequencesAsConfig(): array
    {
        $config = [];
        $products = $this->getProducts();

        foreach ($products as $product) {
            if (!$product['enabled']) {
                continue;
            }

            $config[$product['id']] = [];
            $sequences = $this->getSequencesByType($product['id']);

            // Iterate over all dynamic types from the grouped sequences
            foreach ($sequences as $type => $steps) {
                if (!empty($steps)) {
                    $config[$product['id']][$type] = array_map(function ($step) {
                        return [
                            'stage' => $step['stage'],
                            'template_id' => (int)$step['template_id'],
                            'delay_days' => (int)$step['delay_days'],
                            'subject' => $step['subject'],
                        ];
                    }, array_filter($steps, fn($s) => $s['enabled']));
                }
            }
        }

        return $config;
    }

    /**
     * Get all enabled product IDs
     */
    public function getEnabledProductIds(): array
    {
        return array_column(
            $this->db->query("SELECT id FROM products WHERE enabled = 1")->fetchAll(),
            'id'
        );
    }

    // =========================================================================
    // List Stats History Methods
    // =========================================================================

    /**
     * Record a snapshot of list stats for today
     * Uses INSERT OR REPLACE to update if already recorded today
     */
    public function recordListStats(int $listId, int $total, int $confirmed, int $unconfirmed, int $unsubscribed): bool
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO list_stats_history
            (list_id, recorded_at, total, confirmed, unconfirmed, unsubscribed)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$listId, $today, $total, $confirmed, $unconfirmed, $unsubscribed]);
    }

    /**
     * Check if we have a snapshot for today for a given list
     */
    public function hasSnapshotToday(int $listId): bool
    {
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT 1 FROM list_stats_history WHERE list_id = ? AND recorded_at = ?");
        $stmt->execute([$listId, $today]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get list stats from N days ago
     */
    public function getListStatsFromDaysAgo(int $listId, int $daysAgo): ?array
    {
        $targetDate = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $stmt = $this->db->prepare("
            SELECT * FROM list_stats_history
            WHERE list_id = ? AND recorded_at <= ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$listId, $targetDate]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get delta stats for a list (comparing current to past)
     * Returns: ['week' => delta, 'month' => delta] for confirmed count
     */
    public function getListStatsDelta(int $listId, int $currentConfirmed): array
    {
        $weekAgo = $this->getListStatsFromDaysAgo($listId, 7);
        $monthAgo = $this->getListStatsFromDaysAgo($listId, 30);

        return [
            'week' => $weekAgo ? $currentConfirmed - ($weekAgo['confirmed'] ?? 0) : null,
            'month' => $monthAgo ? $currentConfirmed - ($monthAgo['confirmed'] ?? 0) : null,
        ];
    }

    /**
     * Clean up old stats (keep last 90 days)
     */
    public function cleanupOldStats(): int
    {
        $cutoff = date('Y-m-d', strtotime('-90 days'));
        $stmt = $this->db->prepare("DELETE FROM list_stats_history WHERE recorded_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    // =========================================================================
    // FA-16: Subscriber Methods
    // =========================================================================

    /**
     * Get or create a subscriber by email
     * Returns the subscriber record (creates if doesn't exist)
     */
    public function getOrCreateSubscriber(string $email, ?int $listmonkId = null): array
    {
        $email = strtolower(trim($email));

        $stmt = $this->db->prepare("SELECT * FROM subscribers WHERE email = ?");
        $stmt->execute([$email]);
        $subscriber = $stmt->fetch();

        if ($subscriber) {
            // Update listmonk_id if provided and different
            if ($listmonkId !== null && $subscriber['listmonk_id'] != $listmonkId) {
                $this->updateSubscriberListmonkId($subscriber['id'], $listmonkId);
                $subscriber['listmonk_id'] = $listmonkId;
            }
            return $subscriber;
        }

        // Create new subscriber
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            INSERT INTO subscribers (email, listmonk_id, created_at, updated_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$email, $listmonkId, $now, $now]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'email' => $email,
            'listmonk_id' => $listmonkId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Get subscriber by email
     */
    public function getSubscriberByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM subscribers WHERE email = ?");
        $stmt->execute([strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get subscriber by ID
     */
    public function getSubscriberById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM subscribers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get subscriber by Listmonk ID
     */
    public function getSubscriberByListmonkId(int $listmonkId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM subscribers WHERE listmonk_id = ?");
        $stmt->execute([$listmonkId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update subscriber's Listmonk ID
     */
    public function updateSubscriberListmonkId(int $subscriberId, int $listmonkId): bool
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("UPDATE subscribers SET listmonk_id = ?, updated_at = ? WHERE id = ?");
        return $stmt->execute([$listmonkId, $now, $subscriberId]);
    }

    // =========================================================================
    // FA-16: Subscriber Drip Methods
    // =========================================================================

    /**
     * Get or create drip state for a subscriber/product
     */
    public function getOrCreateDrip(int $subscriberId, string $productId, string $status): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscriber_drips
            WHERE subscriber_id = ? AND product_id = ?
        ");
        $stmt->execute([$subscriberId, $productId]);
        $drip = $stmt->fetch();

        if ($drip) {
            return $drip;
        }

        // Create new drip record
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            INSERT INTO subscriber_drips
            (subscriber_id, product_id, status, stage, next_send, failures, started_at, is_active, created_at, updated_at)
            VALUES (?, ?, ?, NULL, NULL, 0, ?, 1, ?, ?)
        ");
        $stmt->execute([$subscriberId, $productId, $status, $now, $now, $now]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'subscriber_id' => $subscriberId,
            'product_id' => $productId,
            'status' => $status,
            'stage' => null,
            'next_send' => null,
            'failures' => 0,
            'started_at' => $now,
            'last_event' => null,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Get drip state for a subscriber/product
     */
    public function getDrip(int $subscriberId, string $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscriber_drips
            WHERE subscriber_id = ? AND product_id = ?
        ");
        $stmt->execute([$subscriberId, $productId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get drip state by ID
     */
    public function getDripById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM subscriber_drips WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update drip state
     */
    public function updateDrip(int $dripId, array $updates): bool
    {
        $allowedFields = ['status', 'stage', 'next_send', 'failures', 'last_event', 'is_active', 'started_at'];
        $setClauses = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setClauses[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = "updated_at = ?";
        $params[] = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $params[] = $dripId;

        $sql = "UPDATE subscriber_drips SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get all drips due for sending
     * Returns drips where next_send <= now and stage is an active drip stage
     */
    public function getDueDrips(): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            SELECT d.*, s.email, s.listmonk_id
            FROM subscriber_drips d
            JOIN subscribers s ON d.subscriber_id = s.id
            WHERE d.next_send IS NOT NULL
              AND d.next_send <= ?
              AND d.is_active = 1
              AND d.stage IS NOT NULL
              AND d.stage NOT IN ('complete', 'error', 'stopped', 'none', 'imported')
            ORDER BY d.next_send ASC
        ");
        $stmt->execute([$now]);
        return $stmt->fetchAll();
    }

    /**
     * Get drip queue (all pending/scheduled drips)
     * Used by UI to show upcoming emails
     */
    public function getDripQueue(?string $productId = null, int $limit = 100): array
    {
        $sql = "
            SELECT d.*, s.email, s.listmonk_id, p.name as product_name
            FROM subscriber_drips d
            JOIN subscribers s ON d.subscriber_id = s.id
            JOIN products p ON d.product_id = p.id
            WHERE d.next_send IS NOT NULL
              AND d.is_active = 1
              AND d.stage IS NOT NULL
              AND d.stage NOT IN ('complete', 'error', 'stopped', 'none', 'imported')
        ";
        $params = [];

        if ($productId) {
            $sql .= " AND d.product_id = ?";
            $params[] = $productId;
        }

        $sql .= " ORDER BY d.next_send ASC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get drip stats for products
     * Returns counts by product and status
     */
    public function getDripStats(): array
    {
        return $this->db->query("
            SELECT
                d.product_id,
                p.name as product_name,
                d.status,
                COUNT(*) as count,
                SUM(CASE WHEN d.stage IN ('complete', 'error', 'stopped') THEN 0 ELSE 1 END) as active_count
            FROM subscriber_drips d
            JOIN products p ON d.product_id = p.id
            GROUP BY d.product_id, d.status
            ORDER BY p.name, d.status
        ")->fetchAll();
    }

    /**
     * Initialize drip for a subscriber (first email in sequence)
     */
    public function initializeDrip(int $subscriberId, string $productId, string $status, string $firstStage, ?string $nextSend): bool
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare("
            INSERT INTO subscriber_drips
            (subscriber_id, product_id, status, stage, next_send, failures, started_at, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?, ?)
            ON CONFLICT(subscriber_id, product_id) DO UPDATE SET
                status = excluded.status,
                stage = excluded.stage,
                next_send = excluded.next_send,
                started_at = excluded.started_at,
                is_active = 1,
                updated_at = excluded.updated_at
        ");
        return $stmt->execute([$subscriberId, $productId, $status, $firstStage, $nextSend, $now, $now, $now]);
    }

    // =========================================================================
    // FA-16: Subscriber Dunning Methods
    // =========================================================================

    /**
     * Get or create dunning state for a subscriber/list
     */
    public function getOrCreateDunning(int $subscriberId, int $listId, string $stage, string $nextReminder): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscriber_dunning
            WHERE subscriber_id = ? AND list_id = ?
        ");
        $stmt->execute([$subscriberId, $listId]);
        $dunning = $stmt->fetch();

        if ($dunning) {
            return $dunning;
        }

        // Create new dunning record
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            INSERT INTO subscriber_dunning
            (subscriber_id, list_id, stage, next_reminder, started_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$subscriberId, $listId, $stage, $nextReminder, $now, $now, $now]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'subscriber_id' => $subscriberId,
            'list_id' => $listId,
            'stage' => $stage,
            'next_reminder' => $nextReminder,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Get dunning state for a subscriber/list
     */
    public function getDunning(int $subscriberId, int $listId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscriber_dunning
            WHERE subscriber_id = ? AND list_id = ?
        ");
        $stmt->execute([$subscriberId, $listId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update dunning state
     */
    public function updateDunning(int $dunningId, array $updates): bool
    {
        $allowedFields = ['stage', 'next_reminder', 'started_at'];
        $setClauses = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setClauses[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = "updated_at = ?";
        $params[] = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $params[] = $dunningId;

        $sql = "UPDATE subscriber_dunning SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete dunning record (when subscriber confirms or is blocklisted)
     */
    public function deleteDunning(int $subscriberId, int $listId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM subscriber_dunning WHERE subscriber_id = ? AND list_id = ?");
        return $stmt->execute([$subscriberId, $listId]);
    }

    /**
     * Delete subscriber and all related records (drips, dunning)
     * Call this when subscriber is deleted from Listmonk
     */
    public function deleteSubscriber(int $subscriberId): bool
    {
        try {
            $this->db->beginTransaction();

            // Delete drip records
            $stmt = $this->db->prepare("DELETE FROM subscriber_drips WHERE subscriber_id = ?");
            $stmt->execute([$subscriberId]);

            // Delete dunning records
            $stmt = $this->db->prepare("DELETE FROM subscriber_dunning WHERE subscriber_id = ?");
            $stmt->execute([$subscriberId]);

            // Delete subscriber
            $stmt = $this->db->prepare("DELETE FROM subscribers WHERE id = ?");
            $stmt->execute([$subscriberId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Mark all drips for a subscriber as deleted (subscriber was deleted from Listmonk)
     * Used for lazy cleanup when campaign builder detects orphaned listmonk_ids
     *
     * @param int $listmonkId The Listmonk subscriber ID
     * @return bool Success status
     */
    public function markSubscriberDeletedByListmonkId(int $listmonkId): bool
    {
        // Find subscriber by listmonk_id
        $stmt = $this->db->prepare("SELECT id FROM subscribers WHERE listmonk_id = ?");
        $stmt->execute([$listmonkId]);
        $subscriber = $stmt->fetch();

        if (!$subscriber) {
            return false; // Subscriber not found in SQLite
        }

        $subscriberId = (int)$subscriber['id'];

        // Update all drips for this subscriber to deleted
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            UPDATE subscriber_drips
            SET stage = 'deleted', next_send = NULL, updated_at = ?
            WHERE subscriber_id = ? AND stage NOT IN ('deleted', 'complete')
        ");
        return $stmt->execute([$now, $subscriberId]);
    }

    /**
     * Get all dunning records due for reminder
     */
    public function getDueDunning(): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare("
            SELECT dn.*, s.email, s.listmonk_id
            FROM subscriber_dunning dn
            JOIN subscribers s ON dn.subscriber_id = s.id
            WHERE dn.next_reminder IS NOT NULL
              AND dn.next_reminder <= ?
            ORDER BY dn.next_reminder ASC
        ");
        $stmt->execute([$now]);
        return $stmt->fetchAll();
    }

    /**
     * Get all active dunning records (for checking confirmations)
     */
    public function getActiveDunning(): array
    {
        return $this->db->query("
            SELECT dn.*, s.email, s.listmonk_id
            FROM subscriber_dunning dn
            JOIN subscribers s ON dn.subscriber_id = s.id
            ORDER BY dn.started_at ASC
        ")->fetchAll();
    }

    /**
     * Get dunning stats
     */
    public function getDunningStats(): array
    {
        return $this->db->query("
            SELECT
                dn.stage,
                COUNT(*) as count
            FROM subscriber_dunning dn
            GROUP BY dn.stage
            ORDER BY
                CASE dn.stage
                    WHEN 'dunning_1' THEN 1
                    WHEN 'dunning_2' THEN 2
                    WHEN 'dunning_3' THEN 3
                    WHEN 'dunning_4' THEN 4
                    WHEN 'dunning_blocklist' THEN 5
                    ELSE 6
                END
        ")->fetchAll();
    }

    /**
     * Get subscribers who need dunning initiated (new unconfirmed, no dunning record)
     * This requires checking against Listmonk, so we return subscriber IDs that have no dunning record
     * The processor will then check Listmonk for their DOI status
     */
    public function getSubscribersWithoutDunning(array $listIds): array
    {
        if (empty($listIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($listIds), '?'));

        // Get all subscribers who have drips for products that use these lists
        // but don't have dunning records for those lists
        $stmt = $this->db->prepare("
            SELECT DISTINCT s.id, s.email, s.listmonk_id
            FROM subscribers s
            WHERE s.listmonk_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM subscriber_dunning dn
                  WHERE dn.subscriber_id = s.id
                    AND dn.list_id IN ($placeholders)
              )
        ");
        $stmt->execute($listIds);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // FA-16: Audit/History Methods
    // =========================================================================

    /**
     * Get subscriber activity for audit page
     */
    public function getSubscriberActivity(string $email): array
    {
        $subscriber = $this->getSubscriberByEmail($email);
        if (!$subscriber) {
            return ['subscriber' => null, 'drips' => [], 'dunning' => []];
        }

        $stmtDrips = $this->db->prepare("
            SELECT d.*, p.name as product_name
            FROM subscriber_drips d
            JOIN products p ON d.product_id = p.id
            WHERE d.subscriber_id = ?
            ORDER BY d.updated_at DESC
        ");
        $stmtDrips->execute([$subscriber['id']]);

        $stmtDunning = $this->db->prepare("
            SELECT * FROM subscriber_dunning
            WHERE subscriber_id = ?
            ORDER BY updated_at DESC
        ");
        $stmtDunning->execute([$subscriber['id']]);

        return [
            'subscriber' => $subscriber,
            'drips' => $stmtDrips->fetchAll(),
            'dunning' => $stmtDunning->fetchAll(),
        ];
    }

    // =========================================================================
    // FA-16: Queue Stats Methods (for API endpoints)
    // =========================================================================

    /**
     * Get queue stats for all products
     * Used by /api/drip/queue/stats
     */
    public function getQueueStats(): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $stats = [
            'in_queue' => 0,
            'due_now' => 0,
            'errors' => 0,
            'completed' => 0,
            'by_product' => []
        ];

        $products = $this->getProducts();

        foreach ($products as $product) {
            $productId = $product['id'];

            // In queue (active, not complete/stopped/error/none/imported)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ?
                  AND is_active = 1
                  AND stage IS NOT NULL
                  AND stage NOT IN ('complete', 'stopped', 'error', 'none', 'imported')
            ");
            $stmt->execute([$productId]);
            $inQueue = (int)$stmt->fetchColumn();

            // Due now
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ?
                  AND is_active = 1
                  AND next_send IS NOT NULL
                  AND next_send <= ?
                  AND stage IS NOT NULL
                  AND stage NOT IN ('complete', 'stopped', 'error', 'none', 'imported')
            ");
            $stmt->execute([$productId, $now]);
            $dueNow = (int)$stmt->fetchColumn();

            // Errors
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ? AND stage = 'error'
            ");
            $stmt->execute([$productId]);
            $errors = (int)$stmt->fetchColumn();

            // Completed
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ? AND stage = 'complete'
            ");
            $stmt->execute([$productId]);
            $completed = (int)$stmt->fetchColumn();

            $stats['by_product'][$productId] = [
                'name' => $product['name'],
                'in_queue' => $inQueue,
                'due_now' => $dueNow,
                'errors' => $errors,
                'completed' => $completed
            ];

            $stats['in_queue'] += $inQueue;
            $stats['due_now'] += $dueNow;
            $stats['errors'] += $errors;
            $stats['completed'] += $completed;
        }

        return $stats;
    }

    /**
     * Get queue items for display
     * Used by /api/drip/queue
     */
    public function getQueueItems(?string $productId = null, string $status = 'active', int $page = 1, int $perPage = 50): array
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $offset = ($page - 1) * $perPage;

        $params = [];
        $whereConditions = [];

        if ($productId) {
            $whereConditions[] = "d.product_id = ?";
            $params[] = $productId;
        }

        switch ($status) {
            case 'due':
                $whereConditions[] = "d.next_send IS NOT NULL AND d.next_send != '' AND d.next_send <= ?";
                $whereConditions[] = "d.is_active = 1";
                $whereConditions[] = "d.stage IS NOT NULL AND d.stage != '' AND d.stage NOT IN ('complete', 'stopped', 'error', 'none', 'imported')";
                $params[] = $now;
                break;
            case 'error':
                $whereConditions[] = "d.stage = 'error'";
                break;
            case 'completed':
                $whereConditions[] = "d.stage = 'complete'";
                break;
            case 'active':
            default:
                $whereConditions[] = "d.is_active = 1";
                $whereConditions[] = "d.stage IS NOT NULL AND d.stage != '' AND d.stage NOT IN ('complete', 'stopped', 'error', 'none', 'imported')";
                break;
        }

        $where = empty($whereConditions) ? '1=1' : implode(' AND ', $whereConditions);

        // Get total count
        $countSql = "
            SELECT COUNT(*) FROM subscriber_drips d
            JOIN subscribers s ON d.subscriber_id = s.id
            WHERE $where
        ";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Get items
        $sql = "
            SELECT d.*, s.email, s.listmonk_id, p.name as product_name, p.list_id
            FROM subscriber_drips d
            JOIN subscribers s ON d.subscriber_id = s.id
            JOIN products p ON d.product_id = p.id
            WHERE $where
            ORDER BY d.next_send ASC, d.updated_at DESC
            LIMIT ? OFFSET ?
        ";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        return [
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ];
    }

    /**
     * Reset a drip for a subscriber/product
     * Used by /api/drip/queue/reset
     */
    public function resetDrip(int $subscriberId, string $productId): bool
    {
        $drip = $this->getDrip($subscriberId, $productId);
        if (!$drip) {
            return false;
        }

        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $status = $drip['status'] ?? 'free';
        $firstStage = $status . '_1';

        return $this->updateDrip((int)$drip['id'], [
            'stage' => $firstStage,
            'next_send' => $now,
            'failures' => 0,
            'is_active' => 1
        ]);
    }

    /**
     * Reset all errors for a product
     * Used by /api/drip/queue/retry-errors
     */
    public function retryProductErrors(string $productId): int
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        // Get all error drips for this product
        $stmt = $this->db->prepare("
            SELECT d.id, d.status
            FROM subscriber_drips d
            WHERE d.product_id = ? AND d.stage = 'error'
        ");
        $stmt->execute([$productId]);
        $errors = $stmt->fetchAll();

        $count = 0;
        foreach ($errors as $drip) {
            $status = $drip['status'] ?? 'free';
            $firstStage = $status . '_1';

            $this->updateDrip((int)$drip['id'], [
                'stage' => $firstStage,
                'next_send' => $now,
                'failures' => 0,
                'is_active' => 1
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Get detailed stats for dashboard
     * Used by /api/drip/stats/detailed
     */
    public function getDetailedStats(): array
    {
        $products = $this->getProducts();

        // Calculate time boundaries
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $weekAgo = (clone $now)->modify('-7 days')->format('Y-m-d\TH:i:s\Z');
        $monthAgo = (clone $now)->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
        $twoWeeksAgo = (clone $now)->modify('-14 days')->format('Y-m-d\TH:i:s\Z');

        $response = [
            'summary' => [
                'total_active' => 0,
                'completed_all_time' => 0,
                'completed_this_week' => 0,
                'completed_this_month' => 0,
                'completed_last_week' => 0,
                'errors' => 0
            ],
            'by_product' => []
        ];

        foreach ($products as $product) {
            $productId = $product['id'];

            $productData = [
                'name' => $product['name'],
                'funnel' => [],
                'total_active' => 0
            ];

            // Initialize funnel with all defined stages set to 0 (include all, even disabled)
            // getSequences returns ordered by type, step_order so we preserve proper order
            $sequences = $this->getSequences($productId);
            foreach ($sequences as $seq) {
                if (!empty($seq['stage'])) {
                    $productData['funnel'][$seq['stage']] = 0;
                }
            }

            // Total active (exclude inactive stages)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ?
                  AND is_active = 1
                  AND stage IS NOT NULL
                  AND stage NOT IN ('complete', 'stopped', 'error', 'none', 'imported')
            ");
            $stmt->execute([$productId]);
            $productData['total_active'] = (int)$stmt->fetchColumn();
            $response['summary']['total_active'] += $productData['total_active'];

            // Count by stage (funnel) - exclude inactive stages
            $stmt = $this->db->prepare("
                SELECT stage, COUNT(*) as count
                FROM subscriber_drips
                WHERE product_id = ?
                  AND stage IS NOT NULL
                  AND stage NOT IN ('none', 'imported')
                GROUP BY stage
            ");
            $stmt->execute([$productId]);
            $stageCounts = $stmt->fetchAll();

            // Track terminal state counts to add at end
            $terminalCounts = ['complete' => 0, 'stopped' => 0, 'error' => 0];

            foreach ($stageCounts as $row) {
                $stage = $row['stage'] ?? 'unknown';
                $count = (int)$row['count'];

                // Terminal states: track separately to add at end
                if (in_array($stage, ['complete', 'stopped', 'error'])) {
                    $terminalCounts[$stage] = $count;
                } else {
                    $productData['funnel'][$stage] = $count;
                }

                if ($stage === 'complete') {
                    $response['summary']['completed_all_time'] += $count;
                }
                if ($stage === 'error') {
                    $response['summary']['errors'] += $count;
                }
            }

            // Add terminal states at the end (always show, even if 0)
            $productData['funnel']['complete'] = $terminalCounts['complete'];
            $productData['funnel']['stopped'] = $terminalCounts['stopped'];
            $productData['funnel']['error'] = $terminalCounts['error'];

            // Time-based completions for this product
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ? AND stage = 'complete' AND updated_at >= ?
            ");
            $stmt->execute([$productId, $weekAgo]);
            $productThisWeek = (int)$stmt->fetchColumn();
            $response['summary']['completed_this_week'] += $productThisWeek;

            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ? AND stage = 'complete' AND updated_at >= ?
            ");
            $stmt->execute([$productId, $monthAgo]);
            $productThisMonth = (int)$stmt->fetchColumn();
            $response['summary']['completed_this_month'] += $productThisMonth;

            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM subscriber_drips
                WHERE product_id = ? AND stage = 'complete' AND updated_at >= ? AND updated_at < ?
            ");
            $stmt->execute([$productId, $twoWeeksAgo, $weekAgo]);
            $productLastWeek = (int)$stmt->fetchColumn();
            $response['summary']['completed_last_week'] += $productLastWeek;

            $productData['this_week'] = ['completed' => $productThisWeek];
            $productData['this_month'] = ['completed' => $productThisMonth];

            $response['by_product'][$productId] = $productData;
        }

        // Get dunning stats
        $dunningStats = $this->getDunningStats();
        $response['dunning'] = [
            'total_in_dunning' => 0,
            'by_stage' => []
        ];
        foreach ($dunningStats as $row) {
            $response['dunning']['by_stage'][$row['stage']] = (int)$row['count'];
            $response['dunning']['total_in_dunning'] += (int)$row['count'];
        }

        return $response;
    }

    /**
     * Get all drips for a subscriber
     */
    public function getSubscriberDrips(int $subscriberId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, p.name as product_name, p.list_id
            FROM subscriber_drips d
            JOIN products p ON d.product_id = p.id
            WHERE d.subscriber_id = ?
            ORDER BY p.name
        ");
        $stmt->execute([$subscriberId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all dunning for a subscriber
     */
    public function getSubscriberDunning(int $subscriberId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscriber_dunning
            WHERE subscriber_id = ?
            ORDER BY list_id
        ");
        $stmt->execute([$subscriberId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Segment Builder Methods
    // =========================================================================

    /**
     * Get distinct values for a drip field (for segment builder filters)
     */
    public function getDistinctDripValues(string $field, ?string $productId = null): array
    {
        $allowedFields = ['status', 'stage', 'product_id'];
        if (!in_array($field, $allowedFields)) {
            return [];
        }

        $sql = "SELECT DISTINCT $field FROM subscriber_drips WHERE $field IS NOT NULL AND $field != ''";
        $params = [];

        if ($productId) {
            $sql .= " AND product_id = ?";
            $params[] = $productId;
        }

        $sql .= " ORDER BY $field";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(), $field);
    }

    /**
     * Execute a raw SELECT query (for segment builder)
     * Security: Only allows SELECT queries, validated by caller
     */
    public function executeRawQuery(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
