<div x-data="campaignBuilder()" x-init="init()">

    <!-- Segment Builder -->
    <section class="card-section">
        <h3>Campaign Segment Builder</h3>
        <p class="note"><small>Build subscriber segments from SQLite drip data. Filter by Listmonk marketing attribute.</small></p>

        <!-- Product Selection -->
        <div class="form-group">
            <label for="product-select">Product</label>
            <select id="product-select" x-model="selectedProduct" @change="onProductChange()">
                <option value="">-- All Products --</option>
                <template x-for="product in products" :key="product.id">
                    <option :value="product.id" x-text="product.name + ' (' + product.id + ')'"></option>
                </template>
            </select>
        </div>

        <!-- Status Selection (Dynamic) -->
        <div class="form-group">
            <label>Status</label>
            <div class="checkbox-group" x-show="availableStatuses.length > 0">
                <template x-for="status in availableStatuses" :key="status">
                    <label class="checkbox-label">
                        <input type="checkbox" :value="status" x-model="selectedStatuses" @change="buildQuery()">
                        <span x-text="status"></span>
                    </label>
                </template>
            </div>
            <small x-show="availableStatuses.length === 0">Select a product to see available statuses</small>
        </div>

        <!-- Stage Selection (Dynamic) -->
        <div class="form-group" x-show="availableStages.length > 0">
            <label>Stage (optional)</label>
            <div class="checkbox-group">
                <template x-for="stage in availableStages" :key="stage">
                    <label class="checkbox-label">
                        <input type="checkbox" :value="stage" x-model="selectedStages" @change="buildQuery()">
                        <span x-text="stage"></span>
                    </label>
                </template>
            </div>
        </div>

        <!-- Marketing Filter -->
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" x-model="marketingRequired">
                Filter by Listmonk status = enabled (excludes blocklisted)
            </label>
        </div>

        <!-- Inactivity Filter -->
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" x-model="inactivityFilter" @change="buildQuery()">
                No activity in last
                <input type="number" x-model="inactivityDays" min="1" max="365"
                       style="width: 100px; display: inline-block; margin: 0 8px;"
                       :disabled="!inactivityFilter" @change="buildQuery()">
                days
            </label>
        </div>

        <!-- Generated SQLite Query -->
        <div class="form-group">
            <label>
                SQLite Query
                <button type="button" class="small secondary outline" @click="resetQuery()"
                        x-show="sqlEdited" style="margin-left: 1rem; padding: 0.25rem 0.5rem;">
                    Reset
                </button>
            </label>
            <textarea x-model="sqliteQuery" rows="6" @input="sqlEdited = true"
                      placeholder="SELECT query will appear here..."></textarea>
            <small>Edit directly to customize. Query must return s.email and s.listmonk_id (names fetched from Listmonk).</small>
        </div>
    </section>

    <!-- Preview Button -->
    <section>
        <div class="button-group">
            <button type="button" @click="preview()" :disabled="loading || !sqliteQuery" :aria-busy="loading">
                Preview Results
            </button>
        </div>
    </section>

    <!-- Results Section -->
    <section x-show="results !== null" class="card-section">
        <h3>Results</h3>

        <p class="total-count">
            <span x-text="totalCount"></span> subscribers found
            <span x-show="marketingRequired"> (filtered by status=enabled)</span>
        </p>

        <!-- Results Table -->
        <div class="results-table" x-show="subscribers.length > 0">
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="sub in subscribers" :key="sub.id || sub.email">
                        <tr>
                            <td x-text="sub.email"></td>
                            <td x-text="sub.name || '-'"></td>
                            <td x-text="sub.product_name || sub.product_id || '-'"></td>
                            <td x-text="sub.status || '-'"></td>
                            <td x-text="sub.stage || '-'"></td>
                            <td x-text="formatDate(sub.updated_at)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Load More -->
        <div x-show="hasMore" class="button-group">
            <button type="button" @click="loadMore()" :disabled="loading" class="secondary">
                Load More
            </button>
        </div>
    </section>

    <!-- Create List Section -->
    <section x-show="results !== null && totalCount > 0" class="create-list-section">
        <h3>Create Campaign List</h3>

        <fieldset>
            <label>
                <input type="radio" name="list-mode" value="new" x-model="listMode">
                Create new list
            </label>
            <div x-show="listMode === 'new'" class="option-details">
                <input type="text" x-model="newListName" placeholder="campaign-" @input="validateListName()">
                <small x-show="listNameError" x-text="listNameError" class="error-text"></small>
            </div>

            <label>
                <input type="radio" name="list-mode" value="update" x-model="listMode">
                Update existing list
            </label>
            <div x-show="listMode === 'update'" class="option-details">
                <select x-model="selectedListId">
                    <option value="">-- Select a list --</option>
                    <template x-for="list in campaignLists" :key="list.id">
                        <option :value="list.id" x-text="list.name + ' (' + list.subscriber_count + ' subscribers)'"></option>
                    </template>
                </select>
                <fieldset x-show="selectedListId">
                    <label>
                        <input type="radio" name="update-mode" value="replace" x-model="updateMode">
                        Replace (remove existing, add new)
                    </label>
                    <label>
                        <input type="radio" name="update-mode" value="add" x-model="updateMode">
                        Add only (keep existing)
                    </label>
                </fieldset>
            </div>
        </fieldset>

        <button type="button" @click="createOrUpdateList()" :disabled="!canCreateList() || creating" :aria-busy="creating">
            <span x-text="listMode === 'new' ? 'Create List' : 'Update List'"></span>
        </button>

        <div x-show="createMessage" class="alert" :class="createSuccess ? 'alert-success' : 'alert-error'" x-text="createMessage"></div>
    </section>

</div>

<style>
.form-group { margin-bottom: 1rem; }
.checkbox-group { display: flex; gap: 1rem; flex-wrap: wrap; }
.checkbox-label { display: flex; align-items: center; gap: 0.4rem; cursor: pointer; }
.checkbox-label input { margin: 0; }
.error-text { color: var(--del-color, #c00); }
textarea { font-family: monospace; font-size: 0.85rem; }
.small { font-size: 0.8rem; }
</style>

<script>
function campaignBuilder() {
    return {
        // Data from API
        products: [],
        availableStatuses: [],
        availableStages: [],

        // Filter state
        selectedProduct: '',
        selectedStatuses: [],
        selectedStages: [],
        marketingRequired: true,
        inactivityFilter: false,
        inactivityDays: 180,

        // SQL
        sqliteQuery: '',
        sqlEdited: false,

        // Results
        loading: false,
        results: null,
        subscribers: [],
        totalCount: 0,
        currentPage: 1,
        hasMore: false,

        // List creation
        listMode: 'new',
        newListName: 'campaign-',
        listNameError: '',
        selectedListId: '',
        updateMode: 'replace',
        campaignLists: [],
        creating: false,
        createMessage: '',
        createSuccess: false,

        async init() {
            await this.loadProducts();
            await this.loadCampaignLists();
            await this.loadDistinctValues();
            this.buildQuery();
        },

        async loadProducts() {
            try {
                const response = await fetch('/api/products');
                const data = await response.json();
                this.products = data.data || [];
            } catch (error) {
                console.error('Failed to load products:', error);
            }
        },

        async loadDistinctValues() {
            try {
                const params = this.selectedProduct ? `?product_id=${this.selectedProduct}` : '';
                const response = await fetch('/api/segment/options' + params);
                const data = await response.json();
                this.availableStatuses = data.statuses || [];
                this.availableStages = data.stages || [];
            } catch (error) {
                console.error('Failed to load options:', error);
            }
        },

        async onProductChange() {
            this.selectedStatuses = [];
            this.selectedStages = [];
            this.results = null;
            await this.loadDistinctValues();
            this.buildQuery();
        },

        buildQuery() {
            if (this.sqlEdited) return;

            let sql = `SELECT DISTINCT s.email, s.listmonk_id, d.product_id, d.status, d.stage, d.updated_at
FROM subscribers s
JOIN subscriber_drips d ON s.id = d.subscriber_id`;

            const conditions = [];

            // Always exclude deleted subscribers (deleted from Listmonk)
            conditions.push(`d.stage != 'deleted'`);

            // Product filter
            if (this.selectedProduct) {
                conditions.push(`d.product_id = '${this.selectedProduct}'`);
            }

            // Status filter
            if (this.selectedStatuses.length > 0) {
                const statusList = this.selectedStatuses.map(s => `'${s}'`).join(', ');
                conditions.push(`d.status IN (${statusList})`);
            }

            // Stage filter
            if (this.selectedStages.length > 0) {
                const stageList = this.selectedStages.map(s => `'${s}'`).join(', ');
                conditions.push(`d.stage IN (${stageList})`);
            }

            // Inactivity filter
            if (this.inactivityFilter && this.inactivityDays > 0) {
                conditions.push(`d.updated_at < datetime('now', '-${this.inactivityDays} days')`);
            }

            if (conditions.length > 0) {
                sql += '\nWHERE ' + conditions.join('\n  AND ');
            }

            sql += '\nORDER BY d.updated_at DESC';

            this.sqliteQuery = sql;
        },

        resetQuery() {
            this.sqlEdited = false;
            this.buildQuery();
        },

        async preview() {
            this.loading = true;
            this.currentPage = 1;
            this.results = null;

            try {
                const response = await fetch('/api/segment/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sql: this.sqliteQuery,
                        marketing_required: this.marketingRequired,
                        page: 1,
                        per_page: 50
                    })
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                this.results = data;
                this.subscribers = data.data || [];
                this.totalCount = data.total || 0;
                this.hasMore = this.subscribers.length < this.totalCount;
            } catch (error) {
                console.error('Preview failed:', error);
                alert('Failed to preview: ' + error.message);
            } finally {
                this.loading = false;
            }
        },

        async loadMore() {
            this.loading = true;
            this.currentPage++;

            try {
                const response = await fetch('/api/segment/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sql: this.sqliteQuery,
                        marketing_required: this.marketingRequired,
                        page: this.currentPage,
                        per_page: 50
                    })
                });

                const data = await response.json();
                this.subscribers = [...this.subscribers, ...data.data || []];
                this.hasMore = this.subscribers.length < this.totalCount;
            } catch (error) {
                console.error('Load more failed:', error);
            } finally {
                this.loading = false;
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '-';
            return new Date(dateStr).toLocaleDateString();
        },

        async loadCampaignLists() {
            try {
                const response = await fetch('/api/lists');
                const data = await response.json();
                this.campaignLists = data.data || [];
            } catch (error) {
                console.error('Failed to load campaign lists:', error);
            }
        },

        validateListName() {
            if (!this.newListName) {
                this.listNameError = 'List name is required';
            } else if (!this.newListName.startsWith('campaign-')) {
                this.listNameError = 'List name should start with "campaign-"';
            } else if (this.newListName.length < 10) {
                this.listNameError = 'List name is too short';
            } else {
                this.listNameError = '';
            }
        },

        canCreateList() {
            if (this.listMode === 'new') {
                return this.newListName && this.newListName.length >= 10 && !this.listNameError;
            }
            return this.selectedListId;
        },

        async createOrUpdateList() {
            this.creating = true;
            this.createMessage = '';

            try {
                const response = await fetch('/api/segment/create-list', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sql: this.sqliteQuery,
                        marketing_required: this.marketingRequired,
                        list_mode: this.listMode,
                        list_name: this.listMode === 'new' ? this.newListName : null,
                        list_id: this.listMode === 'update' ? parseInt(this.selectedListId) : null,
                        update_mode: this.updateMode
                    })
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                this.createSuccess = true;
                this.createMessage = `Success! ${data.subscriber_count} subscribers added to list "${data.list_name}".`;
                this.loadCampaignLists();
            } catch (error) {
                this.createSuccess = false;
                this.createMessage = 'Error: ' + error.message;
            } finally {
                this.creating = false;
            }
        }
    };
}
</script>
