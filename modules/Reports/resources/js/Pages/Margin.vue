<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Bar } from 'vue-chartjs';
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    BarElement,
    CategoryScale,
    LinearScale,
} from 'chart.js';
import Icons from '@/Components/Icons.vue';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

const props = defineProps({
    period: String,
    dateRange: Object,
    marginStats: Object,
    departments: Array,
    selectedDepartment: [String, Number],
    hasFakturownia: Boolean,
    isAdmin: Boolean,
    productStats: Object,
    customDateFrom: String,
    customDateTo: String,
});

const CLIENTS_PER_PAGE = 25;
const PRODUCTS_PER_PAGE = 25;

const selectedPeriod = ref(props.period);
const selectedDept = ref(props.selectedDepartment || '');
const sortBy = ref('margin');
const sortDir = ref('desc');
const searchQuery = ref('');
const clientsPage = ref(1);
const productsPage = ref(1);
const customDateFrom = ref(props.customDateFrom || new Date().toISOString().slice(0, 10));
const customDateTo = ref(props.customDateTo || new Date().toISOString().slice(0, 10));

const periods = [
    { value: 'week', label: 'Tydzień' },
    { value: 'month', label: 'Miesiąc' },
    { value: 'quarter', label: 'Kwartał' },
    { value: 'year', label: 'Rok' },
    { value: 'custom', label: 'Własny zakres' },
];

function applyFilters() {
    const params = { period: selectedPeriod.value };
    if (selectedDept.value) params.department_id = selectedDept.value;
    if (selectedPeriod.value === 'custom') {
        params.date_from = customDateFrom.value;
        params.date_to = customDateTo.value;
    }
    router.get(route('reports.margin'), params, { preserveState: true });
}

function changePeriod(p) {
    selectedPeriod.value = p;
    if (p !== 'custom') {
        applyFilters();
    }
}

function applyCustomRange() {
    if (customDateFrom.value && customDateTo.value) {
        applyFilters();
    }
}

function changeDepartment() {
    applyFilters();
}

// URL do pobrania XLSX z aktualnymi filtrami
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (selectedPeriod.value) params.set('period', selectedPeriod.value);
    if (selectedDept.value) params.set('department_id', selectedDept.value);
    if (selectedPeriod.value === 'custom') {
        if (customDateFrom.value) params.set('date_from', customDateFrom.value);
        if (customDateTo.value) params.set('date_to', customDateTo.value);
    }
    const query = params.toString();
    return route('reports.margin.export') + (query ? '?' + query : '');
});

function formatCurrency(val) {
    return new Intl.NumberFormat('pl-PL', {
        style: 'currency',
        currency: 'PLN',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(val || 0);
}

function formatPercent(val) {
    return (val || 0).toFixed(1) + '%';
}

const filteredClients = computed(() => {
    let list = props.marginStats?.allClients || props.marginStats?.topClients || [];
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(c => c.name.toLowerCase().includes(q) || (c.nip && c.nip.includes(q)));
    }
    const dir = sortDir.value === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => (a[sortBy.value] - b[sortBy.value]) * dir);
});

const clientsTotalPages = computed(() => Math.max(1, Math.ceil(filteredClients.value.length / CLIENTS_PER_PAGE)));
const paginatedClients = computed(() => {
    const list = filteredClients.value;
    const start = (clientsPage.value - 1) * CLIENTS_PER_PAGE;
    return list.slice(start, start + CLIENTS_PER_PAGE);
});

watch([searchQuery, sortBy, sortDir], () => { clientsPage.value = 1; });
watch(() => props.marginStats, () => { clientsPage.value = 1; });
watch(() => props.productStats, () => { productsPage.value = 1; });

function toggleSort(col) {
    if (sortBy.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = col;
        sortDir.value = 'desc';
    }
}

function sortIcon(col) {
    if (sortBy.value !== col) return '';
    return sortDir.value === 'asc' ? '↑' : '↓';
}

const deptChartData = computed(() => {
    const depts = props.marginStats?.departments || [];
    return {
        labels: depts.map(d => d.name),
        datasets: [
            {
                label: 'Przychód',
                data: depts.map(d => d.revenue),
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderRadius: 4,
            },
            {
                label: 'Koszt',
                data: depts.map(d => d.cost),
                backgroundColor: 'rgba(239, 68, 68, 0.5)',
                borderRadius: 4,
            },
            {
                label: 'Marża',
                data: depts.map(d => d.margin),
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderRadius: 4,
            },
        ],
    };
});

const deptChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'top' },
        tooltip: {
            callbacks: {
                label: (ctx) => ctx.dataset.label + ': ' + new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 }).format(ctx.raw),
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            ticks: {
                callback: (v) => new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 }).format(v),
            },
        },
    },
};

const topClientsChartData = computed(() => {
    const top = (props.marginStats?.topClients || []).slice(0, 10);
    return {
        labels: top.map(c => c.name.length > 20 ? c.name.substring(0, 20) + '…' : c.name),
        datasets: [
            {
                label: 'Marża netto',
                data: top.map(c => c.margin),
                backgroundColor: top.map(c => c.margin >= 0 ? 'rgba(16, 185, 129, 0.7)' : 'rgba(239, 68, 68, 0.7)'),
                borderRadius: 4,
            },
        ],
    };
});

const topClientsChartOptions = {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 }).format(ctx.raw),
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            ticks: {
                callback: (v) => new Intl.NumberFormat('pl-PL', { notation: 'compact', maximumFractionDigits: 1 }).format(v),
            },
        },
    },
};

const productSortBy = ref('revenue');
const productSortDir = ref('desc');
const productSearch = ref('');

const topProductsChartData = computed(() => {
    const items = (props.productStats?.products || []).slice(0, 15);
    return {
        labels: items.map(p => p.name.length > 25 ? p.name.substring(0, 25) + '…' : p.name),
        datasets: [{
            label: 'Przychód netto',
            data: items.map(p => p.revenue),
            backgroundColor: 'rgba(59, 130, 246, 0.7)',
            borderRadius: 4,
        }],
    };
});

const topProductsChartOptions = {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx) => new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', maximumFractionDigits: 0 }).format(ctx.raw),
            },
        },
    },
    scales: {
        x: {
            beginAtZero: true,
            ticks: {
                callback: (v) => new Intl.NumberFormat('pl-PL', { notation: 'compact', maximumFractionDigits: 1 }).format(v),
            },
        },
    },
};

const filteredProducts = computed(() => {
    let list = props.productStats?.products || [];
    if (productSearch.value) {
        const q = productSearch.value.toLowerCase();
        list = list.filter(p => p.name.toLowerCase().includes(q));
    }
    const dir = productSortDir.value === 'asc' ? 1 : -1;
    return [...list].sort((a, b) => (a[productSortBy.value] - b[productSortBy.value]) * dir);
});

const productsTotalPages = computed(() => Math.max(1, Math.ceil(filteredProducts.value.length / PRODUCTS_PER_PAGE)));
const paginatedProducts = computed(() => {
    const list = filteredProducts.value;
    const start = (productsPage.value - 1) * PRODUCTS_PER_PAGE;
    return list.slice(start, start + PRODUCTS_PER_PAGE);
});

watch([productSearch, productSortBy, productSortDir], () => { productsPage.value = 1; });

function toggleProductSort(col) {
    if (productSortBy.value === col) {
        productSortDir.value = productSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        productSortBy.value = col;
        productSortDir.value = 'desc';
    }
}

function productSortIcon(col) {
    if (productSortBy.value !== col) return '';
    return productSortDir.value === 'asc' ? '↑' : '↓';
}
</script>

<template>
    <Head title="Marżowość" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Marżowość</h1>
                <p class="text-slate-500 dark:text-slate-400">{{ dateRange.label }}</p>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <!-- Nawigacja -->
                <Link :href="route('reports.index', { period: selectedPeriod })"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                    <Icons name="chart-bar" class="w-4 h-4" />
                    Statystyki
                </Link>

                <!-- Eksport Excel — z aktualnymi filtrami -->
                <a v-if="hasFakturownia" :href="exportUrl" target="_blank"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium transition shadow-sm">
                    <Icons name="document-text" class="w-4 h-4" />
                    Pobierz Excel
                </a>

                <!-- Filtr działu (tylko admin) -->
                <select v-if="isAdmin" v-model="selectedDept" @change="changeDepartment"
                    class="px-3 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-sm text-slate-700 dark:text-slate-300 focus:ring-2 focus:ring-emerald-500">
                    <option value="">Wszystkie działy</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                        {{ dept.name }}
                    </option>
                </select>

                <!-- Okres -->
                <div class="flex items-center gap-1 bg-white dark:bg-slate-800 rounded-lg shadow p-1 border border-slate-200 dark:border-slate-700">
                    <button
                        v-for="p in periods" :key="p.value"
                        @click="changePeriod(p.value)"
                        :class="[
                            'px-3 py-1.5 text-sm font-medium rounded-md transition-colors',
                            selectedPeriod === p.value
                                ? 'bg-emerald-500 text-white'
                                : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                        ]">
                        {{ p.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Własny zakres dat -->
        <div v-if="selectedPeriod === 'custom'" class="flex items-center gap-3 flex-wrap bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 px-5 py-3">
            <Icons name="calendar" class="w-5 h-5 text-slate-400" />
            <span class="text-sm font-medium text-slate-600 dark:text-slate-300">Zakres dat:</span>
            <input
                v-model="customDateFrom"
                type="date"
                class="px-3 py-1.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300"
            />
            <span class="text-slate-400">—</span>
            <input
                v-model="customDateTo"
                type="date"
                class="px-3 py-1.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 text-slate-700 dark:text-slate-300"
            />
            <button
                @click="applyCustomRange"
                class="px-4 py-1.5 text-sm font-medium bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-colors">
                Zastosuj
            </button>
        </div>

        <!-- Totals -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                        <Icons name="cash" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <span class="text-sm text-slate-500 dark:text-slate-400">Przychód netto</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ formatCurrency(marginStats.totals.revenue) }}</p>
                <p class="text-xs text-slate-400 mt-1">brutto: {{ formatCurrency(marginStats.totals.revenue_gross) }}</p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center">
                        <Icons name="shopping-cart" class="w-5 h-5 text-red-600 dark:text-red-400" />
                    </div>
                    <span class="text-sm text-slate-500 dark:text-slate-400">Koszt zakupu</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ formatCurrency(marginStats.totals.cost) }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ marginStats.totals.client_count }} klientów</p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center">
                        <Icons name="chart-bar" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <span class="text-sm text-slate-500 dark:text-slate-400">Marża netto</span>
                </div>
                <p class="text-2xl font-bold" :class="marginStats.totals.margin >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                    {{ formatCurrency(marginStats.totals.margin) }}
                </p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900/30 rounded-lg flex items-center justify-center">
                        <Icons name="activity" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                    </div>
                    <span class="text-sm text-slate-500 dark:text-slate-400">% marży</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ formatPercent(marginStats.totals.margin_percent) }}</p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-slate-100 dark:bg-slate-700 rounded-lg flex items-center justify-center">
                        <Icons name="document" class="w-5 h-5 text-slate-600 dark:text-slate-400" />
                    </div>
                    <span class="text-sm text-slate-500 dark:text-slate-400">Faktury</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ marginStats.totals.regular_count }}</p>
                <p v-if="marginStats.totals.correction_count > 0" class="text-xs text-amber-500 mt-1">
                    w tym {{ marginStats.totals.correction_count }} korekt ({{ formatCurrency(marginStats.totals.correction_net) }} netto)
                </p>
                <p v-else class="text-xs text-slate-400 mt-1">brak korekt</p>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Marża wg działów -->
            <div v-if="marginStats.departments.length > 1" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
                <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100 mb-4">Marża wg działów</h3>
                <div class="h-72">
                    <Bar :data="deptChartData" :options="deptChartOptions" />
                </div>
            </div>

            <!-- Top 10 klientów -->
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
                <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100 mb-4">Top 10 klientów wg marży</h3>
                <div :class="marginStats.departments.length > 1 ? 'h-72' : 'h-80'">
                    <Bar :data="topClientsChartData" :options="topClientsChartOptions" />
                </div>
            </div>

            <!-- Tabela działów (jeśli tylko 1 dział = nie ma wykresu, więc pokaż tabelę) -->
            <div v-if="marginStats.departments.length <= 1 && marginStats.departments.length > 0" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
                <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100 mb-4">Dział</h3>
                <div v-for="dept in marginStats.departments" :key="dept.id" class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Przychód</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200">{{ formatCurrency(dept.revenue) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Koszt</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200">{{ formatCurrency(dept.cost) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 dark:border-slate-700 pt-3">
                        <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">Marża</span>
                        <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ formatCurrency(dept.margin) }} ({{ formatPercent(dept.margin_percent) }})</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela klientów (admin only) -->
        <div v-if="isAdmin" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Ranking klientów</h3>
                <div class="relative">
                    <Icons name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input v-model="searchQuery" type="text" placeholder="Szukaj klienta..."
                        class="pl-9 pr-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 w-64" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
                            <th class="px-6 py-3 font-medium">#</th>
                            <th class="px-6 py-3 font-medium">Klient</th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('revenue')">
                                Netto {{ sortIcon('revenue') }}
                            </th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('revenue_gross')">
                                Brutto {{ sortIcon('revenue_gross') }}
                            </th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('cost')">
                                Koszt {{ sortIcon('cost') }}
                            </th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('margin')">
                                Marża {{ sortIcon('margin') }}
                            </th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('margin_percent')">
                                % {{ sortIcon('margin_percent') }}
                            </th>
                            <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleSort('invoice_count')">
                                Faktury {{ sortIcon('invoice_count') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(client, idx) in paginatedClients" :key="client.nip || client.name"
                            class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-3 text-slate-400">{{ (clientsPage - 1) * CLIENTS_PER_PAGE + idx + 1 }}</td>
                            <td class="px-6 py-3">
                                <div>
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ client.name }}</span>
                                    <span v-if="client.nip" class="block text-xs text-slate-400">NIP: {{ client.nip }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-right font-medium text-slate-700 dark:text-slate-300">{{ formatCurrency(client.revenue) }}</td>
                            <td class="px-6 py-3 text-right text-slate-500 dark:text-slate-400">{{ formatCurrency(client.revenue_gross) }}</td>
                            <td class="px-6 py-3 text-right text-slate-600 dark:text-slate-400">
                                <span v-if="client.has_cost_data">{{ formatCurrency(client.cost) }}</span>
                                <span v-else class="text-slate-300 dark:text-slate-600">—</span>
                            </td>
                            <td class="px-6 py-3 text-right font-semibold" :class="client.margin >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                {{ formatCurrency(client.margin) }}
                            </td>
                            <td class="px-6 py-3 text-right">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                    :class="client.margin_percent >= 30 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                        : client.margin_percent >= 15 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                        : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'">
                                    {{ formatPercent(client.margin_percent) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right text-slate-500">{{ client.invoice_count }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="filteredClients.length > CLIENTS_PER_PAGE" class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ (clientsPage - 1) * CLIENTS_PER_PAGE + 1 }}–{{ Math.min(clientsPage * CLIENTS_PER_PAGE, filteredClients.length) }} z {{ filteredClients.length }}
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            @click="clientsPage = Math.max(1, clientsPage - 1)"
                            :disabled="clientsPage <= 1"
                            class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                            ← Poprzednia
                        </button>
                        <span class="text-sm text-slate-500 dark:text-slate-400 px-2">Strona {{ clientsPage }} z {{ clientsTotalPages }}</span>
                        <button
                            @click="clientsPage = Math.min(clientsTotalPages, clientsPage + 1)"
                            :disabled="clientsPage >= clientsTotalPages"
                            class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                            Następna →
                        </button>
                    </div>
                </div>

                <div v-if="filteredClients.length === 0" class="px-6 py-12 text-center text-slate-400">
                    <Icons name="search" class="w-8 h-8 mx-auto mb-2" />
                    <p>Brak danych dla wybranych filtrów</p>
                </div>
            </div>
        </div>

        <!-- Ranking produktów (admin only) -->
        <template v-if="isAdmin && productStats">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Wykres top produktów -->
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 lg:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Top 15 produktów wg przychodu</h3>
                        <span v-if="productStats.analyzed_count < productStats.total_count"
                            class="text-xs text-slate-400">
                            Analiza {{ productStats.analyzed_count }} z {{ productStats.total_count }} faktur
                        </span>
                    </div>
                    <div class="h-96">
                        <Bar :data="topProductsChartData" :options="topProductsChartOptions" />
                    </div>
                </div>
            </div>

            <!-- Tabela produktów -->
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Ranking produktów</h3>
                        <p v-if="productStats.analyzed_count < productStats.total_count" class="text-xs text-slate-400 mt-0.5">
                            Na podstawie {{ productStats.analyzed_count }} z {{ productStats.total_count }} faktur (najwyższe wartości)
                        </p>
                    </div>
                    <div class="relative">
                        <Icons name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input v-model="productSearch" type="text" placeholder="Szukaj produktu..."
                            class="pl-9 pr-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-emerald-500 w-64" />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
                                <th class="px-6 py-3 font-medium">#</th>
                                <th class="px-6 py-3 font-medium">Produkt</th>
                                <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleProductSort('revenue')">
                                    Przychód {{ productSortIcon('revenue') }}
                                </th>
                                <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleProductSort('quantity')">
                                    Ilość {{ productSortIcon('quantity') }}
                                </th>
                                <th class="px-6 py-3 font-medium text-right cursor-pointer select-none hover:text-slate-700 dark:hover:text-slate-200" @click="toggleProductSort('invoice_count')">
                                    Na fakturach {{ productSortIcon('invoice_count') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(product, idx) in paginatedProducts" :key="product.name"
                                class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                                <td class="px-6 py-3 text-slate-400">{{ (productsPage - 1) * PRODUCTS_PER_PAGE + idx + 1 }}</td>
                                <td class="px-6 py-3 font-medium text-slate-800 dark:text-slate-200">{{ product.name }}</td>
                                <td class="px-6 py-3 text-right font-semibold text-blue-600 dark:text-blue-400">{{ formatCurrency(product.revenue) }}</td>
                                <td class="px-6 py-3 text-right text-slate-600 dark:text-slate-400">{{ product.quantity.toLocaleString('pl-PL') }}</td>
                                <td class="px-6 py-3 text-right text-slate-500">{{ product.invoice_count }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="filteredProducts.length > PRODUCTS_PER_PAGE" class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ (productsPage - 1) * PRODUCTS_PER_PAGE + 1 }}–{{ Math.min(productsPage * PRODUCTS_PER_PAGE, filteredProducts.length) }} z {{ filteredProducts.length }}
                        </p>
                        <div class="flex items-center gap-2">
                            <button
                                @click="productsPage = Math.max(1, productsPage - 1)"
                                :disabled="productsPage <= 1"
                                class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                                ← Poprzednia
                            </button>
                            <span class="text-sm text-slate-500 dark:text-slate-400 px-2">Strona {{ productsPage }} z {{ productsTotalPages }}</span>
                            <button
                                @click="productsPage = Math.min(productsTotalPages, productsPage + 1)"
                                :disabled="productsPage >= productsTotalPages"
                                class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors disabled:opacity-50 disabled:cursor-not-allowed border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                                Następna →
                            </button>
                        </div>
                    </div>

                    <div v-if="filteredProducts.length === 0" class="px-6 py-12 text-center text-slate-400">
                        <Icons name="search" class="w-8 h-8 mx-auto mb-2" />
                        <p>Brak danych produktów</p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
