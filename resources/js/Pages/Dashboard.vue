<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Line, Bar } from 'vue-chartjs';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import Icons from '@/Components/Icons.vue';
import ClickToCall from '@/Components/ClickToCall.vue';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const props = defineProps({
    stats: Object,
    todayTasks: Array,
    overdueTasks: Array,
    recentClients: Array,
    revenueStats: Object,
    marginStats: Object,
    selectedPeriod: String,
    departmentInfo: Object,
    hasFakturowniaIntegration: Boolean,
    hasRingostatIntegration: Boolean,
    callStats: Object,
    callTrend: Array,
    clientsToCall: Array,
    clientsAfterVisit: Array,
    venueBirthdaysUpcoming: { type: Array, default: () => [] },
});

const currentPeriod = ref(props.selectedPeriod || 'month');

const periodOptions = [
    { value: 'day', label: 'Dziś' },
    { value: 'week', label: 'Tydzień' },
    { value: 'month', label: 'Miesiąc' },
    { value: 'year', label: 'Rok' },
];

function changePeriod(period) {
    currentPeriod.value = period;
    router.get(route('dashboard'), { period }, {
        preserveState: true,
        preserveScroll: true,
    });
}

// Konfiguracja wykresu liniowego
const lineChartData = computed(() => ({
    labels: props.revenueStats?.labels || [],
    datasets: [
        {
            label: 'Przychód',
            data: props.revenueStats?.datasets?.revenue || [],
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245, 158, 11, 0.1)',
            fill: true,
            tension: 0.4,
        },
        {
            label: 'Opłacone',
            data: props.revenueStats?.datasets?.paid || [],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.4,
        },
    ],
}));

const lineChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top',
            labels: {
                usePointStyle: true,
                padding: 20,
            },
        },
        tooltip: {
            callbacks: {
                label: function(context) {
                    return context.dataset.label + ': ' + formatCurrency(context.raw);
                }
            }
        }
    },
    scales: {
        y: {
            beginAtZero: true,
            ticks: {
                callback: function(value) {
                    return formatCurrency(value);
                }
            }
        }
    },
};

// Konfiguracja wykresu słupkowego
const barChartData = computed(() => ({
    labels: props.revenueStats?.labels || [],
    datasets: [
        {
            label: 'Opłacone',
            data: props.revenueStats?.datasets?.paid || [],
            backgroundColor: '#10b981',
            borderRadius: 4,
        },
        {
            label: 'Nieopłacone',
            data: props.revenueStats?.datasets?.unpaid || [],
            backgroundColor: '#ef4444',
            borderRadius: 4,
        },
    ],
}));

const barChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top',
            labels: {
                usePointStyle: true,
                padding: 20,
            },
        },
        tooltip: {
            callbacks: {
                label: function(context) {
                    return context.dataset.label + ': ' + formatCurrency(context.raw);
                }
            }
        }
    },
    scales: {
        x: {
            stacked: true,
        },
        y: {
            stacked: true,
            beginAtZero: true,
            ticks: {
                callback: function(value) {
                    return formatCurrency(value);
                }
            }
        }
    },
};

function formatCurrency(value) {
    return new Intl.NumberFormat('pl-PL', {
        style: 'currency',
        currency: 'PLN',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value || 0);
}

const priorityColors = {
    low: 'gray',
    medium: 'blue',
    high: 'yellow',
    urgent: 'red',
};

// AI sugestia rozmówki
const aiReminderClientId = ref(null);
const aiReminderLoading = ref(false);
const aiReminderError = ref('');
const aiReminderText = ref('');

async function fetchAiReminder(clientId) {
    if (aiReminderLoading.value) return;
    aiReminderClientId.value = clientId;
    aiReminderLoading.value = true;
    aiReminderError.value = '';
    aiReminderText.value = '';
    try {
        const r = await fetch(route('dashboard.call-reminder', clientId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (r.status === 419) {
            aiReminderError.value = 'Sesja wygasła — odśwież stronę.';
            return;
        }
        if (!r.ok) {
            aiReminderError.value = `Błąd serwera (${r.status}).`;
            return;
        }
        const data = await r.json().catch(() => ({}));
        if (data.success) {
            aiReminderText.value = data.reminder;
        } else {
            aiReminderError.value = data.message || 'Błąd generowania';
        }
    } catch (e) {
        aiReminderError.value = 'Błąd połączenia';
    } finally {
        aiReminderLoading.value = false;
    }
}

function closeAiReminder() {
    aiReminderClientId.value = null;
    aiReminderText.value = '';
    aiReminderError.value = '';
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Witaj, {{ $page.props.auth.user.name }}!</h1>
                <p class="text-slate-500">Oto podsumowanie Twojego dnia</p>
            </div>
            <Link 
                :href="route('tasks.create')" 
                class="inline-flex items-center px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition-colors shadow-sm"
            >
                <Icons name="plus" class="w-5 h-5 mr-2" />
                Nowe zadanie
            </Link>
        </div>

        <!-- Statystyki kafelki -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-amber-100">
                        <Icons name="tasks" class="w-6 h-6 text-amber-600" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-slate-500">Wszystkie zadania</p>
                        <p class="text-2xl font-bold text-slate-800">{{ stats.tasks }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-blue-100">
                        <Icons name="calendar" class="w-6 h-6 text-blue-600" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-slate-500">Na dziś</p>
                        <p class="text-2xl font-bold text-slate-800">{{ stats.todayTasks }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-red-100">
                        <Icons name="alert" class="w-6 h-6 text-red-600" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-slate-500">Przeterminowane</p>
                        <p class="text-2xl font-bold text-red-600">{{ stats.overdueTasks }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-green-100">
                        <Icons name="clients" class="w-6 h-6 text-green-600" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-slate-500">Klienci</p>
                        <p class="text-2xl font-bold text-slate-800">{{ stats.clients }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-purple-100">
                        <Icons name="users" class="w-6 h-6 text-purple-600" />
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-slate-500">Użytkownicy</p>
                        <p class="text-2xl font-bold text-slate-800">{{ stats.users }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sekcja wykresów przychodów -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-800">Przychody z Fakturowni</h2>
                    <p class="text-sm text-slate-500">
                        <template v-if="departmentInfo">
                            <span class="inline-flex items-center gap-1">
                                <Icons name="filter" class="w-4 h-4" />
                                Filtrowane dla działu: <strong>{{ departmentInfo.name }}</strong>
                            </span>
                        </template>
                        <template v-else-if="hasFakturowniaIntegration">
                            Wszystkie działy (brak przypisanego działu)
                        </template>
                        <template v-else>
                            <span class="text-amber-600">
                                Dane testowe - skonfiguruj integrację w ustawieniach
                            </span>
                        </template>
                    </p>
                </div>
                
                <!-- Przełączniki okresów -->
                <div class="flex bg-slate-100 rounded-lg p-1">
                    <button
                        v-for="option in periodOptions"
                        :key="option.value"
                        @click="changePeriod(option.value)"
                        :class="[
                            'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                            currentPeriod === option.value 
                                ? 'bg-amber-500 text-white shadow-sm' 
                                : 'text-slate-600 hover:text-slate-800'
                        ]"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <!-- Podsumowanie przychodów -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-80">Przychód całkowity</p>
                    <p class="text-2xl font-bold">{{ formatCurrency(revenueStats?.totals?.revenue) }}</p>
                </div>
                <div class="bg-gradient-to-br from-green-500 to-emerald-500 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-80">Opłacone</p>
                    <p class="text-2xl font-bold">{{ formatCurrency(revenueStats?.totals?.paid) }}</p>
                </div>
                <div class="bg-gradient-to-br from-red-500 to-rose-500 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-80">Do zapłaty</p>
                    <p class="text-2xl font-bold">{{ formatCurrency(revenueStats?.totals?.unpaid) }}</p>
                </div>
                <div class="bg-gradient-to-br from-blue-500 to-indigo-500 rounded-xl p-4 text-white">
                    <p class="text-sm opacity-80">Liczba faktur</p>
                    <p class="text-2xl font-bold">{{ revenueStats?.totals?.invoiceCount || 0 }}</p>
                </div>
            </div>

            <!-- Wykresy -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Wykres liniowy -->
                <div class="bg-slate-50 rounded-xl p-4">
                    <h3 class="text-sm font-medium text-slate-600 mb-4">Trend przychodów</h3>
                    <div class="h-64">
                        <Line :data="lineChartData" :options="lineChartOptions" />
                    </div>
                </div>

                <!-- Wykres słupkowy -->
                <div class="bg-slate-50 rounded-xl p-4">
                    <h3 class="text-sm font-medium text-slate-600 mb-4">Płatności</h3>
                    <div class="h-64">
                        <Bar :data="barChartData" :options="barChartOptions" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Marżowość -->
        <div v-if="marginStats && marginStats.totals" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                        <Icons name="chart-bar" class="h-5 w-5 text-emerald-500" />
                        Marżowość
                    </h2>
                    <p v-if="departmentInfo" class="text-sm text-slate-500 dark:text-slate-400">
                        Dział: <strong>{{ departmentInfo.name }}</strong>
                    </p>
                </div>
                <Link v-if="$page.props.auth?.user?.role === 'admin'" :href="route('reports.margin')" class="text-sm text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">
                    Pełny raport &rarr;
                </Link>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(marginStats.totals.revenue) }}</p>
                    <p class="text-xs text-blue-500">Przychód netto</p>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-red-600 dark:text-red-400">{{ formatCurrency(marginStats.totals.cost) }}</p>
                    <p class="text-xs text-red-500">Koszt zakupu</p>
                </div>
                <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400">{{ formatCurrency(marginStats.totals.margin) }}</p>
                    <p class="text-xs text-emerald-500">Marża netto</p>
                </div>
                <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-3 text-center">
                    <p class="text-xl font-bold text-amber-600 dark:text-amber-400">{{ marginStats.totals.margin_percent }}%</p>
                    <p class="text-xs text-amber-500">% marży</p>
                </div>
            </div>

            <!-- Top 5 klientów -->
            <div v-if="marginStats.topClients && marginStats.topClients.length > 0">
                <h3 class="text-sm font-medium text-slate-500 dark:text-slate-400 mb-2">Top klienci wg marży</h3>
                <div class="space-y-2">
                    <div v-for="(client, idx) in marginStats.topClients.slice(0, 5)" :key="idx"
                        class="flex items-center justify-between px-3 py-2 bg-slate-50 dark:bg-slate-700/30 rounded-lg">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-sm font-bold text-slate-400 w-5 text-right">{{ idx + 1 }}</span>
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ client.name }}</span>
                        </div>
                        <div class="flex items-center gap-4 flex-shrink-0">
                            <span class="text-sm text-slate-500 dark:text-slate-400 hidden sm:inline">{{ formatCurrency(client.revenue) }}</span>
                            <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">{{ formatCurrency(client.margin) }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded font-medium"
                                :class="client.margin_percent >= 30 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                    : client.margin_percent >= 15 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                    : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'">
                                {{ client.margin_percent }}%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statystyki połączeń Ringostat -->
        <div v-if="hasRingostatIntegration && callStats" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                    <Icons name="phone" class="h-5 w-5 text-amber-500" />
                    Połączenia dziś
                </h2>
                <Link :href="route('ringostat.index')" class="text-sm text-amber-600 hover:text-amber-700 dark:text-amber-400">
                    Zobacz wszystkie &rarr;
                </Link>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ callStats.total }}</p>
                    <p class="text-xs text-blue-500">Wszystkie</p>
                </div>
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ callStats.answered }}</p>
                    <p class="text-xs text-green-500">Odebrane</p>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ callStats.missed }}</p>
                    <p class="text-xs text-red-500">Nieodebrane</p>
                </div>
                <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ Math.floor(callStats.avg_duration / 60) }}:{{ String(callStats.avg_duration % 60).padStart(2, '0') }}</p>
                    <p class="text-xs text-purple-500">Śr. czas</p>
                </div>
            </div>

            <!-- Mini trend 7 dni -->
            <div v-if="callTrend && callTrend.length > 0" class="flex items-end gap-1 h-16">
                <div v-for="(day, idx) in callTrend" :key="idx" class="flex-1 flex flex-col items-center gap-0.5">
                    <div class="w-full flex flex-col items-center">
                        <div
                            class="w-full bg-green-400 dark:bg-green-500 rounded-t"
                            :style="{ height: (day.answered / Math.max(...callTrend.map(d => d.total), 1)) * 40 + 2 + 'px' }"
                        ></div>
                        <div
                            class="w-full bg-red-300 dark:bg-red-500 rounded-b"
                            :style="{ height: ((day.total - day.answered) / Math.max(...callTrend.map(d => d.total), 1)) * 40 + 'px' }"
                        ></div>
                    </div>
                    <span class="text-[9px] text-slate-400">{{ day.date }}</span>
                </div>
            </div>
        </div>

        <!-- Urodziny lokali w najbliższych 30 dniach -->
        <div v-if="venueBirthdaysUpcoming?.length > 0" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700">
                <h2 class="text-lg font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                    <Icons name="cake" class="h-5 w-5 text-pink-500" />
                    Urodziny lokali
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Lokale z rocznicą otwarcia w najbliższych 30 dniach
                </p>
            </div>
            <ul class="p-6 space-y-2">
                <li v-for="item in venueBirthdaysUpcoming" :key="item.id"
                    class="flex items-center justify-between gap-3 p-3 rounded-lg bg-pink-50 dark:bg-pink-900/20 hover:bg-pink-100 dark:hover:bg-pink-900/30 transition-colors">
                    <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-slate-800 dark:text-white hover:text-pink-600 truncate">
                        {{ item.name }}
                    </Link>
                    <span class="text-sm text-slate-500 dark:text-slate-400 shrink-0">
                        {{ new Date(item.date).toLocaleDateString('pl-PL') }}
                        <span class="text-pink-600 dark:text-pink-400 font-medium">
                            {{ item.days_until === 0 ? '— dziś' : item.days_until === 1 ? '— za 1 dzień' : '— za ' + item.days_until + ' dni' }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>

        <!-- Warto zadzwonić (Ringostat) -->
        <div v-if="hasRingostatIntegration && (clientsToCall?.length > 0 || clientsAfterVisit?.length > 0)" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700">
                <h2 class="text-lg font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                    <Icons name="phone" class="h-5 w-5 text-amber-500" />
                    Warto zadzwonić
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Klienci, do których warto się przypomnieć na podstawie daty ostatniego połączenia lub wizyty
                </p>
            </div>
            <div class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Do oddzwonienia (ostatnie połączenie 7+ dni temu) -->
                <div v-if="clientsToCall?.length > 0">
                    <h3 class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-3">Ostatnie połączenie 7+ dni temu</h3>
                    <ul class="space-y-2">
                        <li v-for="item in clientsToCall" :key="'call-' + item.id"
                            class="flex items-center justify-between gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-700/30 hover:bg-slate-100 dark:hover:bg-slate-700/50 transition-colors relative">
                            <div class="min-w-0 flex-1">
                                <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-slate-800 dark:text-white hover:text-amber-600 truncate block">
                                    {{ item.name }}
                                </Link>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Ostatnie połączenie: {{ item.days_ago }} dni temu
                                </p>
                            </div>
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <button
                                    @click="fetchAiReminder(item.id)"
                                    :disabled="aiReminderLoading"
                                    class="p-1.5 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/30 text-amber-600 dark:text-amber-400 transition-colors disabled:opacity-50"
                                    title="Sugestia AI – rozmówka przed połączeniem"
                                >
                                    <Icons v-if="aiReminderLoading && aiReminderClientId === item.id" name="spinner" class="w-4 h-4 animate-spin" />
                                    <Icons v-else name="sparkles" class="w-4 h-4" />
                                </button>
                                <ClickToCall v-if="item.phone" :phone="item.phone" size="md" />
                            </div>
                        </li>
                    </ul>
                </div>
                <!-- Po wizycie bez oddzwonienia -->
                <div v-if="clientsAfterVisit?.length > 0">
                    <h3 class="text-sm font-medium text-slate-600 dark:text-slate-400 mb-3">Wizyta bez oddzwonienia</h3>
                    <ul class="space-y-2">
                        <li v-for="item in clientsAfterVisit" :key="'visit-' + item.id"
                            class="flex items-center justify-between gap-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-colors relative">
                            <div class="min-w-0 flex-1">
                                <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-slate-800 dark:text-white hover:text-amber-600 truncate block">
                                    {{ item.name }}
                                </Link>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Wizyta {{ item.days_ago }} dni temu – warto się przypomnieć
                                </p>
                            </div>
                            <div class="flex items-center gap-1 flex-shrink-0">
                                <button
                                    @click="fetchAiReminder(item.id)"
                                    :disabled="aiReminderLoading"
                                    class="p-1.5 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/30 text-amber-600 dark:text-amber-400 transition-colors disabled:opacity-50"
                                    title="Sugestia AI – rozmówka przed połączeniem"
                                >
                                    <Icons v-if="aiReminderLoading && aiReminderClientId === item.id" name="spinner" class="w-4 h-4 animate-spin" />
                                    <Icons v-else name="sparkles" class="w-4 h-4" />
                                </button>
                                <ClickToCall v-if="item.phone" :phone="item.phone" size="md" />
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="px-6 py-3 bg-slate-50 dark:bg-slate-700/30 border-t border-slate-100 dark:border-slate-700 rounded-b-xl">
                <Link :href="route('ringostat.index')" class="text-sm text-amber-600 hover:text-amber-700 dark:text-amber-400 font-medium">
                    Zobacz wszystkie połączenia w Ringostat →
                </Link>
            </div>
        </div>

        <!-- Modal: Sugestia AI rozmówki -->
        <Teleport to="body">
            <div v-if="aiReminderClientId" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 bg-black/50" @click.self="closeAiReminder">
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-md w-full max-h-[70vh] overflow-auto" @click.stop>
                    <div class="p-4 border-b border-slate-200 dark:border-slate-600 flex justify-between items-center">
                        <span class="text-sm font-medium text-amber-600 dark:text-amber-400 flex items-center gap-2">
                            <Icons name="sparkles" class="w-4 h-4" />
                            Sugestia AI – rozmówka przed połączeniem
                        </span>
                        <button @click="closeAiReminder" class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors">
                            <Icons name="close" class="w-5 h-5" />
                        </button>
                    </div>
                    <div class="p-4">
                        <p v-if="aiReminderError" class="text-sm text-red-600 dark:text-red-400">{{ aiReminderError }}</p>
                        <p v-else class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line">{{ aiReminderText || 'Ładowanie...' }}</p>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Zadania i klienci -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Zadania na dziś -->
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-6 py-4 border-b border-slate-100">
                    <h3 class="font-semibold text-slate-800">Zadania na dziś</h3>
                </div>
                <div class="p-6">
                    <div v-if="todayTasks.length === 0" class="text-center py-8 text-slate-400">
                        <Icons name="check" class="w-12 h-12 mx-auto mb-3 text-green-500" />
                        <p>Brak zadań na dziś. Świetna robota!</p>
                    </div>
                    <ul v-else class="divide-y divide-slate-100 -my-4">
                        <li v-for="task in todayTasks" :key="task.id" class="py-4">
                            <div class="flex items-start justify-between">
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-slate-800 hover:text-amber-600">
                                        {{ task.title }}
                                    </Link>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span 
                                            :class="[
                                                'px-2 py-0.5 rounded text-xs font-medium',
                                                task.priority === 'urgent' ? 'bg-red-100 text-red-700' :
                                                task.priority === 'high' ? 'bg-yellow-100 text-yellow-700' :
                                                task.priority === 'medium' ? 'bg-blue-100 text-blue-700' :
                                                'bg-slate-100 text-slate-700'
                                            ]"
                                        >
                                            {{ task.priority === 'low' ? 'Niski' : task.priority === 'medium' ? 'Średni' : task.priority === 'high' ? 'Wysoki' : 'Pilny' }}
                                        </span>
                                        <span v-if="task.client" class="text-xs text-slate-500">
                                            {{ task.client.short_name || task.client.name }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="task.status" class="ml-4 flex-shrink-0">
                                    <span 
                                        class="inline-flex items-center px-2 py-1 rounded text-xs font-medium" 
                                        :style="{ backgroundColor: task.status.color + '20', color: task.status.color }"
                                    >
                                        {{ task.status.name }}
                                    </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-if="todayTasks.length > 0" class="px-6 py-3 bg-slate-50 border-t border-slate-100 rounded-b-xl">
                    <Link :href="route('tasks.index', { today: true })" class="text-sm text-amber-600 hover:text-amber-700 font-medium">
                        Zobacz wszystkie zadania na dziś →
                    </Link>
                </div>
            </div>

            <!-- Przeterminowane -->
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-6 py-4 border-b border-slate-100">
                    <h3 class="font-semibold text-slate-800">Przeterminowane zadania</h3>
                </div>
                <div class="p-6">
                    <div v-if="overdueTasks.length === 0" class="text-center py-8 text-slate-400">
                        <Icons name="check" class="w-12 h-12 mx-auto mb-3 text-green-500" />
                        <p>Brak przeterminowanych zadań!</p>
                    </div>
                    <ul v-else class="divide-y divide-slate-100 -my-4">
                        <li v-for="task in overdueTasks" :key="task.id" class="py-4">
                            <div class="flex items-start justify-between">
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-slate-800 hover:text-amber-600">
                                        {{ task.title }}
                                    </Link>
                                    <div class="mt-1 flex items-center gap-2 text-xs text-red-600">
                                        <Icons name="alert" class="w-4 h-4" />
                                        Termin: {{ new Date(task.due_date).toLocaleDateString('pl-PL') }}
                                    </div>
                                </div>
                                <div v-if="task.assignee" class="ml-4 flex-shrink-0 text-xs text-slate-500">
                                    {{ task.assignee.name }}
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-if="overdueTasks.length > 0" class="px-6 py-3 bg-slate-50 border-t border-slate-100 rounded-b-xl">
                    <Link :href="route('tasks.index', { overdue: true })" class="text-sm text-red-600 hover:text-red-700 font-medium">
                        Zobacz wszystkie przeterminowane →
                    </Link>
                </div>
            </div>
        </div>

        <!-- Ostatnio dodani klienci -->
        <div class="bg-white rounded-xl shadow-sm">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-semibold text-slate-800">Ostatnio dodani klienci</h3>
            </div>
            <div class="p-6">
                <div v-if="recentClients.length === 0" class="text-center py-8 text-slate-400">
                    <p>Brak klientów w systemie.</p>
                </div>
                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <Link
                        v-for="client in recentClients"
                        :key="client.id"
                        :href="route('clients.show', client.id)"
                        class="flex items-center p-3 rounded-xl border border-slate-200 hover:border-amber-300 hover:bg-amber-50 transition-colors"
                    >
                        <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-700 font-medium">
                            {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                        </div>
                        <div class="ml-3 min-w-0">
                            <p class="text-sm font-medium text-slate-800 truncate">{{ client.short_name || client.name }}</p>
                            <p class="text-xs text-slate-500">{{ client.type === 'company' ? 'Firma' : 'Osoba' }}</p>
                        </div>
                    </Link>
                </div>
            </div>
            <div class="px-6 py-3 bg-slate-50 border-t border-slate-100 rounded-b-xl">
                <Link :href="route('clients.index')" class="text-sm text-amber-600 hover:text-amber-700 font-medium">
                    Zobacz wszystkich klientów →
                </Link>
            </div>
        </div>
    </div>
</template>
