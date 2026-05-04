<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
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
    if (client?.id) {
        router.visit(route('clients.show', client.id));
    } else {
        router.reload({ only: ['clients'] });
    }
}

// === Bulk actions ===
const selectedIds = ref([]);
const bulkProcessing = ref(false);
const showBulkConfirm = ref(false);
const pendingBulkAction = ref(null);

const allSelected = computed({
    get: () => props.clients.data.length > 0 && selectedIds.value.length === props.clients.data.length,
    set: (val) => {
        selectedIds.value = val ? props.clients.data.map(c => c.id) : [];
    },
});

const hasSelection = computed(() => selectedIds.value.length > 0);

function executeBulkAction(action, extra = {}) {
    bulkProcessing.value = true;
    router.post(route('clients.bulk-action'), {
        ids: selectedIds.value,
        action,
        ...extra,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
            showBulkConfirm.value = false;
        },
        onFinish: () => { bulkProcessing.value = false; },
    });
}

function bulkDelete() {
    pendingBulkAction.value = 'delete';
    showBulkConfirm.value = true;
}

function confirmBulk() {
    if (pendingBulkAction.value === 'delete') {
        executeBulkAction('delete');
    }
}

function bulkChangeStatus(status) {
    executeBulkAction('change_status', { status });
}
// === End Bulk ===

// Debounce search
let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
});

watch([typeFilter, statusFilter], () => {
    applyFilters();
});

function applyFilters() {
    selectedIds.value = [];
    router.get(route('clients.index'), {
        search: search.value || undefined,
        type: typeFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function confirmDelete(client) {
    clientToDelete.value = client;
    showDeleteModal.value = true;
}

function deleteClient() {
    deleting.value = true;
    router.delete(route('clients.destroy', clientToDelete.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            clientToDelete.value = null;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

const statusColors = {
    active: 'green',
    inactive: 'gray',
    potential: 'yellow',
};

const statusLabels = {
    active: 'Aktywny',
    inactive: 'Nieaktywny',
    potential: 'Potencjalny',
};

// Import CSV
const showImportModal = ref(false);
const importForm = useForm({
    file: null,
});

function handleFileChange(e) {
    importForm.file = e.target.files[0];
}

function submitImport() {
    importForm.post(route('clients.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false;
            importForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Klienci" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Klienci</h1>
                <p class="text-gray-600 dark:text-slate-400">Zarządzaj bazą klientów</p>
            </div>
            <div class="flex items-center gap-2">
                <a :href="route('clients.export')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
                    <Icons name="document-arrow-down" class="w-4 h-4 mr-2" />
                    Eksport CSV
                </a>
                <button
                    @click="showImportModal = true"
                    class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                >
                    <Icons name="upload" class="w-4 h-4 mr-2" />
                    Import CSV
                </button>
                <Button @click="showQuickAdd = true">
                    <Icons name="plus" class="w-5 h-5 mr-2" />
                    Dodaj klienta
                </Button>
                <Link :href="route('clients.create')" class="text-xs text-slate-500 hover:text-slate-700 underline self-center">
                    Pełny formularz
                </Link>
            </div>
        </div>

        <!-- Filtry -->
        <Card :padding="false">
            <div class="p-4 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <Input v-model="search" placeholder="Szukaj po nazwie, NIP, email..." />
                    </div>
                    <div>
                        <Select v-model="typeFilter" :options="types" placeholder="Wszystkie typy" />
                    </div>
                    <div>
                        <Select v-model="statusFilter" :options="statuses" placeholder="Wszystkie statusy" />
                    </div>
                </div>
            </div>

            <!-- Bulk actions bar -->
            <div v-if="hasSelection" class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-3 flex-wrap">
                <span class="text-sm font-medium text-amber-800 dark:text-amber-300">
                    Zaznaczono {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'klienta' : selectedIds.length < 5 ? 'klientów' : 'klientów' }}
                </span>
                <span class="text-amber-300 dark:text-amber-700">|</span>

                <!-- Zmień status -->
                <div class="relative group">
                    <button class="text-sm px-3 py-1.5 rounded-lg bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">
                        Zmień status ▾
                    </button>
                    <div class="hidden group-hover:block absolute top-full left-0 mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-xl border dark:border-slate-700 py-1 z-50 min-w-[140px]">
                        <button
                            v-for="(label, key) in statusLabels"
                            :key="key"
                            @click="bulkChangeStatus(key)"
                            class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700"
                        >
                            {{ label }}
                        </button>
                    </div>
                </div>

                <span class="text-amber-300 dark:text-amber-700">|</span>

                <!-- Usuń -->
                <button
                    @click="bulkDelete"
                    class="text-sm px-3 py-1.5 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 transition-colors"
                >
                    Usuń zaznaczone
                </button>

                <!-- Odznacz -->
                <button
                    @click="selectedIds = []"
                    class="ml-auto text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300"
                >
                    Odznacz wszystko
                </button>
            </div>

            <!-- Tabela -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 w-10">
                                <input type="checkbox" v-model="allSelected" class="rounded border-gray-300 dark:border-slate-600 text-amber-600 focus:ring-amber-500 dark:bg-slate-700" />
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Klient</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Typ</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kontakt</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zadania</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="client in clients.data" :key="client.id" class="hover:bg-gray-50">
                            <td class="px-3 py-4 w-10">
                                <input type="checkbox" :value="client.id" v-model="selectedIds" class="rounded border-gray-300 dark:border-slate-600 text-amber-600 focus:ring-amber-500 dark:bg-slate-700" />
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-medium">
                                        {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                                    </div>
                                    <div class="ml-4">
                                        <Link :href="route('clients.show', client.id)" class="text-sm font-medium text-gray-900 hover:text-indigo-600">
                                            {{ client.name }}
                                        </Link>
                                        <div v-if="client.nip" class="text-sm text-gray-500">NIP: {{ client.nip }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-gray-900">{{ client.type === 'company' ? 'Firma' : 'Osoba' }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="client.email" class="text-sm text-gray-900">{{ client.email }}</div>
                                <div v-if="client.phone" class="text-sm text-gray-500">{{ client.phone }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <span class="font-medium">{{ client.active_tasks_count }}</span>
                                    <span class="text-gray-500"> aktywnych / {{ client.tasks_count }} łącznie</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Badge :color="statusColors[client.status]">
                                    {{ client.status === 'active' ? 'Aktywny' : client.status === 'inactive' ? 'Nieaktywny' : 'Potencjalny' }}
                                </Badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('clients.show', client.id)" class="text-gray-400 hover:text-gray-600">
                                        <Icons name="eye" class="w-5 h-5" />
                                    </Link>
                                    <Link :href="route('clients.edit', client.id)" class="text-gray-400 hover:text-indigo-600">
                                        <Icons name="edit" class="w-5 h-5" />
                                    </Link>
                                    <button @click="confirmDelete(client)" class="text-gray-400 hover:text-red-600">
                                        <Icons name="trash" class="w-5 h-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="clients.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <Icons name="clients" class="w-12 h-12 mx-auto mb-3 text-gray-300" />
                                <p>Nie znaleziono klientów</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="clients.links" />
        </Card>
    </div>

    <!-- Modal importu CSV -->
    <div v-if="showImportModal" class="fixed inset-0 bg-black/50 dark:bg-black/70 flex items-center justify-center z-50" @click.self="showImportModal = false">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4">
            <div class="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Import klientów z CSV</h3>
                <button @click="showImportModal = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500 dark:text-slate-400">
                    <Icons name="close" class="w-5 h-5" />
                </button>
            </div>
            <form @submit.prevent="submitImport" class="p-6 space-y-4">
                <div>
                    <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">
                        Wybierz plik CSV z danymi klientów. Plik powinien używać separatora <strong>;</strong> (średnik) i zawierać nagłówki kolumn.
                    </p>
                    <p class="text-xs text-gray-500 dark:text-slate-500 mb-4">
                        Wymagana kolumna: <strong>Nazwa</strong>. Opcjonalne: Typ, NIP, Email, Telefon, Ulica, Miasto, Kod pocztowy, Status, i inne.
                        Najprostszy sposób — najpierw wyeksportuj CSV, edytuj go, i zaimportuj ponownie.
                    </p>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Plik CSV</span>
                        <input
                            type="file"
                            accept=".csv,.txt"
                            @change="handleFileChange"
                            class="mt-1 block w-full text-sm text-gray-500 dark:text-slate-400
                                   file:mr-4 file:py-2 file:px-4
                                   file:rounded-lg file:border-0
                                   file:text-sm file:font-medium
                                   file:bg-amber-50 file:text-amber-700
                                   dark:file:bg-amber-900/30 dark:file:text-amber-400
                                   hover:file:bg-amber-100 dark:hover:file:bg-amber-900/50
                                   file:cursor-pointer"
                        />
                    </label>
                    <p v-if="importForm.errors.file" class="text-red-500 text-xs mt-1">{{ importForm.errors.file }}</p>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="showImportModal = false"
                        class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-300 dark:hover:bg-slate-600"
                    >
                        Anuluj
                    </button>
                    <button
                        type="submit"
                        :disabled="!importForm.file || importForm.processing"
                        class="px-4 py-2 text-sm font-medium rounded-lg bg-amber-500 text-white hover:bg-amber-600 disabled:opacity-50"
                    >
                        {{ importForm.processing ? 'Importowanie...' : 'Importuj' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal potwierdzenia usunięcia -->
    <ConfirmModal
        :show="showDeleteModal"
        title="Usuń klienta"
        :message="`Czy na pewno chcesz usunąć klienta '${clientToDelete?.name}'? Ta operacja jest nieodwracalna.`"
        confirm-text="Tak, usuń"
        :processing="deleting"
        @confirm="deleteClient"
        @cancel="showDeleteModal = false"
    />

    <ConfirmModal
        :show="showBulkConfirm"
        title="Masowe usuwanie"
        :message="`Czy na pewno chcesz usunąć ${selectedIds.length} klientów? Ta operacja jest nieodwracalna.`"
        confirm-text="Tak, usuń zaznaczone"
        :processing="bulkProcessing"
        @confirm="confirmBulk"
        @cancel="showBulkConfirm = false"
    />

    <!-- Szybkie dodawanie klienta (NIP → GUS → zapis) -->
    <div v-if="showQuickAdd" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showQuickAdd = false">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200 dark:border-slate-700">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Dodaj klienta</h2>
                <button @click="showQuickAdd = false" class="text-slate-400 hover:text-slate-600">
                    <Icons name="close" class="w-5 h-5" />
                </button>
            </div>
            <div class="p-5">
                <ClientQuickForm @created="onClientCreated" @cancel="showQuickAdd = false" />
            </div>
        </div>
    </div>
</template>
