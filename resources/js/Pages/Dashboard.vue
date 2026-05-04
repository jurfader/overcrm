<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
import Button from '@/Components/Button.vue';

ChartJS.register(
    CategoryScale, LinearScale, PointElement, LineElement, BarElement,
    Title, Tooltip, Legend, Filler
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

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || '');
const isAdmin = computed(() => page.props.auth?.user?.role === 'admin');

const currentPeriod = ref(props.selectedPeriod || 'month');
const periodOptions = [
    { value: 'day', label: 'Dziś' },
    { value: 'week', label: 'Tydzień' },
    { value: 'month', label: 'Miesiąc' },
    { value: 'year', label: 'Rok' },
];

function changePeriod(period) {
    currentPeriod.value = period;
    router.get(route('dashboard'), { period }, { preserveState: true, preserveScroll: true });
}

// =================== Chart colors czytane z CSS vars (reactive na motyw) ===================
const chartColors = ref({
    primary: '#E91E8C',
    secondary: '#9B26D9',
    success: '#22C55E',
    destructive: '#EF4444',
    foregroundMuted: '#8B8BA0',
});

function readChartColors() {
    if (typeof window === 'undefined') return;
    const cs = getComputedStyle(document.documentElement);
    chartColors.value = {
        primary: cs.getPropertyValue('--brand-primary').trim() || '#E91E8C',
        secondary: cs.getPropertyValue('--brand-secondary').trim() || '#9B26D9',
        success: cs.getPropertyValue('--color-success').trim() || '#22C55E',
        destructive: cs.getPropertyValue('--color-destructive').trim() || '#EF4444',
        foregroundMuted: cs.getPropertyValue('--color-muted-foreground').trim() || '#8B8BA0',
    };
}

onMounted(() => {
    readChartColors();
    // Re-read po zmianie motywu
    const observer = new MutationObserver(() => readChartColors());
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
});

function hexToRgba(hex, alpha) {
    const h = hex.replace('#', '');
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

const lineChartData = computed(() => ({
    labels: props.revenueStats?.labels || [],
    datasets: [
        {
            label: 'Przychód',
            data: props.revenueStats?.datasets?.revenue || [],
            borderColor: chartColors.value.primary,
            backgroundColor: hexToRgba(chartColors.value.primary, 0.12),
            fill: true,
            tension: 0.4,
        },
        {
            label: 'Opłacone',
            data: props.revenueStats?.datasets?.paid || [],
            borderColor: chartColors.value.success,
            backgroundColor: hexToRgba(chartColors.value.success, 0.12),
            fill: true,
            tension: 0.4,
        },
    ],
}));

const lineChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'top', labels: { usePointStyle: true, padding: 20, color: chartColors.value.foregroundMuted } },
        tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${formatCurrency(c.raw)}` } },
    },
    scales: {
        x: { ticks: { color: chartColors.value.foregroundMuted }, grid: { color: hexToRgba('#888888', 0.08) } },
        y: { beginAtZero: true, ticks: { color: chartColors.value.foregroundMuted, callback: (v) => formatCurrency(v) }, grid: { color: hexToRgba('#888888', 0.08) } },
    },
}));

const barChartData = computed(() => ({
    labels: props.revenueStats?.labels || [],
    datasets: [
        { label: 'Opłacone',   data: props.revenueStats?.datasets?.paid   || [], backgroundColor: chartColors.value.success,     borderRadius: 4 },
        { label: 'Nieopłacone', data: props.revenueStats?.datasets?.unpaid || [], backgroundColor: chartColors.value.destructive, borderRadius: 4 },
    ],
}));

const barChartOptions = computed(() => ({
    ...lineChartOptions.value,
    scales: {
        x: { ...lineChartOptions.value.scales.x, stacked: true },
        y: { ...lineChartOptions.value.scales.y, stacked: true },
    },
}));

function formatCurrency(value) {
    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value || 0);
}

const priorityClass = {
    urgent: 'bg-destructive/15 text-destructive border-destructive/30',
    high:   'bg-warning/15 text-warning border-warning/30',
    medium: 'bg-info/15 text-info border-info/30',
    low:    'bg-surface-elevated text-foreground-muted border-border',
};
const priorityLabel = { urgent: 'Pilny', high: 'Wysoki', medium: 'Średni', low: 'Niski' };

const statTiles = computed(() => [
    { label: 'Wszystkie zadania', value: props.stats?.tasks ?? 0,        icon: 'tasks',    color: 'brand-primary' },
    { label: 'Na dziś',           value: props.stats?.todayTasks ?? 0,    icon: 'calendar', color: 'info' },
    { label: 'Przeterminowane',   value: props.stats?.overdueTasks ?? 0,  icon: 'alert',    color: 'destructive', highlight: true },
    { label: 'Klienci',           value: props.stats?.clients ?? 0,       icon: 'clients',  color: 'success' },
    { label: 'Użytkownicy',       value: props.stats?.users ?? 0,         icon: 'users',    color: 'brand-secondary' },
]);

function tileIconClass(color) {
    return {
        'brand-primary':   'gradient-brand text-white',
        'brand-secondary': 'gradient-brand text-white',
        'info':            'bg-info/15 text-info',
        'destructive':     'bg-destructive/15 text-destructive',
        'success':         'bg-success/15 text-success',
        'warning':         'bg-warning/15 text-warning',
    }[color];
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="p-6 space-y-6 animate-fade-in">

        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Witaj, {{ userName }}!</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Oto podsumowanie Twojego dnia</p>
            </div>
            <Link :href="route('tasks.create')">
                <Button>
                    <Icons name="plus" class="w-4 h-4" />
                    Nowe zadanie
                </Button>
            </Link>
        </div>

        <!-- Stat tiles -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div v-for="tile in statTiles" :key="tile.label"
                 class="glass-card rounded-lg p-5 flex items-center gap-4">
                <div :class="['shrink-0 w-11 h-11 rounded-lg flex items-center justify-center', tileIconClass(tile.color)]">
                    <Icons :name="tile.icon" class="w-5 h-5" />
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-medium text-foreground-muted uppercase tracking-wide">{{ tile.label }}</p>
                    <p :class="['text-2xl font-bold mt-0.5', tile.highlight && tile.value > 0 ? 'text-destructive' : 'text-foreground']">
                        {{ tile.value }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Przychody (Fakturownia) -->
        <div class="glass-card rounded-lg p-6">
            <div class="flex items-center justify-between mb-6 gap-4 flex-wrap">
                <div>
                    <h2 class="text-lg font-semibold text-foreground">Przychody</h2>
                    <p class="text-sm text-foreground-muted mt-0.5">
                        <template v-if="departmentInfo">
                            <span class="inline-flex items-center gap-1">
                                <Icons name="filter" class="w-3.5 h-3.5" />
                                Filtrowane dla działu: <strong class="text-foreground">{{ departmentInfo.name }}</strong>
                            </span>
                        </template>
                        <template v-else-if="hasFakturowniaIntegration">
                            Wszystkie działy
                        </template>
                        <template v-else>
                            <span class="text-warning">Dane testowe — skonfiguruj integrację w ustawieniach</span>
                        </template>
                    </p>
                </div>

                <div class="inline-flex glass rounded-md p-1">
                    <button v-for="opt in periodOptions" :key="opt.value"
                            @click="changePeriod(opt.value)"
                            :class="[
                                'px-3 py-1.5 text-xs font-medium rounded transition-all',
                                currentPeriod === opt.value
                                    ? 'gradient-brand text-white shadow'
                                    : 'text-foreground-muted hover:text-foreground'
                            ]">
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <!-- Podsumowanie liczb -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="rounded-lg p-4 gradient-brand text-white shadow">
                    <p class="text-xs opacity-90">Przychód całkowity</p>
                    <p class="text-2xl font-bold mt-1">{{ formatCurrency(revenueStats?.totals?.revenue) }}</p>
                </div>
                <div class="rounded-lg p-4 bg-success/10 border border-success/30">
                    <p class="text-xs text-success">Opłacone</p>
                    <p class="text-2xl font-bold text-success mt-1">{{ formatCurrency(revenueStats?.totals?.paid) }}</p>
                </div>
                <div class="rounded-lg p-4 bg-destructive/10 border border-destructive/30">
                    <p class="text-xs text-destructive">Do zapłaty</p>
                    <p class="text-2xl font-bold text-destructive mt-1">{{ formatCurrency(revenueStats?.totals?.unpaid) }}</p>
                </div>
                <div class="rounded-lg p-4 bg-info/10 border border-info/30">
                    <p class="text-xs text-info">Liczba faktur</p>
                    <p class="text-2xl font-bold text-info mt-1">{{ revenueStats?.totals?.invoiceCount || 0 }}</p>
                </div>
            </div>

            <!-- Wykresy -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="surface rounded-lg p-4">
                    <h3 class="text-xs font-semibold text-foreground-muted uppercase tracking-wide mb-4">Trend przychodów</h3>
                    <div class="h-64">
                        <Line :data="lineChartData" :options="lineChartOptions" />
                    </div>
                </div>
                <div class="surface rounded-lg p-4">
                    <h3 class="text-xs font-semibold text-foreground-muted uppercase tracking-wide mb-4">Płatności</h3>
                    <div class="h-64">
                        <Bar :data="barChartData" :options="barChartOptions" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Marżowość -->
        <div v-if="marginStats?.totals" class="glass-card rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                        <Icons name="chart-bar" class="h-4 w-4 text-success" />
                        Marżowość
                    </h2>
                    <p v-if="departmentInfo" class="text-sm text-foreground-muted mt-0.5">
                        Dział: <strong class="text-foreground">{{ departmentInfo.name }}</strong>
                    </p>
                </div>
                <Link v-if="isAdmin" :href="route('reports.margin')" class="text-sm text-brand-primary hover:underline">
                    Pełny raport →
                </Link>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-xl font-bold text-info">{{ formatCurrency(marginStats.totals.revenue) }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Przychód netto</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-xl font-bold text-destructive">{{ formatCurrency(marginStats.totals.cost) }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Koszt zakupu</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-xl font-bold text-success">{{ formatCurrency(marginStats.totals.margin) }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Marża netto</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-xl font-bold text-warning">{{ marginStats.totals.margin_percent }}%</p>
                    <p class="text-xs text-foreground-muted mt-0.5">% marży</p>
                </div>
            </div>

            <div v-if="marginStats.topClients?.length">
                <h3 class="text-xs font-semibold text-foreground-muted uppercase tracking-wide mb-2">Top klienci wg marży</h3>
                <div class="space-y-2">
                    <div v-for="(client, idx) in marginStats.topClients.slice(0, 5)" :key="idx"
                         class="flex items-center justify-between px-3 py-2 surface-elevated rounded-md">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-sm font-bold text-foreground-subtle w-5 text-right">{{ idx + 1 }}</span>
                            <span class="text-sm font-medium text-foreground truncate">{{ client.name }}</span>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <span class="text-xs text-foreground-muted hidden sm:inline">{{ formatCurrency(client.revenue) }}</span>
                            <span class="text-sm font-semibold text-success">{{ formatCurrency(client.margin) }}</span>
                            <span :class="[
                                'text-xs px-1.5 py-0.5 rounded font-semibold border',
                                client.margin_percent >= 30 ? 'bg-success/15 text-success border-success/30'
                                    : client.margin_percent >= 15 ? 'bg-warning/15 text-warning border-warning/30'
                                    : 'bg-destructive/15 text-destructive border-destructive/30'
                            ]">{{ client.margin_percent }}%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Połączenia (Ringostat) -->
        <div v-if="hasRingostatIntegration && callStats" class="glass-card rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <Icons name="phone" class="h-4 w-4 text-brand-primary" />
                    Połączenia dziś
                </h2>
                <Link :href="route('ringostat.index')" class="text-sm text-brand-primary hover:underline">
                    Zobacz wszystkie →
                </Link>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-2xl font-bold text-info">{{ callStats.total }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Wszystkie</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-2xl font-bold text-success">{{ callStats.answered }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Odebrane</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-2xl font-bold text-destructive">{{ callStats.missed }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Nieodebrane</p>
                </div>
                <div class="surface-elevated rounded-md p-3 text-center">
                    <p class="text-2xl font-bold text-foreground">{{ Math.floor(callStats.avg_duration / 60) }}:{{ String(callStats.avg_duration % 60).padStart(2, '0') }}</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Śr. czas</p>
                </div>
            </div>

            <!-- Mini trend 7 dni -->
            <div v-if="callTrend?.length" class="flex items-end gap-1 h-16">
                <div v-for="(day, idx) in callTrend" :key="idx" class="flex-1 flex flex-col items-center gap-0.5">
                    <div class="w-full flex flex-col items-center">
                        <div class="w-full bg-success rounded-t" :style="{ height: (day.answered / Math.max(...callTrend.map(d => d.total), 1)) * 40 + 2 + 'px' }" />
                        <div class="w-full bg-destructive/70 rounded-b" :style="{ height: ((day.total - day.answered) / Math.max(...callTrend.map(d => d.total), 1)) * 40 + 'px' }" />
                    </div>
                    <span class="text-[9px] text-foreground-subtle">{{ day.date }}</span>
                </div>
            </div>
        </div>

        <!-- Urodziny lokali -->
        <div v-if="venueBirthdaysUpcoming?.length" class="glass-card rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <Icons name="cake" class="h-4 w-4 text-brand-primary" />
                    Urodziny lokali
                </h2>
                <p class="text-sm text-foreground-muted mt-0.5">
                    Lokale z rocznicą otwarcia w najbliższych 30 dniach
                </p>
            </div>
            <ul class="p-6 space-y-2">
                <li v-for="item in venueBirthdaysUpcoming" :key="item.id"
                    class="flex items-center justify-between gap-3 p-3 rounded-md gradient-subtle hover:bg-surface-elevated transition-colors">
                    <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-foreground hover:text-brand-primary truncate">
                        {{ item.name }}
                    </Link>
                    <span class="text-xs text-foreground-muted shrink-0">
                        {{ new Date(item.date).toLocaleDateString('pl-PL') }}
                        <span class="text-brand-primary font-medium ml-1">
                            {{ item.days_until === 0 ? 'dziś' : item.days_until === 1 ? 'za 1 dzień' : `za ${item.days_until} dni` }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>

        <!-- Warto zadzwonić -->
        <div v-if="hasRingostatIntegration && (clientsToCall?.length || clientsAfterVisit?.length)"
             class="glass-card rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <Icons name="phone" class="h-4 w-4 text-brand-primary" />
                    Warto zadzwonić
                </h2>
                <p class="text-sm text-foreground-muted mt-0.5">
                    Klienci do których warto się przypomnieć
                </p>
            </div>
            <div class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div v-if="clientsToCall?.length">
                    <h3 class="text-xs font-semibold text-foreground-muted uppercase tracking-wide mb-3">Ostatnie połączenie 7+ dni temu</h3>
                    <ul class="space-y-2">
                        <li v-for="item in clientsToCall" :key="'call-' + item.id"
                            class="flex items-center justify-between gap-3 p-3 rounded-md surface-elevated hover:bg-surface-hover transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-foreground hover:text-brand-primary truncate block">
                                    {{ item.name }}
                                </Link>
                                <p class="text-xs text-foreground-muted">Ostatnie połączenie: {{ item.days_ago }} dni temu</p>
                            </div>
                            <ClickToCall v-if="item.phone" :phone="item.phone" size="md" />
                        </li>
                    </ul>
                </div>
                <div v-if="clientsAfterVisit?.length">
                    <h3 class="text-xs font-semibold text-foreground-muted uppercase tracking-wide mb-3">Wizyta bez oddzwonienia</h3>
                    <ul class="space-y-2">
                        <li v-for="item in clientsAfterVisit" :key="'visit-' + item.id"
                            class="flex items-center justify-between gap-3 p-3 rounded-md gradient-subtle hover:bg-surface-elevated transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-foreground hover:text-brand-primary truncate block">
                                    {{ item.name }}
                                </Link>
                                <p class="text-xs text-foreground-muted">Wizyta {{ item.days_ago }} dni temu</p>
                            </div>
                            <ClickToCall v-if="item.phone" :phone="item.phone" size="md" />
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Zadania na dziś + Przeterminowane -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Today -->
            <div class="glass-card rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-border">
                    <h3 class="font-semibold text-foreground">Zadania na dziś</h3>
                </div>
                <div class="p-6">
                    <div v-if="!todayTasks?.length" class="text-center py-8 text-foreground-subtle">
                        <Icons name="check" class="w-10 h-10 mx-auto mb-3 text-success" />
                        <p class="text-sm">Brak zadań na dziś. Świetna robota!</p>
                    </div>
                    <ul v-else class="divide-y divide-border -my-3">
                        <li v-for="task in todayTasks" :key="task.id" class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-foreground hover:text-brand-primary">
                                        {{ task.title }}
                                    </Link>
                                    <div class="mt-1 flex items-center gap-2 flex-wrap">
                                        <span :class="['inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border', priorityClass[task.priority] || priorityClass.low]">
                                            {{ priorityLabel[task.priority] || 'Niski' }}
                                        </span>
                                        <span v-if="task.client" class="text-xs text-foreground-muted">
                                            {{ task.client.short_name || task.client.name }}
                                        </span>
                                    </div>
                                </div>
                                <span v-if="task.status"
                                      class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium shrink-0 border"
                                      :style="{ backgroundColor: task.status.color + '20', color: task.status.color, borderColor: task.status.color + '50' }">
                                    {{ task.status.name }}
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-if="todayTasks?.length" class="px-6 py-3 border-t border-border bg-surface-2">
                    <Link :href="route('tasks.index', { today: true })" class="text-sm text-brand-primary hover:underline font-medium">
                        Zobacz wszystkie zadania na dziś →
                    </Link>
                </div>
            </div>

            <!-- Overdue -->
            <div class="glass-card rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-border">
                    <h3 class="font-semibold text-foreground">Przeterminowane zadania</h3>
                </div>
                <div class="p-6">
                    <div v-if="!overdueTasks?.length" class="text-center py-8 text-foreground-subtle">
                        <Icons name="check" class="w-10 h-10 mx-auto mb-3 text-success" />
                        <p class="text-sm">Brak przeterminowanych zadań!</p>
                    </div>
                    <ul v-else class="divide-y divide-border -my-3">
                        <li v-for="task in overdueTasks" :key="task.id" class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-foreground hover:text-brand-primary">
                                        {{ task.title }}
                                    </Link>
                                    <div class="mt-1 flex items-center gap-1.5 text-xs text-destructive">
                                        <Icons name="alert" class="w-3.5 h-3.5" />
                                        Termin: {{ new Date(task.due_date).toLocaleDateString('pl-PL') }}
                                    </div>
                                </div>
                                <div v-if="task.assignee" class="shrink-0 text-xs text-foreground-muted">
                                    {{ task.assignee.name }}
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-if="overdueTasks?.length" class="px-6 py-3 border-t border-border bg-surface-2">
                    <Link :href="route('tasks.index', { overdue: true })" class="text-sm text-destructive hover:underline font-medium">
                        Zobacz wszystkie przeterminowane →
                    </Link>
                </div>
            </div>
        </div>

        <!-- Ostatnio dodani klienci -->
        <div class="glass-card rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-border">
                <h3 class="font-semibold text-foreground">Ostatnio dodani klienci</h3>
            </div>
            <div class="p-6">
                <div v-if="!recentClients?.length" class="text-center py-8 text-foreground-subtle">
                    <p class="text-sm">Brak klientów w systemie.</p>
                </div>
                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <Link
                        v-for="client in recentClients" :key="client.id"
                        :href="route('clients.show', client.id)"
                        class="flex items-center gap-3 p-3 rounded-md surface hover:border-brand-primary/50 transition-all group"
                    >
                        <div class="w-9 h-9 shrink-0 rounded-full gradient-brand flex items-center justify-center text-white text-xs font-semibold">
                            {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground group-hover:text-brand-primary truncate">{{ client.short_name || client.name }}</p>
                            <p class="text-xs text-foreground-muted">{{ client.type === 'company' ? 'Firma' : 'Osoba' }}</p>
                        </div>
                    </Link>
                </div>
            </div>
            <div class="px-6 py-3 border-t border-border bg-surface-2">
                <Link :href="route('clients.index')" class="text-sm text-brand-primary hover:underline font-medium">
                    Zobacz wszystkich klientów →
                </Link>
            </div>
        </div>
    </div>
</template>
