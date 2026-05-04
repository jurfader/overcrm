<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    tasks: Object,
    filters: Object,
    statuses: Array,
    clients: Array,
    users: Array,
    priorities: Object,
});

const page = usePage();
const activeModules = computed(() => page.props.activeModules || []);
const hasKanban = computed(() => activeModules.value.some(m => m.name === 'kanban'));
const hasTimeline = computed(() => activeModules.value.some(m => m.name === 'timeline'));

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status_id);
const clientFilter = ref(props.filters.client_id);
const assignedFilter = ref(props.filters.assigned_to);
const priorityFilter = ref(props.filters.priority);
const myTasks = ref(props.filters.my_tasks);
const overdue = ref(props.filters.overdue);
const today = ref(props.filters.today);
const trashed = ref(props.filters.trashed);

const showDeleteModal = ref(false);
const taskToDelete = ref(null);
const deleting = ref(false);

// === Bulk actions ===
const selectedIds = ref([]);
const bulkProcessing = ref(false);
const showBulkConfirm = ref(false);
const pendingBulkAction = ref(null);

const allSelected = computed({
    get: () => props.tasks.data.length > 0 && selectedIds.value.length === props.tasks.data.length,
    set: (val) => {
        selectedIds.value = val ? props.tasks.data.map(t => t.id) : [];
    },
});

const hasSelection = computed(() => selectedIds.value.length > 0);

function executeBulkAction(action, extra = {}) {
    bulkProcessing.value = true;
    router.post(route('tasks.bulk-action'), {
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

function bulkChangeStatus(statusId) {
    executeBulkAction('change_status', { status_id: statusId });
}

function bulkChangePriority(priority) {
    executeBulkAction('change_priority', { priority });
}

function bulkAssign(userId) {
    executeBulkAction('assign', { assigned_to: userId || null });
}
// === End Bulk ===

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([statusFilter, clientFilter, assignedFilter, priorityFilter, myTasks, overdue, today, trashed], () => {
    applyFilters();
});

function applyFilters() {
    selectedIds.value = [];
    router.get(route('tasks.index'), {
        search: search.value || undefined,
        status_id: statusFilter.value || undefined,
        client_id: clientFilter.value || undefined,
        assigned_to: assignedFilter.value || undefined,
        priority: priorityFilter.value || undefined,
        my_tasks: myTasks.value || undefined,
        overdue: overdue.value || undefined,
        today: today.value || undefined,
        trashed: trashed.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function confirmDelete(task) {
    taskToDelete.value = task;
    showDeleteModal.value = true;
}

function deleteTask() {
    deleting.value = true;
    router.delete(route('tasks.destroy', taskToDelete.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            taskToDelete.value = null;
        },
        onFinish: () => deleting.value = false,
    });
}

function restoreTask(id) {
    router.post(route('tasks.restore', id));
}

const priorityColors = {
    low: 'gray',
    medium: 'blue',
    high: 'yellow',
    urgent: 'red',
};

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pl-PL');
}

function isOverdue(task) {
    if (!task.due_date || task.status?.is_final) return false;
    return new Date(task.due_date) < new Date().setHours(0,0,0,0);
}
</script>

<template>
    <Head title="Zadania" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Zadania</h1>
                <p class="text-foreground-muted">{{ trashed ? 'Kosz - usunięte zadania' : 'Zarządzaj zadaniami i planami' }}</p>
            </div>
            <div class="flex items-center gap-3">
                <Link v-if="hasKanban" :href="route('kanban.index')">
                    <Button variant="secondary">
                        <Icons name="kanban" class="w-5 h-5 mr-2" />
                        Kanban
                    </Button>
                </Link>
                <Link v-if="hasTimeline" :href="route('timeline.index')">
                    <Button variant="secondary">
                        <Icons name="activity" class="w-5 h-5 mr-2" />
                        Timeline
                    </Button>
                </Link>
                <Link :href="route('tasks.create')">
                    <Button>
                        <Icons name="plus" class="w-5 h-5 mr-2" />
                        Nowe zadanie
                    </Button>
                </Link>
            </div>
        </div>

        <!-- Filtry -->
        <Card :padding="false">
            <div class="p-4 border-b border-border space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="md:col-span-2">
                        <Input v-model="search" placeholder="Szukaj po tytule, opisie..." />
                    </div>
                    <Select v-model="statusFilter" :options="statuses" placeholder="Status" />
                    <Select v-model="priorityFilter" :options="priorities" placeholder="Priorytet" />
                    <Select v-model="assignedFilter" :options="users" placeholder="Przypisany do" />
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center">
                        <input type="checkbox" v-model="myTasks" class="rounded border-gray-300 text-amber-600 focus:ring-brand-primary dark:border-slate-600 dark:bg-slate-700" />
                        <span class="ml-2 text-sm text-foreground">Tylko moje</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" v-model="today" class="rounded border-gray-300 text-amber-600 focus:ring-brand-primary dark:border-slate-600 dark:bg-slate-700" />
                        <span class="ml-2 text-sm text-foreground">Na dziś</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" v-model="overdue" class="rounded border-gray-300 text-amber-600 focus:ring-brand-primary dark:border-slate-600 dark:bg-slate-700" />
                        <span class="ml-2 text-sm text-foreground">Przeterminowane</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" v-model="trashed" class="rounded border-gray-300 text-amber-600 focus:ring-brand-primary dark:border-slate-600 dark:bg-slate-700" />
                        <span class="ml-2 text-sm text-foreground">Kosz</span>
                    </label>
                </div>
            </div>

            <!-- Bulk actions bar -->
            <div v-if="hasSelection" class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-3 flex-wrap">
                <span class="text-sm font-medium text-amber-800 dark:text-amber-300">
                    Zaznaczono {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'zadanie' : selectedIds.length < 5 ? 'zadania' : 'zadań' }}
                </span>
                <span class="text-amber-300 dark:text-amber-700">|</span>

                <!-- Zmień status -->
                <div class="relative group">
                    <button class="text-sm px-3 py-1.5 rounded-lg surface border border-border-bright text-foreground hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">
                        Zmień status ▾
                    </button>
                    <div class="hidden group-hover:block absolute top-full left-0 mt-1 surface rounded-lg shadow-xl border dark:border-slate-700 py-1 z-50 min-w-[160px]">
                        <button
                            v-for="s in statuses"
                            :key="s.id"
                            @click="bulkChangeStatus(s.id)"
                            class="block w-full text-left px-4 py-2 text-sm text-foreground hover:bg-surface-elevated"
                        >
                            <span class="inline-block w-2 h-2 rounded-full mr-2" :style="{ backgroundColor: s.color }"></span>
                            {{ s.name }}
                        </button>
                    </div>
                </div>

                <!-- Zmień priorytet -->
                <div class="relative group">
                    <button class="text-sm px-3 py-1.5 rounded-lg surface border border-border-bright text-foreground hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">
                        Zmień priorytet ▾
                    </button>
                    <div class="hidden group-hover:block absolute top-full left-0 mt-1 surface rounded-lg shadow-xl border dark:border-slate-700 py-1 z-50 min-w-[140px]">
                        <button
                            v-for="(label, key) in priorities"
                            :key="key"
                            @click="bulkChangePriority(key)"
                            class="block w-full text-left px-4 py-2 text-sm text-foreground hover:bg-surface-elevated"
                        >
                            {{ label }}
                        </button>
                    </div>
                </div>

                <!-- Przypisz do -->
                <div class="relative group">
                    <button class="text-sm px-3 py-1.5 rounded-lg surface border border-border-bright text-foreground hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">
                        Przypisz do ▾
                    </button>
                    <div class="hidden group-hover:block absolute top-full left-0 mt-1 surface rounded-lg shadow-xl border dark:border-slate-700 py-1 z-50 min-w-[180px] max-h-60 overflow-y-auto">
                        <button
                            @click="bulkAssign(null)"
                            class="block w-full text-left px-4 py-2 text-sm text-gray-400 dark:text-slate-500 hover:bg-surface-elevated italic"
                        >
                            — Brak —
                        </button>
                        <button
                            v-for="u in users"
                            :key="u.id"
                            @click="bulkAssign(u.id)"
                            class="block w-full text-left px-4 py-2 text-sm text-foreground hover:bg-surface-elevated"
                        >
                            {{ u.name }}
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
                    class="ml-auto text-sm text-foreground-muted hover:text-gray-700 dark:hover:text-slate-300"
                >
                    Odznacz wszystko
                </button>
            </div>

            <!-- Tabela -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead class="bg-gray-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-3 py-3 w-10">
                                <input type="checkbox" v-model="allSelected" class="rounded border-border-bright text-amber-600 focus:ring-brand-primary dark:bg-slate-700" />
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Zadanie</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Klient</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Priorytet</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Termin</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Przypisany</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-foreground-muted uppercase tracking-wider">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="surface divide-y divide-gray-200 dark:divide-slate-700">
                        <tr v-for="task in tasks.data" :key="task.id" class="hover:bg-surface-elevated" :class="{ 'bg-red-50 dark:bg-red-900/20': isOverdue(task) }">
                            <td class="px-3 py-4 w-10">
                                <input type="checkbox" :value="task.id" v-model="selectedIds" class="rounded border-border-bright text-amber-600 focus:ring-brand-primary dark:bg-slate-700" />
                            </td>
                            <td class="px-6 py-4">
                                <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-foreground hover:text-amber-600 dark:hover:text-amber-400">
                                    {{ task.title }}
                                </Link>
                                <p v-if="task.description" class="text-xs text-foreground-muted mt-1 truncate max-w-xs">{{ task.description }}</p>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Link v-if="task.client" :href="route('clients.show', task.client.id)" class="text-sm text-foreground hover:text-amber-600 dark:hover:text-amber-400">
                                    {{ task.client.short_name || task.client.name }}
                                </Link>
                                <span v-else class="text-sm text-gray-400 dark:text-slate-500">—</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="task.status" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :style="{ backgroundColor: task.status.color + '20', color: task.status.color }">
                                    {{ task.status.name }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Badge :color="priorityColors[task.priority]" size="sm">
                                    {{ priorities[task.priority] }}
                                </Badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="isOverdue(task) ? 'text-red-600 dark:text-red-400 font-medium' : 'text-sm text-foreground'">
                                    {{ formatDate(task.due_date) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="task.assignee" class="text-sm text-foreground">{{ task.assignee.name }}</span>
                                <span v-else class="text-sm text-gray-400 dark:text-slate-500">—</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2">
                                    <template v-if="trashed">
                                        <button @click="restoreTask(task.id)" class="text-green-600 hover:text-green-800">
                                            <Icons name="refresh" class="w-5 h-5" />
                                        </button>
                                    </template>
                                    <template v-else>
                                        <Link :href="route('tasks.show', task.id)" class="text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300">
                                            <Icons name="eye" class="w-5 h-5" />
                                        </Link>
                                        <Link :href="route('tasks.edit', task.id)" class="text-slate-400 hover:text-amber-600 dark:text-slate-500 dark:hover:text-amber-400">
                                            <Icons name="edit" class="w-5 h-5" />
                                        </Link>
                                        <button @click="confirmDelete(task)" class="text-slate-400 hover:text-red-600 dark:text-slate-500 dark:hover:text-red-400">
                                            <Icons name="trash" class="w-5 h-5" />
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="tasks.data.length === 0">
                            <td colspan="8" class="px-6 py-12 text-center text-foreground-muted">
                                <Icons name="tasks" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                                <p>Nie znaleziono zadań</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="tasks.links" />
        </Card>
    </div>

    <ConfirmModal
        :show="showDeleteModal"
        title="Usuń zadanie"
        :message="`Czy na pewno chcesz przenieść zadanie '${taskToDelete?.title}' do kosza?`"
        confirm-text="Tak, przenieś do kosza"
        :processing="deleting"
        @confirm="deleteTask"
        @cancel="showDeleteModal = false"
    />

    <ConfirmModal
        :show="showBulkConfirm"
        title="Masowe usuwanie"
        :message="`Czy na pewno chcesz przenieść ${selectedIds.length} zadań do kosza?`"
        confirm-text="Tak, usuń zaznaczone"
        :processing="bulkProcessing"
        @confirm="confirmBulk"
        @cancel="showBulkConfirm = false"
    />
</template>
