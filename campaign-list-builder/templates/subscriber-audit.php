<div x-data="subscriberAudit()" x-init="init()">
    <h1>Subscriber Audit</h1>

    <p>Enter an email address to see all drip-related data and diagnose why emails may or may not be sending.</p>
    <!-- Search Form -->
    <form @submit.prevent="search()">
        <div class="grid">
            <input type="email"
                   x-model="email"
                   placeholder="Enter subscriber email address"
                   required
                   autofocus>
            <button type="submit" :disabled="loading" :aria-busy="loading">
                <span x-show="!loading">Search</span>
                <span x-show="loading">Searching...</span>
            </button>
        </div>
    </form>

    <!-- Error Message -->
    <template x-if="error">
        <article style="background-color: #ffebee; border-left: 4px solid #f44336;">
            <p x-text="error"></p>
        </article>
    </template>

    <!-- Results -->
    <template x-if="audit">
        <div>
            <!-- Subscriber Info Card -->
            <article>
                <header>
                    <h3>Subscriber: <span x-text="audit.subscriber.email"></span></h3>
                </header>

                <div class="grid">
                    <div>
                        <strong>ID:</strong> <span x-text="audit.subscriber.id"></span><br>
                        <strong>Name:</strong> <span x-text="audit.subscriber.name || '(not set)'"></span><br>
                        <strong>UUID:</strong> <code x-text="audit.subscriber.uuid"></code>
                    </div>
                    <div>
                        <strong>Status:</strong>
                        <span :class="{'text-success': audit.subscriber.status === 'enabled', 'text-danger': audit.subscriber.status !== 'enabled'}"
                              x-text="audit.subscriber.status"></span><br>
                        <strong>Created:</strong> <span x-text="formatDate(audit.subscriber.created_at)"></span><br>
                        <strong>Updated:</strong> <span x-text="formatDate(audit.subscriber.updated_at)"></span>
                    </div>
                </div>
            </article>

            <!-- Global Checks Card -->
            <article>
                <header>
                    <h4>Global Checks</h4>
                </header>

                <table>
                    <tbody>
                        <tr>
                            <td><strong>marketing_allowed</strong></td>
                            <td>
                                <span :class="audit.global_checks.marketing_allowed ? 'badge-success' : 'badge-danger'"
                                      x-text="audit.global_checks.marketing_allowed ? 'YES' : 'NO'"></span>
                                <small x-show="audit.global_checks.marketing_allowed_raw !== audit.global_checks.marketing_allowed">
                                    (raw value: <code x-text="JSON.stringify(audit.global_checks.marketing_allowed_raw)"></code>)
                                </small>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Subscriber Status</strong></td>
                            <td>
                                <span :class="audit.global_checks.is_blocked ? 'badge-danger' : 'badge-success'"
                                      x-text="audit.global_checks.subscriber_status"></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Can Receive Drips?</strong></td>
                            <td>
                                <span :class="audit.global_checks.can_receive_drips ? 'badge-success' : 'badge-danger'"
                                      x-text="audit.global_checks.can_receive_drips ? 'YES' : 'NO'"></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Audit Time</strong></td>
                            <td x-text="audit.global_checks.audit_time_human"></td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <!-- Lists Card -->
            <article x-show="audit.lists && audit.lists.length > 0">
                <header>
                    <h4>List Subscriptions (<span x-text="audit.lists.length"></span>)</h4>
                </header>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Subscription Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="list in audit.lists" :key="list.id">
                            <tr>
                                <td x-text="list.id"></td>
                                <td x-text="list.name"></td>
                                <td x-text="list.type"></td>
                                <td>
                                    <span :class="{
                                        'badge-success': list.subscription_status === 'confirmed',
                                        'badge-warning': list.subscription_status === 'unconfirmed',
                                        'badge-danger': list.subscription_status === 'unsubscribed'
                                    }" x-text="list.subscription_status"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </article>

            <!-- Bounces Card -->
            <article x-show="audit.bounces && audit.bounces.length > 0">
                <header>
                    <h4>Bounces (<span x-text="audit.bounces.length"></span>)</h4>
                </header>

                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Source</th>
                            <th>Campaign</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="bounce in audit.bounces" :key="bounce.id">
                            <tr>
                                <td>
                                    <span :class="bounce.type === 'hard' ? 'badge-danger' : 'badge-warning'"
                                          x-text="bounce.type"></span>
                                </td>
                                <td x-text="bounce.source"></td>
                                <td x-text="bounce.campaign?.name || '-'"></td>
                                <td x-text="formatDate(bounce.created_at)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </article>

            <!-- Dunning State Card (Double Opt-In Reminders) - FA-16: Now an array -->
            <article x-show="audit.dunning && audit.dunning.length > 0" style="border-left: 4px solid #ff9800;">
                <header>
                    <h4>Double Opt-In Dunning (<span x-text="audit.dunning.length"></span>)</h4>
                </header>

                <div style="background: #fff3e0; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                    <p style="margin: 0;">This subscriber is in the double opt-in dunning process. They have not confirmed their email subscription yet.</p>
                </div>

                <template x-for="dunning in audit.dunning" :key="dunning.list_id">
                    <table style="margin-bottom: 16px;">
                        <tr>
                            <td><strong>List</strong></td>
                            <td>
                                <span x-text="dunning.list_name || 'Unknown'"></span>
                                <small>(ID: <span x-text="dunning.list_id"></span>)</small>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Current Stage</strong></td>
                            <td>
                                <span class="badge-warning" x-text="dunning.stage_human"></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Next Reminder</strong></td>
                            <td>
                                <span x-text="dunning.next_human || '(not set)'"></span>
                                <span x-show="dunning.is_due" class="badge-success">DUE NOW</span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Dunning Started</strong></td>
                            <td x-text="dunning.started_human || '(not set)'"></td>
                        </tr>
                    </table>
                </template>
            </article>

            <!-- Product Details -->
            <template x-for="(productData, productId) in audit.products" :key="productId">
                <article :style="productData.decision.would_send_now ? 'border-left: 4px solid #4caf50;' : 'border-left: 4px solid #ff9800;'">
                    <header>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h4>
                                <span x-text="productData.product_name"></span>
                                <small>(ID: <span x-text="productId"></span>)</small>
                            </h4>
                            <span :class="productData.decision.would_send_now ? 'badge-success' : 'badge-warning'"
                                  style="font-size: 0.9em; padding: 4px 12px;"
                                  x-text="productData.decision.would_send_now ? 'WOULD SEND' : 'BLOCKED'"></span>
                        </div>
                    </header>

                    <!-- Decision Summary -->
                    <div x-show="!productData.decision.would_send_now"
                         style="background: #fff3e0; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                        <strong>Block Reasons:</strong>
                        <ul style="margin: 8px 0 0 0;">
                            <template x-for="reason in productData.decision.block_reasons" :key="reason">
                                <li x-text="reason"></li>
                            </template>
                        </ul>
                    </div>

                    <div class="grid">
                        <!-- Drip State -->
                        <div>
                            <h5>Drip State</h5>
                            <table>
                                <tr>
                                    <td><strong>Status Type</strong></td>
                                    <td><code x-text="productData.status_type"></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Current Stage</strong></td>
                                    <td><code x-text="productData.drip.stage"></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Next Send</strong></td>
                                    <td>
                                        <span x-text="productData.drip.next_date_human || '(not set)'"></span>
                                        <span x-show="productData.drip.is_due" class="badge-success">DUE NOW</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Failures</strong></td>
                                    <td>
                                        <span :class="productData.drip.failures >= 3 ? 'badge-danger' : ''"
                                              x-text="productData.drip.failures"></span>
                                        <span x-show="productData.drip.failures >= 3"> (max reached)</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Started</strong></td>
                                    <td x-text="productData.drip.started"></td>
                                </tr>
                            </table>
                        </div>

                        <!-- List Info -->
                        <div x-show="productData.list">
                            <h5>Product List</h5>
                            <table>
                                <tr>
                                    <td><strong>List ID</strong></td>
                                    <td x-text="productData.list?.id"></td>
                                </tr>
                                <tr>
                                    <td><strong>List Name</strong></td>
                                    <td x-text="productData.list?.name"></td>
                                </tr>
                                <tr>
                                    <td><strong>Opt-in Type</strong></td>
                                    <td>
                                        <span :class="productData.list?.optin_type === 'double' ? 'badge-warning' : 'badge-success'"
                                              x-text="productData.list?.optin_type"></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Subscription</strong></td>
                                    <td>
                                        <span :class="{
                                            'badge-success': productData.list?.subscription_status === 'confirmed',
                                            'badge-warning': productData.list?.subscription_status === 'unconfirmed',
                                            'badge-danger': productData.list?.subscription_status === 'unsubscribed' || !productData.list?.is_subscribed
                                        }" x-text="productData.list?.subscription_status || 'not subscribed'"></span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Current Step -->
                    <div x-show="productData.current_step" style="margin-top: 16px;">
                        <h5>Current Step</h5>
                        <table>
                            <tr>
                                <td><strong>Stage</strong></td>
                                <td><code x-text="productData.current_step?.stage"></code></td>
                            </tr>
                            <tr>
                                <td><strong>Subject</strong></td>
                                <td x-text="productData.current_step?.subject"></td>
                            </tr>
                            <tr>
                                <td><strong>Template</strong></td>
                                <td>
                                    <span x-text="productData.template?.name || 'Template #' + productData.current_step?.template_id"></span>
                                    <small>(ID: <span x-text="productData.current_step?.template_id"></span>)</small>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Enabled</strong></td>
                                <td x-text="productData.current_step?.enabled ? 'Yes' : 'No'"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Next Step -->
                    <div x-show="productData.next_step" style="margin-top: 16px;">
                        <h5>Next Step</h5>
                        <template x-if="typeof productData.next_step === 'string'">
                            <p x-text="productData.next_step"></p>
                        </template>
                        <template x-if="typeof productData.next_step === 'object' && productData.next_step">
                            <table>
                                <tr>
                                    <td><strong>Stage</strong></td>
                                    <td><code x-text="productData.next_step?.stage"></code></td>
                                </tr>
                                <tr>
                                    <td><strong>Subject</strong></td>
                                    <td x-text="productData.next_step?.subject"></td>
                                </tr>
                                <tr>
                                    <td><strong>Delay</strong></td>
                                    <td><span x-text="productData.next_step?.delay_days"></span> days after current</td>
                                </tr>
                            </table>
                        </template>
                    </div>

                    <!-- Sequence Progress -->
                    <div x-show="productData.sequence.stages && productData.sequence.stages.length > 0" style="margin-top: 16px;">
                        <h5>Sequence Progress (<span x-text="productData.sequence.type"></span>)</h5>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <template x-for="stage in productData.sequence.stages" :key="stage.stage">
                                <span :class="{
                                    'stage-current': stage.is_current,
                                    'stage-disabled': !stage.enabled
                                }"
                                :title="stage.subject"
                                style="padding: 4px 8px; border-radius: 4px; font-size: 0.85em;"
                                x-text="stage.stage"></span>
                            </template>
                        </div>
                    </div>
                </article>
            </template>

            <!-- No Product Data Message -->
            <article x-show="Object.keys(audit.products).length === 0">
                <p>This subscriber has no drip sequence data for any configured products.</p>
            </article>

            <!-- Raw Data (Collapsible) -->
            <details>
                <summary>SQLite Database (Source of Truth)</summary>
                <div style="background: #e3f2fd; padding: 12px; border-radius: 4px; margin-bottom: 8px;">
                    <strong>subscribers table:</strong>
                </div>
                <pre style="background: #f5f5f5; padding: 16px; overflow-x: auto; font-size: 0.85em; margin-bottom: 16px;"><code x-text="JSON.stringify(audit.sqlite_data?.subscriber, null, 2) || 'null'"></code></pre>

                <div style="background: #e3f2fd; padding: 12px; border-radius: 4px; margin-bottom: 8px;">
                    <strong>subscriber_drips table:</strong>
                </div>
                <pre style="background: #f5f5f5; padding: 16px; overflow-x: auto; font-size: 0.85em; margin-bottom: 16px;"><code x-text="JSON.stringify(audit.sqlite_data?.drips, null, 2) || '[]'"></code></pre>

                <div style="background: #e3f2fd; padding: 12px; border-radius: 4px; margin-bottom: 8px;">
                    <strong>subscriber_dunning table:</strong>
                </div>
                <pre style="background: #f5f5f5; padding: 16px; overflow-x: auto; font-size: 0.85em;"><code x-text="JSON.stringify(audit.sqlite_data?.dunning, null, 2) || '[]'"></code></pre>
            </details>

            <details>
                <summary>Listmonk Attributes (Legacy)</summary>
                <pre style="background: #f5f5f5; padding: 16px; overflow-x: auto; font-size: 0.85em;"><code x-text="JSON.stringify(audit.listmonk_attributes, null, 2)"></code></pre>
            </details>
        </div>
    </template>
</div>

<style>
    .badge-success {
        background-color: #4caf50;
        color: white;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.85em;
    }
    .badge-warning {
        background-color: #ff9800;
        color: white;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.85em;
    }
    .badge-danger {
        background-color: #f44336;
        color: white;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.85em;
    }
    .text-success { color: #4caf50; }
    .text-danger { color: #f44336; }
    .stage-current {
        background-color: #2196f3;
        color: white;
        font-weight: bold;
    }
    .stage-disabled {
        background-color: #e0e0e0;
        color: #999;
        text-decoration: line-through;
    }
    span:not(.badge-success):not(.badge-warning):not(.badge-danger):not(.stage-current):not(.stage-disabled) {
        /* Default stage styling */
    }
    .grid > div > table {
        margin: 0;
    }
    .grid > div > table td {
        padding: 4px 8px;
    }
    article header h4 {
        margin: 0;
    }
    details {
        margin-top: 24px;
    }
</style>

<script>
function subscriberAudit() {
    return {
        email: '',
        loading: false,
        error: null,
        audit: null,

        init() {
            // Check for email in URL params
            const params = new URLSearchParams(window.location.search);
            const emailParam = params.get('email');
            if (emailParam) {
                this.email = emailParam;
                this.search();
            }
        },

        async search() {
            if (!this.email) return;

            this.loading = true;
            this.error = null;
            this.audit = null;

            try {
                const response = await fetch(`/api/drip/audit?email=${encodeURIComponent(this.email)}`);
                const data = await response.json();

                if (!response.ok) {
                    this.error = data.error || 'Failed to fetch subscriber data';
                    return;
                }

                this.audit = data.data;

                // Update URL without reload
                const url = new URL(window.location);
                url.searchParams.set('email', this.email);
                window.history.pushState({}, '', url);

            } catch (e) {
                this.error = 'Network error: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '-';
            try {
                return new Date(dateStr).toLocaleString();
            } catch (e) {
                return dateStr;
            }
        }
    };
}
</script>