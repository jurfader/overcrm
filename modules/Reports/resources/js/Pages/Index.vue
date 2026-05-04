<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { Line, Bar } from 'vue-chartjs';
import { 
    Chart as ChartJS, 
    Title, 
    Tooltip, 
    Legend, 
    LineElement, 
    BarElement,
    CategoryScale, 
    LinearScale, 
    PointElement 
} from 'chart.js';
import Icons from '@/Components/Icons.vue';

ChartJS.register(Title, Tooltip, Legend, LineElement, BarElement, CategoryScale, LinearScale, PointElement);

const props = defineProps({
    period: String,
    dateRange: Object,
    statistics: Object,
    chartData: Object,
});

const selectedPeriod = ref(props.period);

const periods = [
    { value: 'week', label: 'Tydzień' },
    { value: 'month', label: 'Miesiąc' },
    { value: 'quarter', label: 'Kwartał' },
    { value: 'year', label: 'Rok' },
];

function changePeriod(period) {
    selectedPeriod.value = period;
    router.get(route('reports.index'), { period }, { preserveState: true });
}

function formatCurrency(value) {
    return new Intl.NumberFormat('pl-PL', { 
        style: 'currency', 
        currency: 'PLN',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value || 0);
}

const visitsChartData = computed(() => ({
    labels: props.chartData?.visits?.labels || [],
    datasets: [{
        label: 'Wizyty',
        data: props.chartData?.visits?.data || [],
        borderColor: '#3B82F6',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        fill: true,
        tension: 0.4,
    }],
}));

const revenueChartData = computed(() => ({
    labels: props.chartData?.revenue?.labels || [],
    datasets: [{
        label: 'Przychód (PLN)',
        data: props.chartData?.revenue?.data || [],
        backgroundColor: '#10B981',
        borderRadius: 4,
    }],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
    },
    scales: {
        y: {
            beginAtZero: true,
        },
    },
};
</script>

<template>
    <Head title="Raporty" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Raporty</h1>
                <p class="text-slate-500">{{ dateRange.label }}</p>
            </div>
            
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Link do marżowości -->
                <Link :href="route('reports.margin', { period: selectedPeriod })"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 text-sm font-medium transition">
                    <Icons name="chart-bar" class="w-4 h-4" />
                    Marżowość
                </Link>

            <!-- Wybór okresu -->
            <div class="flex items-center gap-1 bg-white rounded-lg shadow p-1">
                <button
                    v-for="p in periods"
                    :key="p.value"
                    @click="changePeriod(p.value)"
                    :class="[
                        'px-4 py-2 text-sm font-medium rounded-md transition-colors',
                        selectedPeriod === p.value 
                            ? 'bg-amber-500 text-white' 
                            : 'text-slate-600 hover:bg-slate-100'
                    ]"
                >
                    {{ p.label }}
                </button>
            </div>
            </div>
        </div>

        <!-- Statystyki -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Wizyty -->
            <div class="bg-white rounded-xl shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                        <Icons name="calendar" class="w-6 h-6 text-blue-600" />
                    </div>
                    <span class="text-sm text-slate-500">{{ statistics.visits.conversion }}% realizacji</span>
                </div>
                <h3 class="text-3xl font-bold text-slate-800">{{ statistics.visits.total }}</h3>
                <p class="text-slate-500">Wszystkie wizyty</p>
                <div class="mt-2 text-sm text-green-600">
                    {{ statistics.visits.completed }} zakończonych
                </div>
            </div>

            <!-- Przychód -->
            <div class="bg-white rounded-xl shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                        <Icons name="cash" class="w-6 h-6 text-green-600" />
                    </div>
                </div>
                <h3 class="text-3xl font-bold text-slate-800">{{ formatCurrency(statistics.revenue.total) }}</h3>
                <p class="text-slate-500">Łączny przychód</p>
                <div class="mt-2 text-sm text-slate-500">
                    Średnia: {{ formatCurrency(statistics.revenue.average) }} / wizyta
                </div>
            </div>

            <!-- Klienci -->
            <div class="bg-white rounded-xl shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                        <Icons name="users" class="w-6 h-6 text-purple-600" />
                    </div>
                </div>
                <h3 class="text-3xl font-bold text-slate-800">{{ statistics.clients.new }}</h3>
                <p class="text-slate-500">Nowych klientów</p>
                <div class="mt-2 text-sm text-slate-500">
                    {{ statistics.clients.active }} aktywnych łącznie
                </div>
            </div>

            <!-- Zadania -->
            <div class="bg-white rounded-xl shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center">
                        <Icons name="tasks" class="w-6 h-6 text-amber-600" />
                    </div>
                    <span class="text-sm text-slate-500">{{ statistics.tasks.completion_rate }}% ukończonych</span>
                </div>
                <h3 class="text-3xl font-bold text-slate-800">{{ statistics.tasks.total }}</h3>
                <p class="text-slate-500">Zadań</p>
                <div class="mt-2 text-sm text-green-600">
                    {{ statistics.tasks.completed }} ukończonych
                </div>
            </div>
        </div>

        <!-- Wykresy -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Wykres wizyt -->
            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="text-lg font-semibold text-slate-800 mb-4">Wizyty w czasie</h3>
                <div class="h-64">
                    <Line :data="visitsChartData" :options="chartOptions" />
                </div>
            </div>

            <!-- Wykres przychodów -->
            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="text-lg font-semibold text-slate-800 mb-4">Przychody w czasie</h3>
                <div class="h-64">
                    <Bar :data="revenueChartData" :options="chartOptions" />
                </div>
            </div>
        </div>
    </div>
</template>
