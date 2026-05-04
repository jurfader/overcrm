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
    GET: 'text-success',
    POST: 'text-brand-primary',
    PUT: 'text-info',
    DELETE: 'text-destructive',
};

const levelColors = {
    ERROR: 'bg-red-100 dark:bg-red-900/30 text-destructive',
    CRITICAL: 'bg-red-100 dark:bg-red-900/30 text-destructive',
    ALERT: 'bg-red-100 dark:bg-red-900/30 text-destructive',
    EMERGENCY: 'bg-red-100 dark:bg-red-900/30 text-destructive',
    WARNING: 'bg-amber-100 dark:bg-amber-900/30 text-brand-primary',
    INFO: 'bg-blue-100 dark:bg-blue-900/30 text-info',
    DEBUG: 'surface-elevated text-foreground',
    NOTICE: 'bg-blue-100 dark:bg-blue-900/30 text-info',
};

const levelDotColors = {
    ERROR: 'bg-red-500',
    CRITICAL: 'bg-red-600',
    ALERT: 'bg-red-600',
    EMERGENCY: 'bg-red-700',
    WARNING: 'bg-amber-500',
    INFO: 'bg-blue-500',
    DEBUG: 'bg-foreground-muted',
    NOTICE: 'bg-blue-400',
};
</script>

<template>
    <Head title="Logi" />

    <div class="space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Logi</h1>
                <p class="text-foreground-muted text-sm mt-1">Historia wywołań API i logi aplikacji</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 surface rounded-lg shadow p-1 border border-border w-fit">
            <button
                @click="switchTab('integration')"
                :class="[
                    'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                    activeTab === 'integration'
                        ? 'bg-blue-500 text-white'
                        : 'text-foreground-muted hover:bg-surface-elevated'
                ]">
                Integracje API
            </button>
            <button
                @click="switchTab('app')"
                :class="[
                    'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                    activeTab === 'app'
                        ? 'bg-blue-500 text-white'
                        : 'text-foreground-muted hover:bg-surface-elevated'
                ]">
                Logi aplikacji
            </button>
        </div>

        <!-- ==================== INTEGRATION TAB ==================== -->
        <template v-if="activeTab === 'integration'">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-foreground">{{ stats.total }}</div>
                    <div class="text-sm text-foreground-muted">Wszystkie logi</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-foreground">{{ stats.today }}</div>
                    <div class="text-sm text-foreground-muted">Dzisiaj</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold" :class="stats.errors_today > 0 ? 'text-destructive' : 'text-success'">
                        {{ stats.errors_today }}
                    </div>
                    <div class="text-sm text-foreground-muted">Błędy dzisiaj</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-foreground">{{ stats.avg_duration }} ms</div>
                    <div class="text-sm text-foreground-muted">Śr. czas odpowiedzi</div>
                </div>
            </div>

            <Card no-padding>
                <div class="p-4 border-b border-border">
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
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-surface-2">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Czas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Serwis</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Endpoint</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Czas odp.</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Użytkownik</th>
                            </tr>
                        </thead>
                        <tbody class="surface divide-y divide-border">
                            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-surface-elevated">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-foreground-muted">
                                    {{ formatDate(log.created_at) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <Badge :color="serviceColors[log.service] || 'gray'" size="sm">
                                        {{ services[log.service] || log.service }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span :class="methodColors[log.method] || 'text-foreground-muted'" class="text-xs font-mono font-bold">
                                            {{ log.method }}
                                        </span>
                                        <span class="text-sm text-foreground font-mono truncate max-w-xs">
                                            {{ log.endpoint }}
                                        </span>
                                    </div>
                                    <p v-if="log.error_message" class="text-xs text-red-500 mt-1 truncate max-w-sm">
                                        {{ log.error_message }}
                                    </p>
                                    <p v-else-if="log.response_summary" class="text-xs text-foreground-subtle mt-1">
                                        {{ log.response_summary }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                        :class="log.status === 'success'
                                            ? 'bg-green-100 text-success'
                                            : 'bg-red-100 text-destructive'"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="log.status === 'success' ? 'bg-green-500' : 'bg-red-500'"></span>
                                        {{ log.status === 'success' ? 'OK' : 'Błąd' }}
                                        <span v-if="log.response_status" class="ml-1 opacity-70">{{ log.response_status }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-foreground">
                                    <span v-if="log.duration_ms !== null" :class="log.duration_ms > 2000 ? 'text-destructive font-medium' : ''">
                                        {{ log.duration_ms }} ms
                                    </span>
                                    <span v-else class="text-foreground-muted">—</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-foreground-muted">
                                    {{ log.user?.name || '—' }}
                                </td>
                            </tr>
                            <tr v-if="logs.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-foreground-muted">
                                    <Icons name="settings" class="w-12 h-12 mx-auto mb-3 text-foreground-subtle opacity-50" />
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
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-foreground">{{ appLogStats.total }}</div>
                    <div class="text-sm text-foreground-muted">Wszystkie wpisy</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold" :class="appLogStats.errors > 0 ? 'text-destructive' : 'text-success'">
                        {{ appLogStats.errors }}
                    </div>
                    <div class="text-sm text-foreground-muted">Błędy</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-brand-primary">{{ appLogStats.warnings }}</div>
                    <div class="text-sm text-foreground-muted">Ostrzeżenia</div>
                </div>
                <div class="glass-card p-4">
                    <div class="text-2xl font-bold text-info">{{ appLogStats.info }}</div>
                    <div class="text-sm text-foreground-muted">Info / Debug</div>
                </div>
            </div>

            <Card no-padding>
                <div class="p-4 border-b border-border">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <Input v-model="search" placeholder="Szukaj w treści logów..." @keyup.enter="applyFilters" />
                        </div>
                        <select v-model="levelFilter"
                            class="w-full px-3 py-2 surface border border-border rounded-lg text-sm text-foreground focus:ring-2 focus:ring-blue-500">
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
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-surface-2">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Czas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Poziom</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase">Wiadomość</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-foreground-muted uppercase w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="surface divide-y divide-border">
                            <template v-for="(entry, idx) in appLogs" :key="idx">
                                <tr class="hover:bg-surface-elevated cursor-pointer" @click="entry.stack_trace && toggleExpand(idx)">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-foreground-muted">
                                        {{ entry.timestamp }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                            :class="levelColors[entry.level] || 'surface-elevated text-foreground'"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full" :class="levelDotColors[entry.level] || 'bg-gray-400'"></span>
                                            {{ entry.level }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-foreground">
                                        <p class="truncate max-w-2xl font-mono text-xs">{{ entry.message }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button v-if="entry.stack_trace" class="text-foreground-muted hover:text-foreground transition-transform"
                                            :class="expandedRows.has(idx) ? 'rotate-180' : ''">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expandedRows.has(idx) && entry.stack_trace">
                                    <td colspan="4" class="px-4 py-3 bg-surface-2/50">
                                        <pre class="text-xs text-foreground-muted font-mono whitespace-pre-wrap break-all max-h-64 overflow-y-auto">{{ entry.stack_trace }}</pre>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="appLogs.length === 0">
                                <td colspan="4" class="px-4 py-12 text-center text-foreground-muted">
                                    <Icons name="document" class="w-12 h-12 mx-auto mb-3 text-foreground-subtle opacity-50" />
                                    <p>Brak logów aplikacji</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Simple pagination for app logs -->
                <div v-if="appLogStats.last_page > 1" class="px-4 py-3 border-t border-border flex items-center justify-between">
                    <span class="text-sm text-foreground-muted">
                        Strona {{ appLogStats.page }} z {{ appLogStats.last_page }}
                        ({{ appLogStats.filtered_total }} wpisów)
                    </span>
                    <div class="flex items-center gap-2">
                        <button
                            @click="appLogPage(appLogStats.page - 1)"
                            :disabled="appLogStats.page <= 1"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-border text-foreground-muted hover:bg-surface-elevated disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Poprzednia
                        </button>
                        <button
                            @click="appLogPage(appLogStats.page + 1)"
                            :disabled="appLogStats.page >= appLogStats.last_page"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-border text-foreground-muted hover:bg-surface-elevated disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Następna
                        </button>
                    </div>
                </div>
            </Card>
        </template>
    </div>
</template>
