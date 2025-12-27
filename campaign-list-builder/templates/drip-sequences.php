<div x-data="dripSequences()" x-init="init()">
    <h1>Drip Sequences</h1>

    <!-- Product Selector -->
    <div class="grid">
        <div>
            <label for="product-select">Product</label>
            <select id="product-select" x-model="selectedProductId" @change="loadSequences()">
                <option value="">-- Select a Product --</option>
                <template x-for="product in products" :key="product.id">
                    <option :value="product.id" x-text="`${product.name} (${product.id})`"></option>
                </template>
            </select>
        </div>
        <div class="button-group">
            <button type="button" class="outline" @click="showProductModal = true">Add Product</button>
            <button type="button" class="outline secondary" @click="editProduct()" x-show="selectedProductId" :disabled="!selectedProductId">Edit Product</button>
            <button type="button" class="outline contrast" @click="deleteProduct()" x-show="selectedProductId" :disabled="!selectedProductId">Delete Product</button>
        </div>
    </div>

    <!-- Sequence Type Tabs (FA-12: Dynamic types) -->
    <template x-if="selectedProductId">
        <div>
            <div class="sequence-types-header">
                <div role="group" class="sequence-tabs">
                    <template x-for="type in sequenceTypes" :key="type.type_name">
                        <button :class="{ 'active': activeType === type.type_name }" @click="activeType = type.type_name" x-text="type.display_name"></button>
                    </template>
                </div>
                <button type="button" class="outline small add-type-btn" @click="showTypeModal = true" title="Add sequence type">+ Type</button>
            </div>
            <p x-show="sequenceTypes.length === 0" class="empty-message">No sequence types defined. Click "+ Type" to add one (e.g., "Free", "Trial", "Courses").</p>

            <!-- Sequence Steps Table -->
            <div class="table-wrapper">
                <table role="grid">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Stage</th>
                            <th scope="col">Template ID</th>
                            <th scope="col">Delay (days)</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Enabled</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(step, index) in currentSteps" :key="step.id">
                            <tr>
                                <td x-text="index + 1"></td>
                                <td x-text="step.stage"></td>
                                <td x-text="step.template_id"></td>
                                <td x-text="step.delay_days"></td>
                                <td x-text="step.subject"></td>
                                <td>
                                    <span x-show="step.enabled == 1" class="enabled-badge">Yes</span>
                                    <span x-show="step.enabled != 1" class="disabled-badge">No</span>
                                </td>
                                <td class="action-buttons">
                                    <button type="button" class="outline small" @click="editStep(step)">Edit</button>
                                    <button type="button" class="outline contrast small" @click="deleteStep(step)">Delete</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="currentSteps.length === 0">
                            <td colspan="7" class="empty-message">No steps configured for this sequence type.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button type="button" @click="addStep()">+ Add Step</button>
        </div>
    </template>

    <template x-if="!selectedProductId">
        <p class="empty-message">Select a product to manage its drip sequences.</p>
    </template>

    <!-- Product Modal -->
    <dialog :open="showProductModal" @click.self="showProductModal = false">
        <article>
            <header>
                <button aria-label="Close" rel="prev" @click="showProductModal = false"></button>
                <h3 x-text="editingProduct ? 'Edit Product' : 'Add Product'"></h3>
            </header>
            <form @submit.prevent="saveProduct()">
                <div class="grid">
                    <div>
                        <label for="product-id">Product ID</label>
                        <input type="text" id="product-id" x-model="productForm.id" :disabled="editingProduct" required placeholder="e.g., 5065">
                    </div>
                    <div>
                        <label for="product-list-id">Listmonk List ID</label>
                        <input type="number" id="product-list-id" x-model="productForm.list_id" placeholder="e.g., 4" min="1">
                        <small class="hint">Required for subscription status checks</small>
                    </div>
                </div>

                <label for="product-name">Product Name</label>
                <input type="text" id="product-name" x-model="productForm.name" required placeholder="e.g., Fullworks Anti-Spam">

                <div class="grid">
                    <div>
                        <label for="product-type">Webhook Type</label>
                        <select id="product-type" x-model="productForm.type">
                            <option value="freemius">Freemius (HMAC required)</option>
                            <option value="freelib">Freelib (no HMAC)</option>
                            <option value="other">Other (no HMAC)</option>
                        </select>
                        <small class="hint">Freemius webhooks require HMAC signature verification</small>
                    </div>
                    <div>
                        <label>
                            <input type="checkbox" x-model="productForm.enabled">
                            Enabled
                        </label>
                    </div>
                </div>

                <div x-show="productForm.type === 'freemius'" x-transition>
                    <label for="product-hmac-secret">HMAC Secret</label>
                    <input type="password" id="product-hmac-secret" x-model="productForm.hmac_secret" placeholder="Enter HMAC secret from Freemius">
                    <small class="hint">Required for Freemius webhook signature verification</small>
                </div>

                <footer>
                    <button type="button" class="secondary" @click="showProductModal = false">Cancel</button>
                    <button type="submit" :disabled="saving">
                        <span x-show="!saving" x-text="editingProduct ? 'Update' : 'Add'"></span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Step Modal -->
    <dialog :open="showStepModal" @click.self="showStepModal = false">
        <article>
            <header>
                <button aria-label="Close" rel="prev" @click="showStepModal = false"></button>
                <h3 x-text="editingStep ? 'Edit Step' : 'Add Step'"></h3>
            </header>
            <form @submit.prevent="saveStep()">
                <div class="grid">
                    <div>
                        <label for="step-stage">Stage</label>
                        <input type="text" id="step-stage" x-model="stepForm.stage" required placeholder="e.g., free_1">
                    </div>
                    <div>
                        <label for="step-template">Template ID</label>
                        <input type="number" id="step-template" x-model="stepForm.template_id" required min="1">
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="step-delay">Delay (days)</label>
                        <input type="number" id="step-delay" x-model="stepForm.delay_days" required min="0">
                    </div>
                    <div>
                        <label for="step-order">Step Order</label>
                        <input type="number" id="step-order" x-model="stepForm.step_order" required min="1">
                    </div>
                </div>

                <label for="step-subject">Subject</label>
                <input type="text" id="step-subject" x-model="stepForm.subject" required placeholder="Email subject line">

                <label>
                    <input type="checkbox" x-model="stepForm.enabled">
                    Enabled
                </label>

                <footer>
                    <button type="button" class="secondary" @click="showStepModal = false">Cancel</button>
                    <button type="submit" :disabled="saving">
                        <span x-show="!saving" x-text="editingStep ? 'Update' : 'Add'"></span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Sequence Type Modal (FA-12) -->
    <dialog :open="showTypeModal" @click.self="showTypeModal = false">
        <article>
            <header>
                <button aria-label="Close" rel="prev" @click="showTypeModal = false"></button>
                <h3>Add Sequence Type</h3>
            </header>
            <form @submit.prevent="saveSequenceType()">
                <label for="type-name">Type Name (internal)</label>
                <input type="text" id="type-name" x-model="typeForm.type_name" required placeholder="e.g., courses, certificates, free" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only">
                <small class="hint">Used in stage names (e.g., "courses_1"). Lowercase, no spaces.</small>

                <label for="type-display">Display Name</label>
                <input type="text" id="type-display" x-model="typeForm.display_name" required placeholder="e.g., Courses, Certificates, Free Users">
                <small class="hint">Shown in the tab button</small>

                <footer>
                    <button type="button" class="secondary" @click="showTypeModal = false">Cancel</button>
                    <button type="button" class="outline contrast" @click="deleteSequenceType()" x-show="activeType && sequenceTypes.length > 0" :disabled="saving">Delete Current Type</button>
                    <button type="submit" :disabled="saving">
                        <span x-show="!saving">Add Type</span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </footer>
            </form>
        </article>
    </dialog>

    <!-- Status Messages -->
    <div x-show="message" x-transition class="status-message" :class="messageType" x-text="message"></div>
</div>

<style>
.table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 1rem;
}
.sequence-types-header {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 1rem;
}
.add-type-btn {
    margin: 0 !important;
    white-space: nowrap;
}
.sequence-tabs {
    margin-bottom: 1rem;
}
.sequence-tabs button {
    padding: 0.5rem 1.5rem;
    border: 1px solid var(--pico-primary);
    background: transparent;
    color: var(--pico-primary);
    cursor: pointer;
}
.sequence-tabs button:first-child {
    border-radius: var(--pico-border-radius) 0 0 var(--pico-border-radius);
}
.sequence-tabs button:last-child {
    border-radius: 0 var(--pico-border-radius) var(--pico-border-radius) 0;
}
.sequence-tabs button:not(:first-child) {
    border-left: none;
}
.sequence-tabs button.active {
    background: var(--pico-primary);
    color: var(--pico-primary-inverse);
}
.button-group {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
    padding-bottom: 0.25rem;
}
.button-group button {
    margin: 0;
    width: auto;
}
.action-buttons {
    display: flex;
    gap: 0.25rem;
}
.action-buttons button {
    margin: 0;
    padding: 0.25rem 0.5rem;
    font-size: 0.85rem;
}
.small {
    padding: 0.25rem 0.5rem !important;
    font-size: 0.85rem !important;
}
.enabled-badge {
    color: var(--pico-ins-color);
    font-weight: bold;
}
.disabled-badge {
    color: var(--pico-del-color);
}
.empty-message {
    text-align: center;
    color: var(--pico-muted-color);
    padding: 2rem;
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
dialog article {
    max-width: 600px;
}
dialog footer {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}
dialog footer button {
    width: auto;
    margin: 0;
}
.hint {
    display: block;
    color: var(--pico-muted-color);
    font-size: 0.8rem;
    margin-top: 0.25rem;
}
</style>

<script>
function dripSequences() {
    return {
        products: [],
        sequences: {},
        sequenceTypes: [], // FA-12: Dynamic sequence types
        selectedProductId: '',
        activeType: '',
        loading: false,
        saving: false,
        message: '',
        messageType: 'success',

        // Product modal
        showProductModal: false,
        editingProduct: false,
        productForm: { id: '', name: '', list_id: '', type: 'freemius', hmac_secret: '', enabled: true },

        // Step modal
        showStepModal: false,
        editingStep: false,
        stepForm: { id: null, stage: '', template_id: '', delay_days: 0, subject: '', step_order: 1, enabled: true },

        // FA-12: Sequence type modal
        showTypeModal: false,
        typeForm: { type_name: '', display_name: '' },

        get currentSteps() {
            return this.sequences[this.activeType] || [];
        },

        async init() {
            await this.loadProducts();
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

        async loadSequences() {
            if (!this.selectedProductId) {
                this.sequences = {};
                this.sequenceTypes = [];
                this.activeType = '';
                return;
            }

            try {
                // Load sequence types first
                const typesResponse = await fetch(`/api/drip/sequence-types/${this.selectedProductId}`);
                const typesData = await typesResponse.json();
                this.sequenceTypes = typesData.data || [];

                // Load sequences
                const response = await fetch(`/api/drip/sequences/${this.selectedProductId}`);
                const data = await response.json();
                this.sequences = data.data || {};

                // Set active type to first available, or empty
                if (this.sequenceTypes.length > 0) {
                    // Keep current active type if still valid, otherwise use first
                    const validType = this.sequenceTypes.find(t => t.type_name === this.activeType);
                    if (!validType) {
                        this.activeType = this.sequenceTypes[0].type_name;
                    }
                } else {
                    this.activeType = '';
                }
            } catch (error) {
                this.showMessage('Failed to load sequences', 'error');
            }
        },

        // Product methods
        editProduct() {
            const product = this.products.find(p => p.id === this.selectedProductId);
            if (product) {
                this.productForm = {
                    id: product.id,
                    name: product.name,
                    list_id: product.list_id || '',
                    type: product.type || 'freemius',
                    hmac_secret: '', // Don't show existing secret, only allow setting new
                    enabled: product.enabled == 1
                };
                this.editingProduct = true;
                this.showProductModal = true;
            }
        },

        async saveProduct() {
            this.saving = true;
            try {
                const response = await fetch('/api/drip/products', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.productForm)
                });

                if (response.ok) {
                    await this.loadProducts();
                    this.showProductModal = false;
                    this.resetProductForm();
                    this.showMessage(this.editingProduct ? 'Product updated' : 'Product added', 'success');
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to save product', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to save product', 'error');
            }
            this.saving = false;
        },

        async deleteProduct() {
            if (!confirm('Delete this product and all its sequences?')) return;

            try {
                const response = await fetch(`/api/drip/products/${this.selectedProductId}`, {
                    method: 'DELETE'
                });

                if (response.ok) {
                    this.selectedProductId = '';
                    this.sequences = {};
                    this.sequenceTypes = [];
                    this.activeType = '';
                    await this.loadProducts();
                    this.showMessage('Product deleted', 'success');
                } else {
                    this.showMessage('Failed to delete product', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to delete product', 'error');
            }
        },

        resetProductForm() {
            this.productForm = { id: '', name: '', list_id: '', type: 'freemius', hmac_secret: '', enabled: true };
            this.editingProduct = false;
        },

        // FA-12: Sequence type methods
        async saveSequenceType() {
            if (!this.typeForm.type_name || !this.typeForm.display_name) {
                this.showMessage('Both type name and display name are required', 'error');
                return;
            }

            this.saving = true;
            try {
                const response = await fetch('/api/drip/sequence-types', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: this.selectedProductId,
                        type_name: this.typeForm.type_name.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                        display_name: this.typeForm.display_name
                    })
                });

                if (response.ok) {
                    await this.loadSequences();
                    this.showTypeModal = false;
                    this.typeForm = { type_name: '', display_name: '' };
                    // Set active to the new type
                    this.activeType = this.typeForm.type_name || this.sequenceTypes[this.sequenceTypes.length - 1]?.type_name || '';
                    this.showMessage('Sequence type added', 'success');
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to save sequence type', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to save sequence type', 'error');
            }
            this.saving = false;
        },

        async deleteSequenceType() {
            if (!this.activeType) return;

            const stepsCount = this.currentSteps.length;
            const confirmMsg = stepsCount > 0
                ? `Delete the "${this.activeType}" sequence type and its ${stepsCount} step(s)?`
                : `Delete the "${this.activeType}" sequence type?`;

            if (!confirm(confirmMsg)) return;

            this.saving = true;
            try {
                const response = await fetch(`/api/drip/sequence-types/${this.selectedProductId}/${this.activeType}`, {
                    method: 'DELETE'
                });

                if (response.ok) {
                    await this.loadSequences();
                    this.showTypeModal = false;
                    this.showMessage('Sequence type deleted', 'success');
                } else {
                    this.showMessage('Failed to delete sequence type', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to delete sequence type', 'error');
            }
            this.saving = false;
        },

        // Step methods
        addStep() {
            if (!this.activeType) {
                this.showMessage('Please add a sequence type first', 'error');
                return;
            }
            const maxOrder = Math.max(0, ...this.currentSteps.map(s => s.step_order || 0));
            this.stepForm = {
                id: null,
                stage: `${this.activeType}_${this.currentSteps.length + 1}`,
                template_id: '',
                delay_days: 0,
                subject: '',
                step_order: maxOrder + 1,
                enabled: true
            };
            this.editingStep = false;
            this.showStepModal = true;
        },

        editStep(step) {
            this.stepForm = {
                id: step.id,
                stage: step.stage,
                template_id: step.template_id,
                delay_days: step.delay_days,
                subject: step.subject,
                step_order: step.step_order,
                enabled: step.enabled == 1
            };
            this.editingStep = true;
            this.showStepModal = true;
        },

        async saveStep() {
            this.saving = true;
            try {
                const payload = {
                    ...this.stepForm,
                    plugin_id: this.selectedProductId, // API still uses plugin_id for backward compat
                    type: this.activeType,
                    enabled: this.stepForm.enabled ? 1 : 0
                };

                const response = await fetch('/api/drip/sequences', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                if (response.ok) {
                    await this.loadSequences();
                    this.showStepModal = false;
                    this.showMessage(this.editingStep ? 'Step updated' : 'Step added', 'success');
                } else {
                    const data = await response.json();
                    this.showMessage(data.error || 'Failed to save step', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to save step', 'error');
            }
            this.saving = false;
        },

        async deleteStep(step) {
            if (!confirm('Delete this sequence step?')) return;

            try {
                const response = await fetch(`/api/drip/sequences/${step.id}`, {
                    method: 'DELETE'
                });

                if (response.ok) {
                    await this.loadSequences();
                    this.showMessage('Step deleted', 'success');
                } else {
                    this.showMessage('Failed to delete step', 'error');
                }
            } catch (error) {
                this.showMessage('Failed to delete step', 'error');
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
