# Implementation Plan: FA-4 - Consolidate Freemius Webhook Processing

## Executive Summary

- **Epic:** FA-4
- **Complexity:** Medium-High
- **Subtasks:** 5
- **Goal:** Eliminate workflow-helpers and n8n by moving all Freemius webhook processing into email-helpers

## Overall Architecture

### Current State (3 Systems)
```
WordPress → webhook-verifier (verify HMAC) → n8n (transform/map) → Listmonk API
```

### Target State (1 System)
```
WordPress → email-helpers webhook endpoint → Listmonk API
```

### Technology Stack
- **Backend:** PHP 8.x (campaign-list-builder)
- **Database:** SQLite for config, PostgreSQL (Listmonk)
- **API:** Listmonk REST API
- **Authentication:** HMAC-SHA256 signature verification

## File Structure

### Files to Create/Modify

```
campaign-list-builder/
├── src/
│   ├── WebhookHandler.php          (NEW - main webhook processing)
│   └── SequenceDatabase.php        (MODIFY - add type column + methods)
├── templates/
│   └── plugins.php                 (MODIFY - add type selector to UI)
└── public/
    └── index.php                   (MODIFY - add webhook route)
```

### Key Decision: Reuse Existing Components

1. **ListmonkClient** - Already exists in campaign-list-builder/src/Listmonk.php
   - Has `findSubscriberByEmail()`, `addSubscribersToList()`
   - Need to add `createSubscriber()` and `updateSubscriberAttribs()` methods

2. **SequenceDatabase** - Already has plugin-to-list mapping
   - `getPluginListId($pluginId)` method exists
   - Can store plugin secrets in database or use env vars

## Integration Architecture

### Data Flow

```
POST /api/webhook/product
    │
    ├── 1. Parse JSON payload
    │       └── Extract plugin_id, X-Signature header
    │
    ├── 2. Verify signature (Freemius) or validate ID (freelib)
    │       └── hmac_secret from database (freemius only)
    │
    ├── 3. Transform payload
    │       ├── Extract: email, name, status (free/trial/premium)
    │       └── Build: p{id}_status, p{id}_drip_stage, marketing_allowed
    │
    ├── 4. Find/create subscriber in Listmonk
    │       └── Use email as unique identifier
    │
    ├── 5. Merge attributes (preserve existing)
    │       └── Use updateSubscriber with attribute merge
    │
    └── 6. Add to plugin's list if not already member
```

### Payload Transformation (n8n Logic → PHP)

**Input (Freemius webhook):**
```json
{
  "plugin_id": "1330",
  "type": "install.installed",
  "objects": {
    "user": {
      "id": 123,
      "email": "user@example.com",
      "first": "John",
      "last": "Doe",
      "is_marketing_allowed": true
    },
    "install": {
      "is_active": true,
      "is_premium": false,
      "trial_plan_id": null,
      "license_id": null,
      "country_code": "US"
    }
  },
  "created": "2025-01-01T00:00:00Z"
}
```

**Output (Listmonk format):**
```json
{
  "email": "user@example.com",
  "name": "John Doe",
  "status": "enabled",
  "lists": [3],
  "attribs": {
    "marketing_allowed": true,
    "freemius_user_id": 123,
    "country": "US",
    "p1330_status": "free",
    "p1330_active": true,
    "p1330_drip_stage": "none",
    "p1330_last_event": "install.installed",
    "p1330_last_seen": "2025-01-01T00:00:00Z"
  }
}
```

### Status Determination Logic

```php
function determineStatus(array $install): string {
    if (!empty($install['trial_plan_id'])) {
        return 'trial';
    }
    if ($install['is_premium'] && !empty($install['license_id'])) {
        return 'premium';
    }
    return 'free';
}
```

## Plugin Configuration

### Approach: All Config in Database

**Plugin table in SequenceDatabase:**
- `id` - Plugin ID (e.g., "1330", "swegts")
- `name` - Display name
- `list_id` - Listmonk list ID
- `type` - "freemius", "freelib", or "other"
- `hmac_secret` - HMAC secret (freemius only, NULL for others)

All managed via existing UI at `/plugins` - no environment variables needed for plugin config.

### Plugin Mapping (from n8n)

| Plugin ID | Name | Slug | List ID | Type |
|-----------|------|------|---------|------|
| 1330 | display-eventbrite | deb | 3 | freemius |
| 5065 | anti-spam | antispam | 4 | freemius |
| 5623 | qpp | qpp | 5 | freemius |
| swegts | stop-wp-emails-going-to-spam | swegts | 8 | freelib |
| ssfgm | simple-shortcode-google-maps | ssfgm | 9 | freelib |
| lhf | load-html-files | lhf | 10 | freelib |
| sue | stop-user-enumeration | sue | 11 | freelib |
| rshfd | remove-site-health-dashboard | rshfd | 12 | freelib |
| faum | fullworks-active-users-monitor | faum | 13 | freelib |
| fss | fullworks-security-scanner | fss | 14 | freelib |
| mmt | meet-my-team | mmt | 15 | freelib |
| cscf | contact-form-clean-simple | cscf | 16 | freelib |

## Implementation Order

**Recommended sequence:**

1. **FA-6: Plugin mapping configuration** - Foundation, defines data structures
2. **FA-5: Webhook endpoint with verification** - HTTP layer, uses FA-6
3. **FA-7: Payload transformation** - Data layer, pure functions
4. **FA-8: Subscriber create/update logic** - Integrates all above
5. **FA-9: Environment and deployment** - Final configuration

**Parallelization:** FA-6 and FA-7 can be developed in parallel after initial setup.

## Testing Strategy

### Manual Testing

1. **Signature verification:**
   ```bash
   # Generate test signature
   echo -n '{"plugin_id":"1330",...}' | openssl dgst -sha256 -hmac "secret_key"

   # Send test webhook
   curl -X POST http://localhost:8082/api/webhook/product \
     -H "Content-Type: application/json" \
     -H "X-Signature: <calculated_signature>" \
     -d '{"plugin_id":"1330",...}'
   ```

2. **Subscriber creation/update:**
   - Verify new subscriber created in Listmonk
   - Verify attributes merged correctly
   - Verify list membership added

### Dry Run Mode

Add `WEBHOOK_DRY_RUN` env var support to log actions without making API calls.

**Behavior when `WEBHOOK_DRY_RUN=true`:**
- **GET requests:** Execute normally (read operations needed for lookup)
- **POST requests:** Log to debug, do NOT execute (e.g., create subscriber)
- **PUT requests:** Log to debug, do NOT execute (e.g., update attributes)
- **DELETE requests:** Log to debug, do NOT execute

**Example log output:**
```
[DEBUG] [DRY-RUN] Would POST /api/subscribers: {"email":"user@example.com",...}
[DEBUG] [DRY-RUN] Would PUT /api/subscribers/123: {"attribs":{...}}
```

This allows testing webhook processing end-to-end without modifying Listmonk data.

## Security Considerations

1. **HMAC Verification:** Use `hash_equals()` for timing-safe comparison
2. **Rate Limiting:** Add rate limiting to webhook endpoint (existing Listmonk client has retry logic)
3. **Input Validation:** Validate all incoming payload fields
4. **Error Logging:** Log verification failures for security auditing
5. **No Auth Bypass:** Webhook endpoint should NOT require basic auth (external callers)

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Signature secret mismatch | Test with known payloads before migration |
| Attribute merge conflicts | Use additive merge, never overwrite |
| List membership loss | Verify list preservation in updateSubscriber |
| Rate limiting from Listmonk | Use existing retry logic from ListmonkClient |

## Migration Plan

1. Deploy new webhook endpoint alongside existing n8n workflow
2. Update ONE plugin to point to new endpoint
3. Verify webhook processing works correctly
4. Migrate remaining plugins one at a time
5. Decommission n8n workflow
6. Decommission workflow-helpers service

---

## Subtask Plans

### FA-5: Create Generic Webhook Endpoint with Conditional Verification

**Goal:** Create HTTP endpoint that receives webhooks and verifies based on plugin type from database

**Files to modify/create:**
- `campaign-list-builder/public/index.php` - Add route
- `campaign-list-builder/src/WebhookHandler.php` - New file

**Implementation Steps:**

1. **Add webhook route to index.php:**
   ```php
   case 'POST':
       if ($path === '/api/webhook/product') {
           require_once __DIR__ . '/../src/WebhookHandler.php';
           $handler = new WebhookHandler($logger, $sequenceDb);
           $handler->handle();
           exit;
       }
   ```

2. **Create WebhookHandler.php with conditional verification:**
   ```php
   class WebhookHandler {
       private SequenceDatabase $db;

       public function handle(): void {
           // 1. Get raw body and signature header
           $payload = file_get_contents('php://input');
           $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

           // 2. Parse payload to get plugin_id
           $data = json_decode($payload, true);
           $pluginId = $data['plugin_id'] ?? null;

           // 3. Look up plugin in database
           $plugin = $this->db->getPluginById($pluginId);
           if (!$plugin) {
               $this->logger->warning("Unknown plugin_id: $pluginId");
               http_response_code(400);
               return;
           }

           // 4. Verify based on plugin type
           if ($plugin['type'] === 'freemius') {
               // HMAC verification required - secret from database
               $secret = $plugin['hmac_secret'] ?? '';
               if (empty($secret)) {
                   $this->logger->error("No HMAC secret configured for $pluginId");
                   http_response_code(500);
                   return;
               }
               $calculated = hash_hmac('sha256', $payload, $secret);
               if (!hash_equals($calculated, $signature)) {
                   $this->logger->warning("HMAC verification failed for $pluginId");
                   http_response_code(401);
                   return;
               }
           }
           // freelib/other: plugin exists in DB = valid (no HMAC needed)

           // 5. Process webhook...
       }
   }
   ```

3. **Skip auth for webhook endpoint** - Modify basic auth middleware to bypass `/api/webhook/*`

**Acceptance Criteria:**
- [ ] Endpoint accepts POST to `/api/webhook/product`
- [ ] Looks up plugin type from SequenceDatabase
- [ ] Rejects unknown plugin IDs (400)
- [ ] For freemius: rejects invalid/missing HMAC signature (401)
- [ ] For freelib/other: accepts if plugin exists in database
- [ ] Does not require basic auth
- [ ] Logs verification failures

---

### FA-6: Extend Existing Plugin Database for Webhook Type

**Goal:** Extend the existing SequenceDatabase to include webhook type (freemius/freelib/other)

**Files to modify:**
- `campaign-list-builder/src/SequenceDatabase.php` - Add `type` column and methods
- `campaign-list-builder/templates/plugins.php` - Add type selector to UI

**Implementation Steps:**

1. **Add `type` and `hmac_secret` columns to plugins table:**
   ```php
   // In SequenceDatabase::initializeDatabase()
   CREATE TABLE IF NOT EXISTS plugins (
       id TEXT PRIMARY KEY,
       name TEXT NOT NULL,
       list_id INTEGER NOT NULL,
       type TEXT DEFAULT 'freemius',      -- 'freemius', 'freelib', 'other'
       hmac_secret TEXT                    -- HMAC secret for freemius, NULL for others
   )
   ```

2. **Add migration for existing data:**
   ```php
   // Add columns if missing (migration)
   ALTER TABLE plugins ADD COLUMN type TEXT DEFAULT 'freemius';
   ALTER TABLE plugins ADD COLUMN hmac_secret TEXT;

   // Update known freelib plugins
   UPDATE plugins SET type = 'freelib'
   WHERE id IN ('swegts', 'ssfgm', 'lhf', 'sue', 'rshfd', 'faum', 'fss', 'mmt', 'cscf');
   ```

3. **Add helper methods to SequenceDatabase:**
   ```php
   public function getPluginType(string $pluginId): ?string {
       // Returns 'freemius', 'freelib', or null
   }

   public function requiresHmac(string $pluginId): bool {
       return $this->getPluginType($pluginId) === 'freemius';
   }

   public function getPluginById(string $pluginId): ?array {
       // Returns ['id' => ..., 'name' => ..., 'list_id' => ..., 'type' => ..., 'hmac_secret' => ...]
   }
   ```

4. **Update UI to allow type and secret entry:**
   - Add dropdown in plugins.php: Freemius | Freelib | Other
   - Add password field for HMAC secret (shown only when type = freemius)
   - Display type in plugin list table

**Acceptance Criteria:**
- [ ] Existing plugins table extended with `type` and `hmac_secret` columns
- [ ] UI allows selecting plugin type when adding/editing
- [ ] UI shows password field for HMAC secret (freemius only)
- [ ] `requiresHmac()` returns true only for freemius type
- [ ] Existing plugin data migrated correctly

---

### FA-7: Payload Transformation

**Goal:** Transform Freemius webhook payloads into Listmonk subscriber format

**Files to modify:**
- `campaign-list-builder/src/WebhookHandler.php`

**Implementation Steps:**

1. **Add transformation method:**
   ```php
   private function transformPayload(array $data): array {
       $user = $data['objects']['user'] ?? [];
       $install = $data['objects']['install'] ?? [];
       $pluginId = $data['plugin_id'];

       // Determine status: free, trial, or premium
       $status = $this->determineStatus($install);

       return [
           'email' => $user['email'],
           'name' => trim(($user['first'] ?? '') . ' ' . ($user['last'] ?? '')),
           'attribs' => [
               'marketing_allowed' => $user['is_marketing_allowed'] ?? false,
               'freemius_user_id' => $user['id'] ?? null,
               'country' => $install['country_code'] ?? null,
               "p{$pluginId}_status" => $status,
               "p{$pluginId}_active" => $install['is_active'] ?? true,
               "p{$pluginId}_drip_stage" => $this->getDripStage($status),
               "p{$pluginId}_drip_next" => $this->getNextDripDate(),
               "p{$pluginId}_last_event" => $data['type'] ?? 'unknown',
               "p{$pluginId}_last_seen" => $data['created'] ?? date('c'),
           ],
       ];
   }

   private function determineStatus(array $install): string {
       if (!empty($install['trial_plan_id'])) return 'trial';
       if ($install['is_premium'] && !empty($install['license_id'])) return 'premium';
       return 'free';
   }

   private function getDripStage(string $status): string {
       return $status . '_1'; // e.g., 'free_1', 'trial_1', 'premium_1'
   }
   ```

2. **Handle different event types:**
   - `install.installed` - New install, set drip_stage
   - `install.uninstalled` - Set active=false
   - `license.created` - Upgrade to premium
   - `license.cancelled` - Downgrade

**Acceptance Criteria:**
- [ ] Extracts email and name correctly
- [ ] Determines status (free/trial/premium) based on install data
- [ ] Creates proper `p{id}_*` attributes
- [ ] Sets initial drip stage based on status

---

### FA-8: Subscriber Create/Update Logic

**Goal:** Create or update subscribers in Listmonk with merged attributes

**Files to modify:**
- `campaign-list-builder/src/Listmonk.php` - Add methods if needed
- `campaign-list-builder/src/WebhookHandler.php` - Integration

**Implementation Steps:**

1. **Check if subscriber exists:**
   ```php
   $existing = $this->listmonk->findSubscriberByEmail($email);
   ```

2. **Create if not exists:**
   ```php
   if (!$existing) {
       $subscriber = $this->listmonk->createSubscriber([
           'email' => $data['email'],
           'name' => $data['name'],
           'status' => 'enabled',
           'lists' => [$listId],
           'attribs' => $data['attribs'],
       ]);
   }
   ```

3. **Update if exists (MERGE attributes):**
   ```php
   if ($existing) {
       // Merge new attribs with existing, new values win
       $mergedAttribs = array_merge(
           $existing['attribs'] ?? [],
           $data['attribs']
       );

       $this->listmonk->updateSubscriber($existing['id'], $mergedAttribs);

       // Add to list if not already member
       $currentLists = array_column($existing['lists'] ?? [], 'id');
       if (!in_array($listId, $currentLists)) {
           $this->listmonk->addSubscribersToList([$existing['id']], $listId);
       }
   }
   ```

4. **Add createSubscriber method to Listmonk.php if missing**

**Acceptance Criteria:**
- [ ] Creates new subscriber if email doesn't exist
- [ ] Updates existing subscriber with merged attributes
- [ ] Preserves existing attributes not in webhook
- [ ] Adds subscriber to plugin's list
- [ ] Does not duplicate list memberships

---

### FA-9: Deployment and Migration

**Goal:** Configure deployment settings and migrate from n8n

**Files to modify:**
- `campaign-list-builder/.env.example` - Document new vars
- `docker-compose.yml` - Pass env vars to container

**Implementation Steps:**

1. **Update .env.example:**
   ```bash
   # Optional: Dry run mode for webhook (logs but doesn't modify Listmonk)
   WEBHOOK_DRY_RUN=false
   ```

2. **Update docker-compose.yml:**
   ```yaml
   campaign-list-builder:
     environment:
       - WEBHOOK_DRY_RUN
   ```

3. **Add logging configuration:**
   - Log all webhook requests (timestamp, plugin_id, event_type)
   - Log verification failures with IP
   - Log subscriber updates (email, action)

4. **Data migration:**
   - Enter HMAC secrets for existing freemius plugins via UI
   - Verify secrets match those in current workflow-helpers

5. **Migration checklist:**
   - [ ] Deploy new endpoint
   - [ ] Enter HMAC secrets via UI for freemius plugins
   - [ ] Test with one plugin (1330/DEB) using DRY_RUN=true
   - [ ] Disable DRY_RUN and test live
   - [ ] Update Freemius webhook URL for DEB
   - [ ] Monitor logs for errors
   - [ ] Migrate remaining plugins one at a time
   - [ ] Decommission n8n workflow
   - [ ] Decommission workflow-helpers service

**Acceptance Criteria:**
- [ ] Dry run mode works for testing
- [ ] Comprehensive logging in place
- [ ] All freemius plugins have HMAC secrets configured via UI
- [ ] Migration documentation complete

---

**Full analysis documents**: `specification/FA-4/`

Status: Ready for Implementation
Planned by: Claude Code AI Assistant
Date: 2025-12-19