<div x-data="dripQueue()" x-init="init()">
    <h1>Drip Queue Monitor</h1>

    <!-- Filters -->
    <div class="grid filters-row">
        <div>
            <label for="filter-product">Product</label>
            <select id="filter-product" x-model="filters.product_id" @change="loadQueue()">
                <option value="">All Products</option>
                <template x-for="product in products" :key="product.id">
                    <option :value="product.id" x-text="`${product.name} (${product.id})`"></option>
                </template>
            </select>
        </div>
        <div>
            <label for="filter-status">Status</label>
            <select id="filter-status" x-model="filters.status" @change="loadQueue()">
                <option value="active">Active (In Queue)</option>
                <option value="due">Due Now</option>
                <option value="error">Errors</option>
                <option value="completed">Completed</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="button" class="outline" @click="refresh()" :disabled="loading">
                <span x-show="!loading">Refresh</span>
                <span x-show="loading">Loading...</span>
            </button>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="stats-grid" x-show="stats">
        <article class="stat-card">
            <header>In Queue</header>
            <p class="stat-number" x-text="stats?.in_queue ?? 0"></p>
        </article>
        <article class="stat-card due">
            <header>Due Now</header>
            <p class="stat-number" x-text="stats?.due_now ?? 0"></p>
        </article>
        <article class="stat-card error">
            <header>Errors</header>
            <p class="stat-number" x-text="stats?.errors ?? 0"></p>
        </article>
        <article class="stat-card completed">
            <header>Completed</header>
            <p class="stat-number" x-text="stats?.completed ?? 0"></p>
        </article>
    </div>

    <!-- Per-Product Stats -->
    <details x-show="Object.keys(stats?.by_product ?? {}).length > 0">
        <summary>Stats by Product</summary>
        <div class="table-wrapper">
        <table role="grid">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>In Queue</th>
                    <th>Due Now</th>
                    <th>Errors</th>
                    <th>Completed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(pstats, pid) in stats?.by_product ?? {}" :key="pid">
                    <tr>
                        <td x-text="`${pstats.name} (${pid})`"></td>
                        <td x-text="pstats.in_queue"></td>
                        <td x-text="pstats.due_now"></td>
                        <td>
                            <span :class="{ 'error-count': pstats.errors > 0 }" x-text="pstats.errors"></span>
                        </td>
                        <td x-text="pstats.completed"></td>
                        <td>
                            <button type="button" class="outline small contrast"
                                    @click="retryErrors(pid)"
                                    :disabled="pstats.errors === 0 || retrying"
                                    x-show="pstats.errors > 0">
                                Retry Errors
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        </div>
    </details>

    <!-- Queue Table -->
    <div class="table-wrapper">
        <table role="grid">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Product</th>
                    <th>List</th>
                    <th>Stage</th>
                    <th>Next Send</th>
                    <th>Failures</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="sub in subscribers" :key="sub.id">
                    <template x-for="drip in sub.drips" :key="`${sub.id}-${drip.plugin_id}`">
                        <tr :class="{
                            'error-row': drip.stage === 'error',
                            'due-row': isDue(drip.next),
                            'blocked-row': sub.status === 'blocklisted' || sub.status === 'disabled',
                            'unconfirmed-row': drip.list_status === 'unconfirmed'
                        }">
                            <td>
                                <span x-text="sub.email"></span>
                                <small x-show="sub.name" x-text="` (${sub.name})`" class="muted"></small>
                            </td>
                            <td>
                                <span class="status-badge" :class="sub.status" x-text="sub.status"></span>
                            </td>
                            <td x-text="`${drip.product_name}`"></td>
                            <td>
                                <span class="list-status-badge" :class="drip.list_status?.replace(' ', '-')" x-text="drip.list_status"></span>
                            </td>
                            <td>
                                <span class="stage-badge" :class="drip.stage" x-text="drip.stage"></span>
                            </td>
                            <td x-text="formatDate(drip.next)"></td>
                            <td>
                                <span :class="{ 'error-count': drip.failures > 0 }" x-text="drip.failures"></span>
                            </td>
                            <td>
                                <button type="button" class="outline small"
                                        @click="resetSubscriber(sub.id, drip.plugin_id)"
                                        :disabled="resetting">
                                    Reset
                                </button>
                            </td>
                        </tr>
                    </template>
                </template>
                <tr x-show="subscribers.length === 0 && !loading">
                    <td colspan="8" class="empty-message">No subscribers found matching filters.</td>
                </tr>
                <tr x-show="loading">
                    <td colspan="8" class="empty-message">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination" x-show="total > perPage">
        <button type="button" class="outline" @click="prevPage()" :disabled="page === 1">Previous</button>
        <span class="page-info">Page <span x-text="page"></span> of <span x-text="totalPages"></span> (<span x-text="total"></span> total)</span>
        <button type="button" class="outline" @click="nextPage()" :disabled="page >= totalPages">Next</button>
    </div>

    <!-- Status Messages -->
    <div x-show="message" x-transition class="status-message" :class="messageType" x-text="message"></div>
</div>

<style>
.table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 1rem;
}
.filters-row {
    margin-bottom: 1rem;
    align-items: end;
}
.filter-actions {
    display: flex;
    align-items: flex-end;
    padding-bottom: 0.25rem;
}
.filter-actions button {
    margin: 0;
    width: auto;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    text-align: center;
    margin: 0;
    padding: 0.5rem;
}
.stat-card header {
    font-size: 0.85rem;
    color: var(--pico-muted-color);
    padding: 0.25rem;
}
.stat-card .stat-number {
    font-size: 2rem;
    font-weight: bold;
    margin: 0;
    padding: 0.5rem;
}
.stat-card.due header {
    color: var(--pico-primary);
}
.stat-card.error header {
    color: var(--pico-del-color);
}
.stat-card.completed header {
    color: var(--pico-ins-color);
}
.error-row {
    background-color: rgba(255, 0, 0, 0.05);
}
.due-row {
    background-color: rgba(0, 100, 255, 0.05);
}
.error-count {
    color: var(--pico-del-color);
    font-weight: bold;
}
.stage-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.85rem;
    background: var(--pico-muted-border-color);
}
.stage-badge.error {
    background: var(--pico-del-color);
    color: white;
}
.stage-badge.complete {
    background: var(--pico-ins-color);
    color: white;
}
.status-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.75rem;
    text-transform: capitalize;
}
.status-badge.enabled {
    background: var(--pico-ins-color);
    color: white;
}
.status-badge.disabled {
    background: var(--pico-muted-color);
    color: white;
}
.status-badge.blocklisted {
    background: var(--pico-del-color);
    color: white;
}
.list-status-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.75rem;
    text-transform: capitalize;
}
.list-status-badge.confirmed,
.list-status-badge.direct {
    background: var(--pico-ins-color);
    color: white;
}
.list-status-badge.unconfirmed {
    background: #f0ad4e;
    color: white;
}
.list-status-badge.unsubscribed {
    background: var(--pico-del-color);
    color: white;
}
.list-status-badge.not-subscribed,
.list-status-badge.no-list {
    background: var(--pico-muted-color);
    color: white;
}
.blocked-row {
    background-color: rgba(255, 0, 0, 0.1);
}
.unconfirmed-row {
    background-color: rgba(240, 173, 78, 0.1);
}
.muted {
    color: var(--pico-muted-color);
}
.small {
    padding: 0.25rem 0.5rem !important;
    font-size: 0.85rem !important;
}
.empty-message {
    text-align: center;
    color: var(--pico-muted-color);
    padding: 2rem;
}
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 1rem;
}
.pagination button {
    width: auto;
    margin: 0;
}
.page-info {
    color: var(--pico-muted-color);
}
.status-message {
    position: fixed;
    bottom: 1rem;
    right: 1rem;
    padding: 1rem;
    border-radius: var(--pico-border-radius);
    z-index: 1000;
}
.status-message.success {
    background: var(--pico-ins-color);
    color: white;
}
.status-message.error {
    background: var(--pico-del-color);
    color: white;
}
details {
    margin-bottom: 1.5rem;
}
details summary {
    cursor: pointer;
}
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
function dripQueue() {
    return {
        products: [],
        subscribers: [],
        stats: null,
        filters: {
            product_id: '',
            status: 'active'
        },
        page: 1,
        perPage: 50,
        total: 0,
        loading: false,
        resetting: false,
        retrying: false,
        message: '',
        messageType: 'success',

        get totalPages() {
            return Math.ceil(this.total / this.perPage);
        },

        async init() {
            await this.loadProducts();
            await Promise.all([this.loadStats(), this.loadQueue()]);
        },

        async loadProducts() {
            try {
                const response = await fetch('/api/drip/products');
                const data = await response.json();
                this.products = data.data || [];
            } catch (error) {
                this.showMessage('Failed to load products', 'error');
            }
        },

        async loadStats() {
            try {
                const response = await fetch('/api/drip/queue/stats');
                const data = await response.json();
                this.stats = data.data || null;
            } catch (error) {
                console.error('Failed to load stats:', error);
            }
        },

        async loadQueue() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.page,
                    per_page: this.perPage
                });
                if (this.filters.product_id) {
                    params.append('plugin_id', this.filters.product_id); // API uses plugin_id for backward compat
                }
                if (this.filters.status) {
                    params.append('status', this.filters.status);
                }

                const response = await fetch(`/api/drip/queue?${params}`);
                const data = await response.json();

                this.subscribers = data.data || [];
                this.total = data.total || 0;
            } catch (error) {
                this.showMessage('Failed to load queue', 'error');
            }
            this.loading = false;
        },

        async refresh() {
            this.page = 1;
            await Promise.all([this.loadStats(), this.loadQueue()]);
        },

        async resetSubscriber(subscriberId, pluginId) {
            if (!confirm('Reset this subscriber\'s drip sequence?')) return;

            this.resetting = true;
            try {
                const response = await fetch('/api/drip/queue/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscriber_id: subscriberId,
                        plugin_id: pluginId
                    })
                });

                if (response.ok) {
                    this.showMessage('Subscriber reset successfully', 'success');
                    await this.refresh();
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to reset subscriber', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to reset subscriber', 'error');
            }
            this.resetting = false;
        },

        async retryErrors(productId) {
            const product = this.products.find(p => p.id === productId);
            const productName = product?.name || productId;

            if (!confirm(`Retry all error subscribers for ${productName}?`)) return;

            this.retrying = true;
            try {
                const response = await fetch('/api/drip/queue/retry-errors', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ plugin_id: productId }) // API uses plugin_id for backward compat
                });

                if (response.ok) {
                    const data = await response.json();
                    this.showMessage(`Reset ${data.reset_count} subscribers`, 'success');
                    await this.refresh();
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to retry errors', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to retry errors', 'error');
            }
            this.retrying = false;
        },

        prevPage() {
            if (this.page > 1) {
                this.page--;
                this.loadQueue();
            }
        },

        nextPage() {
            if (this.page < this.totalPages) {
                this.page++;
                this.loadQueue();
            }
        },

        isDue(dateStr) {
            if (!dateStr) return false;
            return new Date(dateStr) <= new Date();
        },

        formatDate(dateStr) {
            if (!dateStr) return '-';

            const date = new Date(dateStr);
            const now = new Date();
            const diffMs = date - now;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);

            if (diffMs < 0) {
                // Past
                if (diffMins > -60) return 'Due now';
                if (diffHours > -24) return `${Math.abs(diffHours)}h overdue`;
                return `${Math.abs(diffDays)}d overdue`;
            } else {
                // Future
                if (diffMins < 60) return `In ${diffMins}m`;
                if (diffHours < 24) return `In ${diffHours}h`;
                return `In ${diffDays}d`;
            }
        },

        showMessage(text, type = 'success') {
            this.message = text;
            this.messageType = type;
            setTimeout(() => {
                this.message = '';
            }, 3000);
        }
    };
}
</script>
