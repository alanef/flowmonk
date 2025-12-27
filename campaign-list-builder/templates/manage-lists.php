<div x-data="listManager()" x-init="loadLists()">
    <h2>Manage Campaign Lists</h2>

    <p>View and manage lists created for campaigns. Only lists with the "campaign-" prefix or "campaign" tag are shown.</p>

    <!-- Loading State -->
    <div x-show="loading" aria-busy="true">Loading lists...</div>

    <!-- Lists Table -->
    <div x-show="!loading && lists.length > 0" class="results-table">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Subscribers</th>
                    <th>Type</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="list in lists" :key="list.id">
                    <tr>
                        <td x-text="list.name"></td>
                        <td x-text="list.subscriber_count"></td>
                        <td x-text="list.type"></td>
                        <td x-text="formatDate(list.created_at)"></td>
                        <td class="list-actions">
                            <a :href="getListmonkUrl(list.id)" target="_blank" class="secondary" role="button">
                                Open in Listmonk
                            </a>
                            <button type="button" @click="confirmDelete(list)" class="secondary outline">
                                Delete
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Empty State -->
    <div x-show="!loading && lists.length === 0">
        <p>No campaign lists found. Create one from the <a href="/">Query Builder</a> page.</p>
    </div>

    <!-- Message -->
    <div x-show="message" class="alert" :class="messageType === 'success' ? 'alert-success' : 'alert-error'" x-text="message"></div>

    <!-- Delete Confirmation Dialog -->
    <dialog x-ref="deleteDialog">
        <article>
            <header>
                <h3>Delete List</h3>
            </header>
            <p>Are you sure you want to delete "<span x-text="listToDelete?.name"></span>"?</p>
            <p>This action cannot be undone. The list will be removed but subscribers will not be affected.</p>
            <footer>
                <button type="button" class="secondary" @click="$refs.deleteDialog.close()">Cancel</button>
                <button type="button" @click="deleteList()" :aria-busy="deleting">Delete</button>
            </footer>
        </article>
    </dialog>
</div>

<script>
function listManager() {
    return {
        lists: [],
        loading: true,
        message: '',
        messageType: 'success',
        listToDelete: null,
        deleting: false,

        async loadLists() {
            this.loading = true;
            try {
                const response = await fetch('/api/lists');
                const data = await response.json();
                this.lists = data.data || [];
            } catch (error) {
                console.error('Failed to load lists:', error);
                this.message = 'Failed to load lists: ' + error.message;
                this.messageType = 'error';
            } finally {
                this.loading = false;
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            return new Date(dateStr).toLocaleDateString();
        },

        getListmonkUrl(listId) {
            // Construct Listmonk admin URL
            const baseUrl = '<?= rtrim(getenv('LISTMONK_URL') ?: 'https://email.fw9.uk', '/') ?>';
            return `${baseUrl}/admin/lists/${listId}`;
        },

        confirmDelete(list) {
            this.listToDelete = list;
            this.$refs.deleteDialog.showModal();
        },

        async deleteList() {
            if (!this.listToDelete) return;

            this.deleting = true;
            try {
                const response = await fetch(`/api/lists/${this.listToDelete.id}`, {
                    method: 'DELETE'
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                this.$refs.deleteDialog.close();
                this.message = `List "${this.listToDelete.name}" deleted successfully.`;
                this.messageType = 'success';
                this.listToDelete = null;
                this.loadLists();
            } catch (error) {
                this.message = 'Failed to delete list: ' + error.message;
                this.messageType = 'error';
            } finally {
                this.deleting = false;
            }
        }
    };
}
</script>
