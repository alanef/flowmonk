# Implementation Plan: FA-10 - Generic Product Sequences

## Executive Summary

- **Epic:** FA-10
- **Complexity:** Medium
- **Subtasks:** 4 (FA-11, FA-12, FA-13, FA-14)
- **Goal:** Rename plugin→product, make sequence types dynamic instead of hardcoded

## What's Changing

| Aspect | Current | Target |
|--------|---------|--------|
| Terminology | "plugin" | "product" |
| Type list | Hardcoded `['free', 'trial', 'premium']` | Read from `sequence_types` table |
| UI tabs | Fixed 3 tabs | Dynamic tabs from DB |

## What's NOT Changing

- Subscriber attributes (`p{id}_drip_stage`, etc.) - format stays same
- Stage naming convention (`type_number`) - stays same
- Sequences table structure - stays same
- Drip processing logic - just reads types dynamically

## Database Changes

### 1. Rename table
```sql
ALTER TABLE plugins RENAME TO products;
```

### 2. Add sequence_types table
```sql
CREATE TABLE sequence_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id TEXT NOT NULL,
    type_name TEXT NOT NULL,
    display_name TEXT NOT NULL,
    sort_order INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    UNIQUE(product_id, type_name)
);
```

### 3. Populate from existing data
```sql
INSERT INTO sequence_types (product_id, type_name, display_name, sort_order)
SELECT DISTINCT
    product_id,
    type,
    UPPER(SUBSTR(type, 1, 1)) || SUBSTR(type, 2),
    CASE type
        WHEN 'free' THEN 1
        WHEN 'trial' THEN 2
        WHEN 'premium' THEN 3
        ELSE 4
    END
FROM sequences;
```

## Files Requiring Changes

| File | Type of Change |
|------|----------------|
| `campaign-list-builder/src/SequenceDatabase.php` | Rename methods, add sequence_types, migration |
| `drip-controller/src/SequenceDatabase.php` | **Mirror all changes** (keep in sync) |
| `drip-controller/src/SequenceManager.php` | Dynamic type detection |
| `campaign-list-builder/src/WebhookHandler.php` | Rename variables, dynamic stage init |
| `campaign-list-builder/public/index.php` | Rename API endpoints, add types API |
| `campaign-list-builder/templates/drip-sequences.php` | Dynamic tabs, terminology |
| `campaign-list-builder/templates/drip-stats.php` | Terminology updates |

## Implementation Order

```
FA-11 (Rename) ──┬──> FA-13 (UI)
FA-12 (Schema) ──┘        │
                          v
                    FA-14 (Processor)
```

1. **FA-11 + FA-12** can run in parallel
2. **FA-13** needs both complete
3. **FA-14** needs FA-12, best after FA-13

---

## FA-11: Rename plugin → product

### Goal
Update all terminology from "plugin" to "product" throughout codebase and UI.

### Files to Modify

#### 1. campaign-list-builder/src/SequenceDatabase.php

**Table rename (add to migration section around line 40):**
```php
// Rename plugins table to products
try {
    $this->db->exec("ALTER TABLE plugins RENAME TO products");
} catch (PDOException $e) {
    // Already renamed or doesn't exist
}
```

**Method renames (lines 182-309):**
| Current | New |
|---------|-----|
| `getPlugins()` | `getProducts()` |
| `getPlugin($id)` | `getProduct($id)` |
| `savePlugin(...)` | `saveProduct(...)` |
| `getPluginListId($id)` | `getProductListId($id)` |
| `getPluginListIds()` | `getProductListIds()` |
| `getPluginById($id)` | `getProductById($id)` |
| `getPluginType($id)` | `getProductType($id)` |
| `savePluginFull(...)` | `saveProductFull(...)` |
| `deletePlugin($id)` | `deleteProduct($id)` |

**SQL queries:** Update all references from `plugins` table to `products` table.

#### 2. drip-controller/src/SequenceDatabase.php

**Mirror ALL changes from #1** - these files must stay in sync.

#### 3. campaign-list-builder/src/WebhookHandler.php

**Variable renames throughout:**
```php
// Line 50, 62, 74, etc.
$pluginId → $productId
$plugin → $product
```

**Method call updates:**
```php
// Line 62
$plugin = $this->db->getPluginById($pluginId);
// becomes
$product = $this->db->getProductById($productId);
```

**Log messages:**
```php
// Update all log messages
"plugin=$pluginId" → "product=$productId"
```

#### 4. campaign-list-builder/public/index.php

**API route renames (lines 265-400):**
```php
// Line 265: GET plugins
case $endpoint === 'drip/plugins' && $method === 'GET':
// becomes
case $endpoint === 'drip/products' && $method === 'GET':

// Line 271: POST plugins
case $endpoint === 'drip/plugins' && $method === 'POST':
// becomes
case $endpoint === 'drip/products' && $method === 'POST':

// Line 297: DELETE plugins/{id}
case preg_match('#^drip/plugins/([a-zA-Z0-9_-]+)$#', ...):
// becomes
case preg_match('#^drip/products/([a-zA-Z0-9_-]+)$#', ...):
```

**Method calls:** Update all `getPlugins()`, `savePlugin()`, etc.

#### 5. campaign-list-builder/templates/drip-sequences.php

**UI labels (lines 4-20):**
```html
<!-- Line 4 -->
<h1>Drip Sequences</h1>  <!-- No change needed -->

<!-- Line 8: Selector label -->
<label for="plugin-select">Plugin</label>
<!-- becomes -->
<label for="product-select">Product</label>

<!-- Lines 16-18: Buttons -->
<button>Add Plugin</button>
<button>Edit Plugin</button>
<button>Delete Plugin</button>
<!-- becomes -->
<button>Add Product</button>
<button>Edit Product</button>
<button>Delete Product</button>
```

**Modal (lines 83-134):**
```html
<!-- Line 83 -->
<h3 x-text="editingPlugin ? 'Edit Plugin' : 'Add Plugin'"></h3>
<!-- becomes -->
<h3 x-text="editingProduct ? 'Edit Product' : 'Add Product'"></h3>

<!-- Line 89 -->
<label for="plugin-id">Plugin ID</label>
<!-- becomes -->
<label for="product-id">Product ID</label>
```

**JavaScript (lines 285-404):**
```javascript
// Rename throughout:
plugins → products
selectedPluginId → selectedProductId
pluginForm → productForm
editingPlugin → editingProduct
loadPlugins() → loadProducts()
editPlugin() → editProduct()
savePlugin() → saveProduct()
deletePlugin() → deleteProduct()
resetPluginForm() → resetProductForm()

// API calls
fetch('/api/drip/plugins') → fetch('/api/drip/products')
```

#### 6. campaign-list-builder/templates/drip-stats.php

**Update any "plugin" references in UI text.**

### Acceptance Criteria
- [ ] All UI shows "Product" instead of "Plugin"
- [ ] API endpoints use `/api/drip/products` path
- [ ] Code uses `$productId` variable names
- [ ] Both SequenceDatabase files in sync
- [ ] Existing functionality unchanged

---

## FA-12: Configurable sequence stages per product

### Goal
Replace hardcoded `['free', 'trial', 'premium']` with database-driven types.

### Database Changes

**Add to SequenceDatabase migration (both files):**
```php
// Create sequence_types table
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

// Populate from existing sequences
$this->db->exec("
    INSERT OR IGNORE INTO sequence_types (product_id, type_name, display_name, sort_order)
    SELECT DISTINCT
        product_id,
        type,
        UPPER(SUBSTR(type, 1, 1)) || SUBSTR(type, 2),
        CASE type
            WHEN 'free' THEN 1
            WHEN 'trial' THEN 2
            WHEN 'premium' THEN 3
            ELSE 4
        END
    FROM sequences
    WHERE type IS NOT NULL AND type != ''
");
```

### New Methods in SequenceDatabase

```php
/**
 * Get sequence types for a product
 */
public function getSequenceTypes(string $productId): array
{
    $stmt = $this->db->prepare("
        SELECT type_name, display_name, sort_order
        FROM sequence_types
        WHERE product_id = ?
        ORDER BY sort_order, type_name
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add a sequence type to a product
 */
public function addSequenceType(string $productId, string $typeName, string $displayName, int $sortOrder = 0): int
{
    $stmt = $this->db->prepare("
        INSERT INTO sequence_types (product_id, type_name, display_name, sort_order)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$productId, $typeName, $displayName, $sortOrder]);
    return (int) $this->db->lastInsertId();
}

/**
 * Update a sequence type
 */
public function updateSequenceType(string $productId, string $typeName, string $displayName, int $sortOrder): bool
{
    $stmt = $this->db->prepare("
        UPDATE sequence_types
        SET display_name = ?, sort_order = ?
        WHERE product_id = ? AND type_name = ?
    ");
    return $stmt->execute([$displayName, $sortOrder, $productId, $typeName]);
}

/**
 * Delete a sequence type (also deletes associated sequences)
 */
public function deleteSequenceType(string $productId, string $typeName): bool
{
    // Delete sequences first
    $stmt = $this->db->prepare("DELETE FROM sequences WHERE product_id = ? AND type = ?");
    $stmt->execute([$productId, $typeName]);

    // Delete type
    $stmt = $this->db->prepare("DELETE FROM sequence_types WHERE product_id = ? AND type_name = ?");
    return $stmt->execute([$productId, $typeName]);
}
```

### Update getSequencesByType()

**Current (line ~341 in campaign-list-builder, ~252 in drip-controller):**
```php
public function getSequencesByType(string $productId): array
{
    $grouped = ['free' => [], 'trial' => [], 'premium' => []]; // HARDCODED!
    // ...
}
```

**New:**
```php
public function getSequencesByType(string $productId): array
{
    // Get types from database
    $types = $this->getSequenceTypes($productId);
    $grouped = [];
    foreach ($types as $type) {
        $grouped[$type['type_name']] = [];
    }

    // If no types defined, return empty
    if (empty($grouped)) {
        return [];
    }

    // Populate with sequences
    $stmt = $this->db->prepare("
        SELECT * FROM sequences
        WHERE product_id = ?
        ORDER BY type, step_order
    ");
    $stmt->execute([$productId]);
    $sequences = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sequences as $seq) {
        $type = $seq['type'];
        if (isset($grouped[$type])) {
            $grouped[$type][] = $seq;
        }
    }

    return $grouped;
}
```

### Update getSequencesAsConfig()

**Current (line ~473 in campaign-list-builder, ~384 in drip-controller):**
```php
foreach (['free', 'trial', 'premium'] as $type) { // HARDCODED!
```

**New:**
```php
$types = $this->getSequenceTypes($productId);
foreach ($types as $typeInfo) {
    $type = $typeInfo['type_name'];
    // ...
}
```

### Acceptance Criteria
- [ ] sequence_types table created
- [ ] Existing types auto-populated from sequences table
- [ ] getSequenceTypes() method works
- [ ] addSequenceType() method works
- [ ] deleteSequenceType() method works
- [ ] getSequencesByType() returns dynamic types
- [ ] Both SequenceDatabase files in sync

---

## FA-13: Frontend UI updates

### Goal
Make sequence type tabs dynamic and add type management UI.

### Dynamic Tabs in drip-sequences.php

**Current (lines 26-28):**
```html
<div role="group" class="sequence-tabs">
    <button :class="{ 'active': activeType === 'free' }" @click="activeType = 'free'">Free</button>
    <button :class="{ 'active': activeType === 'trial' }" @click="activeType = 'trial'">Trial</button>
    <button :class="{ 'active': activeType === 'premium' }" @click="activeType = 'premium'">Premium</button>
</div>
```

**New:**
```html
<div role="group" class="sequence-tabs">
    <template x-for="type in sequenceTypes" :key="type.type_name">
        <button :class="{ 'active': activeType === type.type_name }"
                @click="activeType = type.type_name"
                x-text="type.display_name"></button>
    </template>
    <button class="outline small" @click="showTypeModal = true" title="Manage Types">+</button>
</div>
```

### Add Type Management Modal

```html
<!-- Type Management Modal -->
<dialog :open="showTypeModal" @click.self="showTypeModal = false">
    <article>
        <header>
            <button aria-label="Close" rel="prev" @click="showTypeModal = false"></button>
            <h3>Manage Sequence Types</h3>
        </header>

        <!-- Existing Types List -->
        <table role="grid">
            <thead>
                <tr>
                    <th>Type Name</th>
                    <th>Display Name</th>
                    <th>Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="type in sequenceTypes" :key="type.type_name">
                    <tr>
                        <td x-text="type.type_name"></td>
                        <td x-text="type.display_name"></td>
                        <td x-text="type.sort_order"></td>
                        <td>
                            <button class="outline small contrast" @click="deleteType(type.type_name)">Delete</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!-- Add New Type Form -->
        <h4>Add New Type</h4>
        <form @submit.prevent="addType()">
            <div class="grid">
                <div>
                    <label>Type Name (identifier)</label>
                    <input type="text" x-model="typeForm.type_name" placeholder="e.g., module" required
                           pattern="[a-z][a-z0-9_]*" title="Lowercase letters, numbers, underscores">
                </div>
                <div>
                    <label>Display Name</label>
                    <input type="text" x-model="typeForm.display_name" placeholder="e.g., Module Series" required>
                </div>
                <div>
                    <label>Sort Order</label>
                    <input type="number" x-model="typeForm.sort_order" value="0">
                </div>
            </div>
            <button type="submit">Add Type</button>
        </form>
    </article>
</dialog>
```

### JavaScript Updates

```javascript
function dripSequences() {
    return {
        products: [],
        sequenceTypes: [],  // NEW
        sequences: {},
        selectedProductId: '',
        activeType: '',

        // Type modal - NEW
        showTypeModal: false,
        typeForm: { type_name: '', display_name: '', sort_order: 0 },

        // ... existing properties ...

        async init() {
            await this.loadProducts();
        },

        // NEW: Load sequence types for selected product
        async loadSequenceTypes() {
            if (!this.selectedProductId) {
                this.sequenceTypes = [];
                this.activeType = '';
                return;
            }

            try {
                const response = await fetch(`/api/drip/products/${this.selectedProductId}/types`);
                const data = await response.json();
                this.sequenceTypes = data.data || [];

                // Set active type to first one
                if (this.sequenceTypes.length > 0 && !this.activeType) {
                    this.activeType = this.sequenceTypes[0].type_name;
                }
            } catch (error) {
                this.showMessage('Failed to load sequence types', 'error');
            }
        },

        // Update loadSequences to also load types
        async loadSequences() {
            await this.loadSequenceTypes();

            if (!this.selectedProductId) {
                this.sequences = {};
                return;
            }

            try {
                const response = await fetch(`/api/drip/sequences/${this.selectedProductId}`);
                const data = await response.json();
                this.sequences = data.data || {};
            } catch (error) {
                this.showMessage('Failed to load sequences', 'error');
            }
        },

        // NEW: Add sequence type
        async addType() {
            if (!this.typeForm.type_name || !this.typeForm.display_name) {
                this.showMessage('Type name and display name required', 'error');
                return;
            }

            try {
                const response = await fetch(`/api/drip/products/${this.selectedProductId}/types`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.typeForm)
                });

                if (response.ok) {
                    await this.loadSequenceTypes();
                    this.typeForm = { type_name: '', display_name: '', sort_order: 0 };
                    this.showMessage('Type added', 'success');
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to add type', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to add type', 'error');
            }
        },

        // NEW: Delete sequence type
        async deleteType(typeName) {
            if (!confirm(`Delete type "${typeName}" and all its sequences?`)) return;

            try {
                const response = await fetch(
                    `/api/drip/products/${this.selectedProductId}/types/${typeName}`,
                    { method: 'DELETE' }
                );

                if (response.ok) {
                    await this.loadSequences();
                    if (this.activeType === typeName) {
                        this.activeType = this.sequenceTypes[0]?.type_name || '';
                    }
                    this.showMessage('Type deleted', 'success');
                } else {
                    this.showMessage('Failed to delete type', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to delete type', 'error');
            }
        },

        // ... rest of existing methods with plugin→product renames ...
    };
}
```

### New API Endpoints in index.php

```php
// Get sequence types for a product
case preg_match('#^drip/products/([^/]+)/types$#', $endpoint, $matches) && $method === 'GET':
    $productId = $matches[1];
    $seqDb = new SequenceDatabase();
    $types = $seqDb->getSequenceTypes($productId);
    echo json_encode(['data' => $types]);
    break;

// Add sequence type
case preg_match('#^drip/products/([^/]+)/types$#', $endpoint, $matches) && $method === 'POST':
    $productId = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);

    $typeName = $input['type_name'] ?? '';
    $displayName = $input['display_name'] ?? '';
    $sortOrder = (int)($input['sort_order'] ?? 0);

    if (empty($typeName) || empty($displayName)) {
        http_response_code(400);
        echo json_encode(['error' => 'type_name and display_name required']);
        break;
    }

    // Validate type_name format
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $typeName)) {
        http_response_code(400);
        echo json_encode(['error' => 'type_name must be lowercase letters, numbers, underscores']);
        break;
    }

    $seqDb = new SequenceDatabase();
    $id = $seqDb->addSequenceType($productId, $typeName, $displayName, $sortOrder);
    echo json_encode(['success' => true, 'id' => $id]);
    break;

// Delete sequence type
case preg_match('#^drip/products/([^/]+)/types/([^/]+)$#', $endpoint, $matches) && $method === 'DELETE':
    $productId = $matches[1];
    $typeName = $matches[2];

    $seqDb = new SequenceDatabase();
    $success = $seqDb->deleteSequenceType($productId, $typeName);
    echo json_encode(['success' => $success]);
    break;
```

### Acceptance Criteria
- [ ] Dynamic tabs based on product's sequence types
- [ ] "+" button to open type management modal
- [ ] Add new sequence type via form
- [ ] Delete sequence type (with confirmation)
- [ ] Type name validation (lowercase, no spaces)
- [ ] "Product" terminology throughout
- [ ] First type auto-selected when product changes

---

## FA-14: Update drip processor for dynamic stages

### Goal
Update drip processor to use product-defined stages instead of hardcoded logic.

### Update SequenceManager.php

**getTypeFromStage() - Current (lines 131-143):**
```php
public function getTypeFromStage(string $stage): ?string
{
    if (str_starts_with($stage, 'free_')) return 'free';
    if (str_starts_with($stage, 'trial_')) return 'trial';
    if (str_starts_with($stage, 'premium_')) return 'premium';
    return null;
}
```

**getTypeFromStage() - New:**
```php
public function getTypeFromStage(string $productId, string $stage): ?string
{
    // Terminal stages are type-agnostic
    if (in_array($stage, ['complete', 'stopped', 'error', 'none', 'imported'])) {
        return null;
    }

    // Get types from database
    $types = $this->db->getSequenceTypes($productId);

    foreach ($types as $type) {
        if (str_starts_with($stage, $type['type_name'] . '_')) {
            return $type['type_name'];
        }
    }

    return null;
}
```

**Update callers of getTypeFromStage()** to pass productId.

**getAllStages() - Current (line 187):**
```php
foreach (['free', 'trial', 'premium'] as $type) {
```

**getAllStages() - New:**
```php
$types = $this->db->getSequenceTypes($productId);
foreach ($types as $typeInfo) {
    $type = $typeInfo['type_name'];
    // ...
}
```

### Update WebhookHandler.php

**getDripStage() - Current (line 337):**
```php
private function getDripStage(string $status): string
{
    return $status . '_1'; // Hardcoded pattern
}
```

**getDripStage() - New:**
```php
private function getDripStage(string $productId, string $status): ?string
{
    // Get sequences for this product
    $sequences = $this->db->getSequencesByType($productId);

    // Look for first enabled step matching the status type
    if (isset($sequences[$status]) && !empty($sequences[$status])) {
        foreach ($sequences[$status] as $step) {
            if ($step['enabled']) {
                return $step['stage'];
            }
        }
    }

    // Fallback: look for any type starting with status
    foreach ($sequences as $type => $steps) {
        if (str_starts_with($type, $status) && !empty($steps)) {
            foreach ($steps as $step) {
                if ($step['enabled']) {
                    return $step['stage'];
                }
            }
        }
    }

    $this->log('warning', "No sequence found for product=$productId, status=$status");
    return null;
}
```

**Update shouldInitializeDrip() caller (lines 259-263):**
```php
// Current
if ($this->shouldInitializeDrip($eventType, $statusChanged)) {
    $attribs["p{$productId}_drip_stage"] = $this->getDripStage($status);
    // ...
}

// New
if ($this->shouldInitializeDrip($eventType, $statusChanged)) {
    $initialStage = $this->getDripStage($productId, $status);
    if ($initialStage) {
        $attribs["p{$productId}_drip_stage"] = $initialStage;
        $attribs["p{$productId}_drip_next"] = $this->getNextDripDate();
        $attribs["p{$productId}_drip_started"] = date('c');
    }
}
```

### Update DripProcessor.php

**Ensure getStep() and getNextStep() calls work with dynamic types.**

The DripProcessor already loads sequences from the database via SequenceManager, so most logic should work once SequenceManager is updated.

**Verify these work with custom stage names:**
- `sendAndAdvance()` - gets current stage, sends email, advances
- Stage advancement logic - should use database order, not string parsing

### Acceptance Criteria
- [ ] getTypeFromStage() uses database lookup
- [ ] getDripStage() returns first enabled step from database
- [ ] Existing stages (`free_1`, `trial_1`) still work
- [ ] Custom stages (`module_1`, `welcome_1`) work correctly
- [ ] Terminal stages (complete, stopped, error) still work
- [ ] Webhook initializes correct first stage from database

---

## Testing Checklist

### After FA-11 (Rename)
- [ ] UI shows "Product" everywhere
- [ ] API `/api/drip/products` works
- [ ] Existing products still load
- [ ] Can create/edit/delete products

### After FA-12 (Schema)
- [ ] sequence_types table exists
- [ ] Existing types auto-populated
- [ ] getSequenceTypes() returns types
- [ ] getSequencesByType() returns dynamic grouped data

### After FA-13 (UI)
- [ ] Tabs are dynamic based on product types
- [ ] Can add new sequence type
- [ ] Can delete sequence type
- [ ] Adding sequences works with new types

### After FA-14 (Processor)
- [ ] Drip processor processes existing subscribers
- [ ] Webhook creates subscriber with correct stage
- [ ] Custom stages process correctly
- [ ] Stats page shows correct data

---

**Full analysis documents**: `specification/FA-10/`

Status: Ready for Implementation
Planned by: Claude Code AI Assistant
Date: 2025-12-19