<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Pagination from '@/Components/Pagination.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    logs: Object,
    stats: Object,
    filters: Object,
    services: Object,
    appLogs: Array,
    appLogStats: Object,
});

const activeTab = ref(props.filters.tab || 'integration');
const search = ref(props.filters.search);
const serviceFilter = ref(props.filters.service);
const statusFilter = ref(props.filters.status);
const levelFilter = ref(props.filters.level || '');
const expandedRows = ref(new Set());

let searchTimeout;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([serviceFilter, statusFilter], () => {
    if (activeTab.value === 'integration') applyFilters();
});

watch(levelFilter, () => {
    if (activeTab.value === 'app') applyFilters();
});

function switchTab(tab) {
    activeTab.value = tab;
    search.value = '';
    serviceFilter.value = '';
    statusFilter.value = '';
    levelFilter.value = '';
    expandedRows.value.clear();
    router.get(route('admin.integration-logs'), { tab }, { preserveState: false });
}

function applyFilters() {
    const params = { tab: activeTab.value };
    if (search.value) params.search = search.value;

    if (activeTab.value === 'integration') {
        if (serviceFilter.value) params.service = serviceFilter.value;
        if (statusFilter.value) params.status = statusFilter.value;
    } else {
        if (levelFilter.value) params.level = levelFilter.value;
    }

    router.get(route('admin.integration-logs'), params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function appLogPage(page) {
    const params = { tab: 'app', page };
    if (search.value) params.search = search.value;
    if (levelFilter.value) params.level = levelFilter.value;
    router.get(route('admin.integration-logs'), params, { preserveState: true });
}

function toggleExpand(idx) {
    if (expandedRows.value.has(idx)) {
        expandedRows.value.delete(idx);
    } else {
        expandedRows.value.add(idx);
    }
}

function formatDate(date) {
    return new Date(date).toLocaleString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

const serviceColors = {
    fakturownia: 'blue',
    apilo: 'purple',
    gus: 'green',
    ringostat: 'amber',
};

const methodColors = {
    GET: 'text-green-600 dark:text-green-400',
    POST: 'text-amber-600 dark:text-amber-400',
    PUT: 'text-blue-600 dark:text-blue-400',
    DELETE: 'text-red-600 dark:text-red-400',
};

const levelColors = {
    ERROR: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
    CRITICAL: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
    ALERT: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
    EMERGENCY: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
    WARNING: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
    INFO: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
    DEBUG: 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
    NOTICE: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
};

const levelDotColors = {
    ERROR: 'bg-red-500',
    CRITICAL: 'bg-red-600',
    ALERT: 'bg-red-600',
    EMERGENCY: 'bg-red-700',
    WARNING: 'bg-amber-500',
    INFO: 'bg-blue-500',
    DEBUG: 'bg-gray-400',
    NOTICE: 'bg-blue-400',
};
</script>

<template>
    <Head title="Logi" />

    <div class="space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Logi</h1>
                <p class="text-gray-600 dark:text-slate-400">Historia wywołań API i logi aplikacji</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 bg-white dark:bg-slate-800 rounded-lg shadow p-1 border border-gray-200 dark:border-slate-700 w-fit">
            <button
                @click="switchTab('integration')"
                :class="[
                    'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                    activeTab === 'integration'
                        ? 'bg-blue-500 text-white'
                        : 'text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700'
                ]">
                Integracje API
            </button>
            <button
                @click="switchTab('app')"
                :class="[
                    'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                    activeTab === 'app'
                        ? 'bg-blue-500 text-white'
                        : 'text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700'
                ]">
                Logi aplikacji
            </button>
        </div>

        <!-- ==================== INTEGRATION TAB ==================== -->
        <template v-if="activeTab === 'integration'">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.total }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Wszystkie logi</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.today }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Dzisiaj</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold" :class="stats.errors_today > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                        {{ stats.errors_today }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Błędy dzisiaj</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.avg_duration }} ms</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Śr. czas odpowiedzi</div>
                </div>
            </div>

            <Card :padding="false">
                <div class="p-4 border-b border-gray-200 dark:border-slate-700">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <Input v-model="search" placeholder="Szukaj po endpoint, błędzie..." />
                        </div>
                        <Select v-model="serviceFilter" :options="services" placeholder="Wszystkie serwisy" />
                        <Select
                            v-model="statusFilter"
                            :options="{ success: 'Sukces', error: 'Błąd' }"
                            placeholder="Wszystkie statusy"
                        />
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                        <thead class="bg-gray-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Czas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Serwis</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Endpoint</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Czas odp.</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Użytkownik</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50 dark:hover:bg-slate-700">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-slate-400">
                                    {{ formatDate(log.created_at) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <Badge :color="serviceColors[log.service] || 'gray'" size="sm">
                                        {{ services[log.service] || log.service }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span :class="methodColors[log.method] || 'text-gray-600'" class="text-xs font-mono font-bold">
                                            {{ log.method }}
                                        </span>
                                        <span class="text-sm text-gray-900 dark:text-slate-200 font-mono truncate max-w-xs">
                                            {{ log.endpoint }}
                                        </span>
                                    </div>
                                    <p v-if="log.error_message" class="text-xs text-red-500 dark:text-red-400 mt-1 truncate max-w-sm">
                                        {{ log.error_message }}
                                    </p>
                                    <p v-else-if="log.response_summary" class="text-xs text-gray-400 dark:text-slate-500 mt-1">
                                        {{ log.response_summary }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                        :class="log.status === 'success'
                                            ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
                                            : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="log.status === 'success' ? 'bg-green-500' : 'bg-red-500'"></span>
                                        {{ log.status === 'success' ? 'OK' : 'Błąd' }}
                                        <span v-if="log.response_status" class="ml-1 opacity-70">{{ log.response_status }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-slate-200">
                                    <span v-if="log.duration_ms !== null" :class="log.duration_ms > 2000 ? 'text-red-600 dark:text-red-400 font-medium' : ''">
                                        {{ log.duration_ms }} ms
                                    </span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-slate-400">
                                    {{ log.user?.name || '—' }}
                                </td>
                            </tr>
                            <tr v-if="logs.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-gray-500 dark:text-slate-400">
                                    <Icons name="settings" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                                    <p>Brak logów integracji</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :links="logs.links" />
            </Card>
        </template>

        <!-- ==================== APP LOGS TAB ==================== -->
        <template v-if="activeTab === 'app'">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ appLogStats.total }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Wszystkie wpisy</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold" :class="appLogStats.errors > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'">
                        {{ appLogStats.errors }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Błędy</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ appLogStats.warnings }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Ostrzeżenia</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ appLogStats.info }}</div>
                    <div class="text-sm text-gray-500 dark:text-slate-400">Info / Debug</div>
                </div>
            </div>

            <Card :padding="false">
                <div class="p-4 border-b border-gray-200 dark:border-slate-700">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <Input v-model="search" placeholder="Szukaj w treści logów..." @keyup.enter="applyFilters" />
                        </div>
                        <select v-model="levelFilter"
                            class="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-lg text-sm text-gray-700 dark:text-slate-300 focus:ring-2 focus:ring-blue-500">
                            <option value="">Wszystkie poziomy</option>
                            <option value="error">Error</option>
                            <option value="warning">Warning</option>
                            <option value="info">Info</option>
                            <option value="debug">Debug</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                        <thead class="bg-gray-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Czas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Poziom</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Wiadomość</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                            <template v-for="(entry, idx) in appLogs" :key="idx">
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer" @click="entry.stack_trace && toggleExpand(idx)">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-slate-400">
                                        {{ entry.timestamp }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                            :class="levelColors[entry.level] || 'bg-gray-100 text-gray-700'"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full" :class="levelDotColors[entry.level] || 'bg-gray-400'"></span>
                                            {{ entry.level }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-slate-200">
                                        <p class="truncate max-w-2xl font-mono text-xs">{{ entry.message }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button v-if="entry.stack_trace" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 transition-transform"
                                            :class="expandedRows.has(idx) ? 'rotate-180' : ''">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedRows.has(idx) && entry.stack_trace">
                                    <td colspan="4" class="px-4 py-3 bg-gray-50 dark:bg-slate-900">
                                        <pre class="text-xs text-gray-600 dark:text-slate-400 font-mono whitespace-pre-wrap break-all max-h-64 overflow-y-auto">{{ entry.stack_trace }}</pre>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="appLogs.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-gray-500 dark:text-slate-400">
                                    <Icons name="document" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                                    <p>Brak logów aplikacji</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Simple pagination for app logs -->
                <div v-if="appLogStats.last_page > 1" class="px-4 py-3 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <span class="text-sm text-gray-500 dark:text-slate-400">
                        Strona {{ appLogStats.page }} z {{ appLogStats.last_page }}
                        ({{ appLogStats.filtered_total }} wpisów)
                    </span>
                    <div class="flex items-center gap-2">
                        <button
                            @click="appLogPage(appLogStats.page - 1)"
                            :disabled="appLogStats.page <= 1"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Poprzednia
                        </button>
                        <button
                            @click="appLogPage(appLogStats.page + 1)"
                            :disabled="appLogStats.page >= appLogStats.last_page"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Następna
                        </button>
                    </div>
                </div>
            </Card>
        </template>
    </div>
</template>
