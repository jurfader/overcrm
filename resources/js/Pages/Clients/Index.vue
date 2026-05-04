<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Input from '@/Components/Input.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icons from '@/Components/Icons.vue';
import ClientQuickForm from '@/Components/ClientQuickForm.vue';

const props = defineProps({
    clients: Object,
    filters: Object,
    types: Object,
    statuses: Object,
});

const search = ref(props.filters.search);
const typeFilter = ref(props.filters.type);
const statusFilter = ref(props.filters.status);

const showDeleteModal = ref(false);
const clientToDelete = ref(null);
const deleting = ref(false);

const showQuickAdd = ref(false);

function onClientCreated(client) {
    showQuickAdd.value = false;
    if (client?.id) router.visit(route('clients.show', client.id));
    else router.reload({ only: ['clients'] });
}

// Bulk
const selectedIds = ref([]);
const bulkProcessing = ref(false);
const showBulkConfirm = ref(false);
const pendingBulkAction = ref(null);

const allSelected = computed({
    get: () => props.clients.data.length > 0 && selectedIds.value.length === props.clients.data.length,
    set: (val) => { selectedIds.value = val ? props.clients.data.map(c => c.id) : []; },
});
const hasSelection = computed(() => selectedIds.value.length > 0);

function executeBulkAction(action, extra = {}) {
    bulkProcessing.value = true;
    router.post(route('clients.bulk-action'), { ids: selectedIds.value, action, ...extra }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; showBulkConfirm.value = false; },
        onFinish: () => { bulkProcessing.value = false; },
    });
}
function bulkDelete() { pendingBulkAction.value = 'delete'; showBulkConfirm.value = true; }
function confirmBulk() { if (pendingBulkAction.value === 'delete') executeBulkAction('delete'); }
function bulkChangeStatus(status) { executeBulkAction('change_status', { status }); }

// Search debounce
let searchTimeout;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
});
watch([typeFilter, statusFilter], applyFilters);

function applyFilters() {
    selectedIds.value = [];
    router.get(route('clients.index'), {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function confirmDelete(client) { clientToDelete.value = client; showDeleteModal.value = true; }
function deleteClient() {
    deleting.value = true;
    router.delete(route('clients.destroy', clientToDelete.value.id), {
        onSuccess: () => { showDeleteModal.value = false; clientToDelete.value = null; },
        onFinish: () => { deleting.value = false; },
    });
}

const statusVariant = { active: 'success', inactive: 'secondary', potential: 'warning' };
const statusLabels = { active: 'Aktywny', inactive: 'Nieaktywny', potential: 'Potencjalny' };

// Import
const showImportModal = ref(false);
const importForm = useForm({ file: null });
function handleFileChange(e) { importForm.file = e.target.files[0]; }
function submitImport() {
    importForm.post(route('clients.import'), {
        forceFormData: true,
        onSuccess: () => { showImportModal.value = false; importForm.reset(); },
    });
}

const showStatusMenu = ref(false);
</script>

<template>
    <Head title="Klienci" />

    <div class="p-6 space-y-6 animate-fade-in">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Klienci</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Zarządzaj bazą klientów</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a :href="route('clients.export')" class="inline-flex items-center gap-2 h-9 px-3 text-sm font-medium rounded-md border border-border-bright text-foreground hover:bg-surface-elevated transition-colors">
                    <Icons name="document-arrow-down" class="w-4 h-4" />
                    Eksport CSV
                </a>
                <Button variant="outline" size="sm" @click="showImportModal = true">
                    <Icons name="upload" class="w-4 h-4" />
                    Import CSV
                </Button>
                <Button @click="showQuickAdd = true">
                    <Icons name="plus" class="w-4 h-4" />
                    Dodaj klienta
                </Button>
                <Link :href="route('clients.create')" class="text-xs text-foreground-muted hover:text-foreground underline self-center">
                    Pełny formularz
                </Link>
            </div>
        </div>

        <!-- Filtry + tabela -->
        <div class="glass-card rounded-lg overflow-hidden">
            <!-- Filtry -->
            <div class="p-4 border-b border-border">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2">
                        <Input v-model="search" placeholder="Szukaj po nazwie, NIP, email..." />
                    </div>
                    <select v-model="typeFilter"
                        class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface text-foreground focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary">
                        <option value="">Wszystkie typy</option>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="statusFilter"
                        class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface text-foreground focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary">
                        <option value="">Wszystkie statusy</option>
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
            </div>

            <!-- Bulk bar -->
            <div v-if="hasSelection" class="px-4 py-3 gradient-subtle border-b border-border flex items-center gap-3 flex-wrap">
                <span class="text-sm font-medium text-brand-primary">
                    Zaznaczono {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'klienta' : 'klientów' }}
                </span>
                <span class="text-foreground-subtle">|</span>

                <div class="relative">
                    <button @click="showStatusMenu = !showStatusMenu"
                        class="text-sm h-8 px-3 rounded-md border border-border-bright text-foreground hover:bg-surface-elevated transition-colors">
                        Zmień status ▾
                    </button>
                    <div v-if="showStatusMenu" v-click-outside="() => showStatusMenu = false"
                        class="absolute top-full left-0 mt-1 glass-card rounded-md py-1 z-50 min-w-[140px]">
                        <button v-for="(label, key) in statusLabels" :key="key"
                            @click="bulkChangeStatus(key); showStatusMenu = false"
                            class="block w-full text-left px-3 py-2 text-sm text-foreground hover:bg-surface-elevated">
                            {{ label }}
                        </button>
                    </div>
                </div>

                <span class="text-foreground-subtle">|</span>

                <button @click="bulkDelete"
                    class="text-sm h-8 px-3 rounded-md bg-destructive/10 border border-destructive/30 text-destructive hover:bg-destructive/20 transition-colors">
                    Usuń zaznaczone
                </button>
                <button @click="selectedIds = []" class="ml-auto text-sm text-foreground-muted hover:text-foreground">
                    Odznacz wszystko
                </button>
            </div>

            <!-- Tabela -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-2 text-xs uppercase tracking-wide text-foreground-muted">
                        <tr>
                            <th class="px-3 py-3 w-10">
                                <input type="checkbox" v-model="allSelected"
                                    class="rounded border-border-bright bg-surface text-brand-primary focus:ring-brand-primary" />
                            </th>
                            <th class="px-4 py-3 text-left">Klient</th>
                            <th class="px-4 py-3 text-left">Typ</th>
                            <th class="px-4 py-3 text-left">Kontakt</th>
                            <th class="px-4 py-3 text-left">Zadania</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="client in clients.data" :key="client.id" class="hover:bg-surface-elevated transition-colors">
                            <td class="px-3 py-3 w-10">
                                <input type="checkbox" :value="client.id" v-model="selectedIds"
                                    class="rounded border-border-bright bg-surface text-brand-primary focus:ring-brand-primary" />
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 shrink-0 rounded-full gradient-brand flex items-center justify-center text-white text-xs font-bold">
                                        {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                                    </div>
                                    <div class="min-w-0">
                                        <Link :href="route('clients.show', client.id)" class="text-sm font-medium text-foreground hover:text-brand-primary truncate block">
                                            {{ client.name }}
                                        </Link>
                                        <div v-if="client.nip" class="text-xs text-foreground-muted">NIP: {{ client.nip }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-foreground whitespace-nowrap">
                                {{ client.type === 'company' ? 'Firma' : 'Osoba' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div v-if="client.email" class="text-sm text-foreground truncate">{{ client.email }}</div>
                                <div v-if="client.phone" class="text-xs text-foreground-muted">{{ client.phone }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <span class="font-medium text-foreground">{{ client.active_tasks_count }}</span>
                                <span class="text-foreground-muted"> aktywnych / {{ client.tasks_count }} łącznie</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <Badge :variant="statusVariant[client.status]">{{ statusLabels[client.status] }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <Link :href="route('clients.show', client.id)" title="Podgląd"
                                        class="p-1.5 rounded text-foreground-muted hover:text-foreground hover:bg-surface-elevated">
                                        <Icons name="eye" class="w-4 h-4" />
                                    </Link>
                                    <Link :href="route('clients.edit', client.id)" title="Edytuj"
                                        class="p-1.5 rounded text-foreground-muted hover:text-brand-primary hover:bg-surface-elevated">
                                        <Icons name="edit" class="w-4 h-4" />
                                    </Link>
                                    <button @click="confirmDelete(client)" title="Usuń"
                                        class="p-1.5 rounded text-foreground-muted hover:text-destructive hover:bg-destructive/10">
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="clients.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-foreground-subtle">
                                <Icons name="clients" class="w-10 h-10 mx-auto mb-3 opacity-50" />
                                <p class="text-sm">Nie znaleziono klientów</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="clients.links" />
        </div>
    </div>

    <!-- Modal Import CSV -->
    <Teleport to="body">
        <div v-if="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="showImportModal = false">
            <div class="glass-card rounded-xl w-full max-w-md animate-fade-in">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border">
                    <h3 class="text-base font-semibold text-foreground">Import klientów z CSV</h3>
                    <button @click="showImportModal = false" class="p-1 rounded text-foreground-muted hover:text-foreground hover:bg-surface-elevated">
                        <Icons name="close" class="w-5 h-5" />
                    </button>
                </div>
                <form @submit.prevent="submitImport" class="p-6 space-y-4">
                    <p class="text-sm text-foreground-muted">
                        Wybierz plik CSV z danymi klientów. Separator <strong class="text-foreground">;</strong> (średnik), z nagłówkami kolumn.
                    </p>
                    <p class="text-xs text-foreground-muted">
                        Wymagana kolumna: <strong class="text-foreground">Nazwa</strong>. Opcjonalne: Typ, NIP, Email, Telefon, Adres, Status, ...
                        Najprościej — wyeksportuj CSV, edytuj, zaimportuj ponownie.
                    </p>
                    <label class="block">
                        <span class="text-sm font-medium text-foreground">Plik CSV</span>
                        <input type="file" accept=".csv,.txt" @change="handleFileChange"
                            class="mt-2 block w-full text-sm text-foreground-muted
                                file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0
                                file:text-sm file:font-medium file:gradient-subtle file:text-brand-primary
                                hover:file:opacity-80 file:cursor-pointer" />
                    </label>
                    <p v-if="importForm.errors.file" class="text-destructive text-xs">{{ importForm.errors.file }}</p>
                    <div class="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" type="button" @click="showImportModal = false">Anuluj</Button>
                        <Button type="submit" :loading="importForm.processing" :disabled="!importForm.file">
                            {{ importForm.processing ? 'Importuję...' : 'Importuj' }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>

    <!-- Modal Delete -->
    <ConfirmModal :show="showDeleteModal" title="Usuń klienta"
        :message="`Czy na pewno chcesz usunąć klienta '${clientToDelete?.name}'? Ta operacja jest nieodwracalna.`"
        confirm-text="Tak, usuń" :processing="deleting"
        @confirm="deleteClient" @cancel="showDeleteModal = false" />

    <ConfirmModal :show="showBulkConfirm" title="Masowe usuwanie"
        :message="`Czy na pewno chcesz usunąć ${selectedIds.length} klientów? Ta operacja jest nieodwracalna.`"
        confirm-text="Tak, usuń zaznaczone" :processing="bulkProcessing"
        @confirm="confirmBulk" @cancel="showBulkConfirm = false" />

    <!-- Quick add -->
    <Teleport to="body">
        <div v-if="showQuickAdd" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" @click.self="showQuickAdd = false">
            <div class="glass-card rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-fade-in">
                <div class="flex items-center justify-between px-5 py-3 border-b border-border">
                    <h2 class="text-base font-semibold text-foreground">Dodaj klienta</h2>
                    <button @click="showQuickAdd = false" class="p-1 rounded text-foreground-muted hover:text-foreground hover:bg-surface-elevated">
                        <Icons name="close" class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-5">
                    <ClientQuickForm @created="onClientCreated" @cancel="showQuickAdd = false" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
