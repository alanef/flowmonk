<div x-data="dripStats()" x-init="init()">
    <h1>Drip Statistics</h1>

    <!-- Loading State -->
    <div x-show="loading" class="loading-message">Loading statistics...</div>

    <!-- Error State -->
    <div x-show="!loading && error" class="error-state">
        <p class="error-icon">⚠</p>
        <p class="error-text" x-text="error"></p>
        <button type="button" class="outline" @click="refresh()">Retry</button>
    </div>

    <!-- Summary Cards -->
    <div class="stats-summary" x-show="!loading && !error">
        <article class="stat-card">
            <header>Active in Queue</header>
            <p class="stat-number" x-text="stats?.summary?.total_active ?? 0"></p>
        </article>
        <article class="stat-card completed">
            <header>Completed (All Time)</header>
            <p class="stat-number" x-text="stats?.summary?.completed_all_time ?? 0"></p>
        </article>
        <article class="stat-card highlight">
            <header>Completed This Week</header>
            <p class="stat-number" x-text="stats?.summary?.completed_this_week ?? 0"></p>
        </article>
        <article class="stat-card highlight">
            <header>Completed This Month</header>
            <p class="stat-number" x-text="stats?.summary?.completed_this_month ?? 0"></p>
        </article>
        <article class="stat-card" :class="{ 'error': (stats?.summary?.errors ?? 0) > 0 }">
            <header>Errors</header>
            <p class="stat-number" x-text="stats?.summary?.errors ?? 0"></p>
        </article>
        <article class="stat-card" :class="{ 'warning': (stats?.summary?.blocklisted ?? 0) > 0 }">
            <header>Blocklisted</header>
            <p class="stat-number" x-text="stats?.summary?.blocklisted ?? 0"></p>
            <small class="stat-hint">Hard bounces, spam reports</small>
        </article>
        <article class="stat-card" :class="{ 'muted-card': (stats?.summary?.disabled ?? 0) > 0 }">
            <header>Disabled</header>
            <p class="stat-number" x-text="stats?.summary?.disabled ?? 0"></p>
            <small class="stat-hint">Manually disabled</small>
        </article>
    </div>

    <!-- Time Comparison -->
    <section x-show="!loading && stats?.time_comparison" class="time-comparison">
        <h2>Week-over-Week Comparison</h2>
        <div class="table-wrapper">
            <table role="grid">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>This Week</th>
                        <th>Last Week</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Completed</td>
                        <td x-text="stats?.time_comparison?.this_week?.completed ?? 0"></td>
                        <td x-text="stats?.time_comparison?.last_week?.completed ?? 0"></td>
                        <td>
                            <span :class="getChangeClass(stats?.time_comparison?.this_week?.completed, stats?.time_comparison?.last_week?.completed)"
                                  x-text="formatChange(stats?.time_comparison?.this_week?.completed, stats?.time_comparison?.last_week?.completed)">
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- List Subscription Stats -->
    <section x-show="!loading && Object.keys(stats?.list_stats ?? {}).length > 0" class="list-stats">
        <h2>List Subscription Status</h2>
        <div class="table-wrapper">
            <table role="grid">
                <thead>
                    <tr>
                        <th>List</th>
                        <th>Confirmed</th>
                        <th>Week Δ</th>
                        <th>Month Δ</th>
                        <th>Unconfirmed</th>
                        <th>Unsubscribed</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(listData, listId) in stats?.list_stats ?? {}" :key="listId">
                        <tr>
                            <td>
                                <span x-text="listData.name"></span>
                            </td>
                            <td><span class="count-confirmed" x-text="listData.confirmed"></span></td>
                            <td>
                                <span :class="getDeltaClass(listData.delta_week)"
                                      x-text="formatDelta(listData.delta_week)"></span>
                            </td>
                            <td>
                                <span :class="getDeltaClass(listData.delta_month)"
                                      x-text="formatDelta(listData.delta_month)"></span>
                            </td>
                            <td>
                                <span x-show="listData.optin === 'double'" class="count-unconfirmed" :class="{ 'warning': listData.unconfirmed > 0 }" x-text="listData.unconfirmed"></span>
                                <span x-show="listData.optin !== 'double'" class="count-na">N/A</span>
                            </td>
                            <td><span class="count-unsubscribed" :class="{ 'error': listData.unsubscribed > 0 }" x-text="listData.unsubscribed"></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <small class="stats-hint">Deltas show change in confirmed subscribers. Data builds over time - week/month comparisons available after 7/30 days.</small>
    </section>

    <!-- Per-Product Funnels -->
    <section x-show="!loading && Object.keys(stats?.by_product ?? {}).length > 0" class="product-funnels">
        <h2>Per-Product Funnel</h2>

        <template x-for="(productData, productId) in stats?.by_product ?? {}" :key="productId">
            <article class="product-funnel">
                <header>
                    <span x-text="productData.name"></span>
                    <small class="product-id" x-text="`(${productId})`"></small>
                    <span class="product-active-count" x-text="`${productData.total_active ?? 0} active`"></span>
                </header>

                <!-- Funnel visualization -->
                <div class="funnel-stages">
                    <template x-for="(count, stage) in productData.funnel" :key="stage">
                        <div class="funnel-stage" :class="getFunnelStageClass(stage)">
                            <span class="stage-name" x-text="formatStageName(stage)"></span>
                            <span class="stage-count" x-text="count"></span>
                        </div>
                    </template>
                </div>

                <!-- Product time stats -->
                <div class="product-time-stats">
                    <span class="time-stat">
                        <strong x-text="productData.this_week?.completed ?? 0"></strong> completed this week
                    </span>
                    <span class="time-stat">
                        <strong x-text="productData.this_month?.completed ?? 0"></strong> completed this month
                    </span>
                </div>
            </article>
        </template>
    </section>

    <!-- Dunning Stats (Double Opt-In Confirmation Reminders) -->
    <section x-show="!loading && stats?.dunning" class="dunning-stats">
        <h2>Double Opt-In Dunning</h2>
        <p class="section-description">Subscribers waiting to confirm their email subscription. Reminders are sent at day 1, 3, 7, and 14. Subscribers who never confirm are blocklisted at day 21.</p>

        <div class="dunning-summary">
            <div class="dunning-total">
                <span class="dunning-label">In Dunning Queue:</span>
                <span class="dunning-count" :class="{ 'warning': stats?.dunning?.total_in_dunning > 0 }" x-text="stats?.dunning?.total_in_dunning ?? 0"></span>
            </div>
            <div class="dunning-blocklisted">
                <span class="dunning-label">Blocklisted (Never Confirmed):</span>
                <span class="dunning-count" :class="{ 'error': stats?.dunning?.blocklisted_via_dunning > 0 }" x-text="stats?.dunning?.blocklisted_via_dunning ?? 0"></span>
            </div>
        </div>

        <div class="dunning-stages" x-show="stats?.dunning?.total_in_dunning > 0">
            <template x-for="(count, stage) in stats?.dunning?.by_stage ?? {}" :key="stage">
                <div class="dunning-stage" :class="getDunningStageClass(stage)">
                    <span class="stage-name" x-text="formatDunningStageName(stage)"></span>
                    <span class="stage-count" x-text="count"></span>
                </div>
            </template>
        </div>
    </section>

    <!-- Refresh Button -->
    <div class="actions" x-show="!loading">
        <button type="button" class="outline" @click="refresh()" :disabled="loading">
            Refresh Stats
        </button>
    </div>

    <!-- Status Messages -->
    <div x-show="message" x-transition class="status-message" :class="messageType" x-text="message"></div>
</div>

<style>
.loading-message {
    text-align: center;
    padding: 3rem;
    color: var(--pico-muted-color);
}
.error-state {
    text-align: center;
    padding: 3rem;
    background: rgba(255, 0, 0, 0.05);
    border: 1px solid var(--pico-del-color);
    border-radius: var(--pico-border-radius);
    margin-bottom: 2rem;
}
.error-state .error-icon {
    font-size: 3rem;
    margin: 0 0 1rem 0;
}
.error-state .error-text {
    color: var(--pico-del-color);
    font-weight: 500;
    margin-bottom: 1rem;
}
.error-state button {
    width: auto;
}
.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    text-align: center;
    margin: 0;
    padding: 0.5rem;
}
.stat-card header {
    font-size: 0.8rem;
    color: var(--pico-muted-color);
    padding: 0.25rem;
    margin-bottom: 0;
}
.stat-card .stat-number {
    font-size: 2rem;
    font-weight: bold;
    margin: 0;
    padding: 0.5rem;
}
.stat-card.completed header {
    color: var(--pico-ins-color);
}
.stat-card.highlight header {
    color: var(--pico-primary);
}
.stat-card.error header {
    color: var(--pico-del-color);
}
.stat-card.error .stat-number {
    color: var(--pico-del-color);
}
.stat-card.warning header {
    color: #f0ad4e;
}
.stat-card.warning .stat-number {
    color: #f0ad4e;
}
.stat-card.muted-card header {
    color: var(--pico-muted-color);
}
.stat-hint {
    display: block;
    font-size: 0.7rem;
    color: var(--pico-muted-color);
    margin-top: -0.25rem;
}
.list-stats {
    margin-bottom: 2rem;
}
.list-stats h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
}
.list-stats .list-id {
    color: var(--pico-muted-color);
    font-size: 0.8rem;
    margin-left: 0.25rem;
}
.list-stats th {
    text-align: center;
}
.list-stats td {
    text-align: center;
}
.list-stats .row-label {
    text-align: left;
    font-weight: bold;
    color: var(--pico-muted-color);
}
.optin-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.75rem;
    text-transform: capitalize;
}
.optin-badge.double {
    background: var(--pico-primary);
    color: white;
}
.optin-badge.single {
    background: var(--pico-muted-border-color);
    color: var(--pico-color);
}
.count-confirmed {
    color: var(--pico-ins-color);
    font-weight: bold;
}
.count-unconfirmed {
    color: var(--pico-color);
}
.count-unconfirmed.warning {
    color: #f0ad4e;
    font-weight: bold;
}
.count-unsubscribed {
    color: var(--pico-color);
}
.count-unsubscribed.error {
    color: var(--pico-del-color);
    font-weight: bold;
}
.count-na {
    color: var(--pico-muted-color);
}
.delta-positive {
    color: var(--pico-ins-color);
    font-weight: bold;
}
.delta-negative {
    color: var(--pico-del-color);
    font-weight: bold;
}
.delta-neutral {
    color: var(--pico-muted-color);
}
.delta-none {
    color: var(--pico-muted-color);
}
.stats-hint {
    display: block;
    margin-top: 0.5rem;
    color: var(--pico-muted-color);
}
.time-comparison {
    margin-bottom: 2rem;
}
.time-comparison h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
}
.change-positive {
    color: var(--pico-ins-color);
    font-weight: bold;
}
.change-negative {
    color: var(--pico-del-color);
    font-weight: bold;
}
.change-neutral {
    color: var(--pico-muted-color);
}
.product-funnels {
    margin-bottom: 2rem;
}
.product-funnels h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
}
.product-funnel {
    margin-bottom: 1rem;
}
.product-funnel header {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    margin-bottom: 0;
}
.product-funnel .product-id {
    color: var(--pico-muted-color);
    font-size: 0.8rem;
}
.product-funnel .product-active-count {
    margin-left: auto;
    background: var(--pico-primary);
    color: white;
    padding: 0.2rem 0.6rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.8rem;
}
.funnel-stages {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 1rem;
}
.funnel-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: var(--pico-border-radius);
    background: var(--pico-muted-border-color);
    min-width: 80px;
}
.funnel-stage .stage-name {
    font-size: 0.75rem;
    color: var(--pico-muted-color);
    text-transform: capitalize;
}
.funnel-stage .stage-count {
    font-size: 1.5rem;
    font-weight: bold;
}
.funnel-stage.complete {
    background: var(--pico-ins-color);
    color: white;
}
.funnel-stage.complete .stage-name {
    color: rgba(255,255,255,0.8);
}
.funnel-stage.error {
    background: var(--pico-del-color);
    color: white;
}
.funnel-stage.error .stage-name {
    color: rgba(255,255,255,0.8);
}
.product-time-stats {
    display: flex;
    gap: 2rem;
    padding: 0 1rem 1rem;
    font-size: 0.9rem;
    color: var(--pico-muted-color);
}
.product-time-stats .time-stat strong {
    color: var(--pico-color);
}
.actions {
    margin-top: 2rem;
}
.actions button {
    width: auto;
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
/* Dunning stats section */
.dunning-stats {
    margin-bottom: 2rem;
}
.dunning-stats h2 {
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
}
.section-description {
    color: var(--pico-muted-color);
    font-size: 0.9rem;
    margin-bottom: 1rem;
}
.dunning-summary {
    display: flex;
    gap: 2rem;
    margin-bottom: 1rem;
}
.dunning-total, .dunning-blocklisted {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.dunning-label {
    font-weight: 500;
}
.dunning-count {
    font-size: 1.5rem;
    font-weight: bold;
}
.dunning-count.warning {
    color: #f0ad4e;
}
.dunning-count.error {
    color: var(--pico-del-color);
}
.dunning-stages {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.dunning-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: var(--pico-border-radius);
    background: var(--pico-muted-border-color);
    min-width: 90px;
}
.dunning-stage .stage-name {
    font-size: 0.75rem;
    color: var(--pico-muted-color);
}
.dunning-stage .stage-count {
    font-size: 1.25rem;
    font-weight: bold;
}
.dunning-stage.day-1 {
    background: #fff3cd;
    border: 1px solid #ffc107;
}
.dunning-stage.day-3 {
    background: #ffe0b2;
    border: 1px solid #ff9800;
}
.dunning-stage.day-7 {
    background: #ffccbc;
    border: 1px solid #ff5722;
}
.dunning-stage.day-14 {
    background: #ffcdd2;
    border: 1px solid #f44336;
}
.dunning-stage.blocklist {
    background: var(--pico-del-color);
    color: white;
}
.dunning-stage.blocklist .stage-name {
    color: rgba(255,255,255,0.8);
}

/* Mobile responsive tables */
@media (max-width: 768px) {
    .time-comparison table,
    .list-stats table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .stats-summary {
        grid-template-columns: repeat(2, 1fr);
    }

    .stat-card .stat-number {
        font-size: 1.5rem;
    }

    .dunning-summary {
        flex-direction: column;
        gap: 1rem;
    }

    .product-funnel header {
        flex-wrap: wrap;
    }

    .product-funnel .product-active-count {
        margin-left: 0;
        margin-top: 0.5rem;
    }

    .product-time-stats {
        flex-direction: column;
        gap: 0.5rem;
    }

    .funnel-stages {
        justify-content: center;
    }

    .funnel-stage {
        min-width: 70px;
        padding: 0.4rem 0.8rem;
    }

    .funnel-stage .stage-count {
        font-size: 1.2rem;
    }
}

@media (max-width: 480px) {
    .stats-summary {
        grid-template-columns: 1fr;
    }

    h1 {
        font-size: 1.5rem;
    }

    h2 {
        font-size: 1.1rem;
    }
}
</style>

<script>
function dripStats() {
    return {
        stats: null,
        loading: false,
        error: null,
        message: '',
        messageType: 'success',

        async init() {
            await this.loadStats();
        },

        async loadStats() {
            this.loading = true;
            this.error = null;
            try {
                const response = await fetch('/api/drip/stats/detailed');
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status} ${response.statusText}`);
                }
                const data = await response.json();
                this.stats = data.data;
            } catch (error) {
                this.error = error.message || 'Failed to load statistics';
                this.stats = null;
            }
            this.loading = false;
        },

        async refresh() {
            await this.loadStats();
            if (!this.error) {
                this.showMessage('Statistics refreshed', 'success');
            }
        },

        formatChange(current, previous) {
            current = current ?? 0;
            previous = previous ?? 0;

            if (previous === 0) {
                if (current === 0) return '-';
                return '+100%';
            }

            const change = ((current - previous) / previous) * 100;
            const sign = change >= 0 ? '+' : '';
            return `${sign}${change.toFixed(0)}%`;
        },

        getChangeClass(current, previous) {
            current = current ?? 0;
            previous = previous ?? 0;

            if (current > previous) return 'change-positive';
            if (current < previous) return 'change-negative';
            return 'change-neutral';
        },

        formatStageName(stage) {
            return stage.replace(/_/g, ' ');
        },

        getFunnelStageClass(stage) {
            if (stage === 'complete') return 'complete';
            if (stage === 'error') return 'error';
            return '';
        },

        formatDelta(value) {
            if (value === null || value === undefined) return '-';
            const sign = value >= 0 ? '+' : '';
            return `${sign}${value}`;
        },

        getDeltaClass(value) {
            if (value === null || value === undefined) return 'delta-none';
            if (value > 0) return 'delta-positive';
            if (value < 0) return 'delta-negative';
            return 'delta-neutral';
        },

        formatDunningStageName(stage) {
            const names = {
                'dunning_1': 'Day 1',
                'dunning_2': 'Day 3',
                'dunning_3': 'Day 7',
                'dunning_4': 'Day 14',
                'dunning_blocklist': 'Day 21'
            };
            return names[stage] || stage;
        },

        getDunningStageClass(stage) {
            const classes = {
                'dunning_1': 'day-1',
                'dunning_2': 'day-3',
                'dunning_3': 'day-7',
                'dunning_4': 'day-14',
                'dunning_blocklist': 'blocklist'
            };
            return classes[stage] || '';
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
