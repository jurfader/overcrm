<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import draggable from 'vuedraggable';
import LeadDetailModal from './Components/LeadDetailModal.vue';
import LeadForm from './Components/LeadForm.vue';

const props = defineProps({
    statuses: Array,
    leadsByStatus: Object,
    users: Array,
    filters: Object,
    stats: Object,
});

const search = ref(props.filters?.search || '');
const showCreateForm = ref(false);
const selectedLead = ref(null);
const detailLoading = ref(false);

// Lokalna kopia leadsByStatus dla drag & drop
const columns = ref({});
props.statuses.forEach(s => {
    columns.value[s.id] = [...(props.leadsByStatus[s.id] || [])];
});

function onDragEnd(statusId) {
    // Po drag & drop — aktualizuj status każdego leada w tej kolumnie
    columns.value[statusId].forEach(lead => {
        if (lead.status_id !== statusId) {
            lead.status_id = statusId;
            fetch(route('leads.update-status', lead.id), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ status_id: statusId }),
            }).then(r => {
                if (!r.ok) {
                    console.error('Update status failed', r.status);
                    // Rollback: ustaw z powrotem oryginalny status i przeładuj
                    router.reload({ preserveScroll: true });
                }
            }).catch(err => {
                console.error('Update status error', err);
                router.reload({ preserveScroll: true });
            });
        }
    });
}

async function openDetail(lead) {
    detailLoading.value = true;
    try {
        const res = await fetch(route('leads.show', lead.id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        selectedLead.value = await res.json();
    } catch {
        selectedLead.value = lead;
    } finally {
        detailLoading.value = false;
    }
}

function closeDetail() {
    selectedLead.value = null;
}

function onLeadUpdated() {
    router.reload({ preserveScroll: true });
    selectedLead.value = null;
}

function doSearch() {
    router.get(route('leads.index'), { search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

const sourceLabels = {
    manual: 'Ręczny',
    google_maps: 'Google Maps',
    gus: 'GUS',
    csv_import: 'Import CSV',
    other: 'Inny',
};

const sourceColors = {
    manual: 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300',
    google_maps: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    gus: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    csv_import: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    other: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};
</script>

<template>
    <Head title="Leady" />

    <div class="h-full flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Leady</h1>
                <div class="flex gap-4 mt-1 text-sm text-foreground-muted">
                    <span>Razem: {{ stats?.total || 0 }}</span>
                    <span>Ten tydzień: {{ stats?.this_week || 0 }}</span>
                    <span>Konwersja: {{ stats?.conversion_rate || 0 }}%</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="relative">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Szukaj leada..."
                        class="w-56 rounded-lg border border-border-bright surface text-sm px-3 py-2 pr-8 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                        @keyup.enter="doSearch"
                    />
                    <Icons name="search" class="w-4 h-4 absolute right-2.5 top-2.5 text-slate-400" />
                </div>
                <Link
                    :href="route('leads.search')"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm font-medium transition-colors"
                >
                    <Icons name="search" class="w-4 h-4" />
                    Szukaj leadów
                </Link>
                <button
                    @click="showCreateForm = true"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 text-sm font-medium transition-colors"
                >
                    <Icons name="plus" class="w-4 h-4" />
                    Nowy lead
                </button>
            </div>
        </div>

        <!-- Kanban Board -->
        <div class="flex-1 overflow-x-auto">
            <div class="flex gap-4 min-h-[500px]" style="min-width: max-content;">
                <div
                    v-for="status in statuses"
                    :key="status.id"
                    class="w-72 flex-shrink-0 flex flex-col bg-slate-50 dark:bg-slate-800/50 rounded-xl"
                >
                    <!-- Column Header -->
                    <div class="flex items-center gap-2 px-3 py-3 border-b border-border">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: status.color }" />
                        <h3 class="font-semibold text-sm text-foreground truncate">{{ status.name }}</h3>
                        <span class="ml-auto text-xs text-slate-400 bg-slate-200 dark:bg-slate-700 rounded-full px-2 py-0.5">
                            {{ (columns[status.id] || []).length }}
                        </span>
                    </div>

                    <!-- Draggable Cards -->
                    <draggable
                        v-model="columns[status.id]"
                        :group="{ name: 'leads' }"
                        item-key="id"
                        class="flex-1 p-2 space-y-2 overflow-y-auto min-h-[100px]"
                        ghost-class="opacity-30"
                        @end="onDragEnd(status.id)"
                    >
                        <template #item="{ element: lead }">
                            <div
                                class="surface rounded-lg border border-border p-3 cursor-pointer hover:shadow-md transition-shadow"
                                @click="openDetail(lead)"
                            >
                                <div class="font-medium text-sm text-foreground truncate">
                                    {{ lead.company_name || lead.name }}
                                </div>
                                <div v-if="lead.company_name" class="text-xs text-foreground-muted truncate mt-0.5">
                                    {{ lead.name }}
                                </div>
                                <div class="flex items-center gap-2 mt-2">
                                    <span v-if="lead.phone" class="text-xs text-foreground-muted truncate">
                                        {{ lead.phone }}
                                    </span>
                                    <span
                                        v-if="lead.source"
                                        :class="[sourceColors[lead.source] || sourceColors.other]"
                                        class="text-xs px-1.5 py-0.5 rounded ml-auto flex-shrink-0"
                                    >
                                        {{ sourceLabels[lead.source] || lead.source }}
                                    </span>
                                </div>
                                <div v-if="lead.assignee" class="flex items-center gap-1.5 mt-2">
                                    <span class="w-5 h-5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-xs flex items-center justify-center font-medium">
                                        {{ lead.assignee.name?.charAt(0) }}
                                    </span>
                                    <span class="text-xs text-foreground-muted truncate">{{ lead.assignee.name }}</span>
                                </div>
                            </div>
                        </template>
                    </draggable>
                </div>
            </div>
        </div>
    </div>

    <!-- Lead Detail Modal -->
    <LeadDetailModal
        v-if="selectedLead"
        :lead="selectedLead"
        :statuses="statuses"
        :users="users"
        @close="closeDetail"
        @updated="onLeadUpdated"
    />

    <!-- Create Lead Form -->
    <LeadForm
        v-if="showCreateForm"
        :users="users"
        @close="showCreateForm = false"
        @created="showCreateForm = false; router.reload({ preserveScroll: true })"
    />
</template>
