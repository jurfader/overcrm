<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    users: Array,
    activities: Array,
    visitsSummary: Array,
    selectedUser: Object,
    filters: Object,
});

const date = ref(props.filters.date);
const userId = ref(props.filters.user_id);

// Ringostat - połączenia
const reportCalls = ref([]);
const reportCallStats = ref(null);
const loadingReportCalls = ref(false);

function loadReportCalls() {
    if (!userId.value) return;
    loadingReportCalls.value = true;

    fetch(route('ringostat.daily-report-calls') + '?date=' + date.value + '&user_id=' + userId.value, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        reportCalls.value = data.calls || [];
        reportCallStats.value = data.stats || null;
    })
    .catch(() => {})
    .finally(() => loadingReportCalls.value = false);
}

// Audio player
const playingReportCallId = ref(null);
const reportAudio = ref(null);

function toggleReportPlay(call) {
    const url = call.recording_url;
    if (!url) return;
    if (playingReportCallId.value === call.id) {
        reportAudio.value?.pause();
        reportAudio.value = null;
        playingReportCallId.value = null;
        return;
    }
    reportAudio.value?.pause();
    playingReportCallId.value = call.id;
    reportAudio.value = new Audio(url);
    reportAudio.value.play();
    reportAudio.value.onended = () => { playingReportCallId.value = null; reportAudio.value = null; };
}

function applyFilters() {
    router.get(route('admin.daily-report'), {
        date: date.value,
        user_id: userId.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

watch([date, userId], () => {
    if (userId.value) {
        applyFilters();
        loadReportCalls();
    }
});

function prevDay() {
    const d = new Date(date.value);
    d.setDate(d.getDate() - 1);
    date.value = d.toISOString().split('T')[0];
}

function nextDay() {
    const d = new Date(date.value);
    d.setDate(d.getDate() + 1);
    date.value = d.toISOString().split('T')[0];
}

function today() {
    date.value = new Date().toISOString().split('T')[0];
}

// Nazwa dnia tygodnia
const dayName = computed(() => {
    const days = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
    return days[new Date(date.value).getDay()];
});

const formattedDate = computed(() => {
    return new Date(date.value).toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' });
});

// Grupuj aktywności po kliencie/zadaniu
const groupedActivities = computed(() => {
    if (!props.activities || props.activities.length === 0) return [];

    const groups = {};

    for (const act of props.activities) {
        const key = act.model_type + ':' + act.model_id;
        if (!groups[key]) {
            groups[key] = {
                model_type: act.model_type,
                model_id: act.model_id,
                model_name: act.model_name,
                label: extractLabel(act),
                entries: [],
            };
        }
        groups[key].entries.push(act);
    }

    return Object.values(groups);
});

function extractLabel(act) {
    if (act.description) {
        // Wyciągnij nazwę klienta/zadania z opisu
        const match = act.description.match(/:\s*(.+)$/);
        if (match) return match[1];
    }
    return act.model_name + ' #' + act.model_id;
}

// Statystyki dnia
const stats = computed(() => {
    const total = props.activities?.length ?? 0;
    const creates = props.activities?.filter(a => a.action === 'create').length ?? 0;
    const updates = props.activities?.filter(a => a.action === 'update').length ?? 0;
    const deletes = props.activities?.filter(a => a.action === 'delete').length ?? 0;
    const uniqueClients = new Set(
        props.activities
            ?.filter(a => a.model_name === 'Wizyta' || a.model_name === 'Zadanie')
            .map(a => a.model_id) ?? []
    ).size;

    return { total, creates, updates, deletes, uniqueClients };
});

// Pola zmian
const fieldLabels = {
    title: 'Tytuł',
    description: 'Opis',
    notes: 'Notatki',
    visit_date: 'Data wizyty',
    visit_time: 'Godzina',
    status_id: 'Status',
    status: 'Status',
    client_id: 'Klient',
    color: 'Kolor',
    link: 'Link',
    deadline: 'Deadline',
    order_value: 'Wartość zamówienia',
    priority: 'Priorytet',
    assigned_to: 'Przypisany do',
    due_date: 'Termin',
    submit_date: 'Data zgłoszenia',
    estimated_hours: 'Szacowany czas',
    completed_at: 'Data ukończenia',
    user_id: 'Użytkownik',
};

function getChangedFields(log) {
    if (!log.old_values || !log.new_values) return [];
    const changes = [];
    const skip = ['updated_at', 'created_at', 'created_by', 'deleted_at', 'id', 'apilo_order_id'];

    for (const key of Object.keys(log.new_values)) {
        if (skip.includes(key)) continue;
        const oldVal = log.old_values[key];
        const newVal = log.new_values[key];
        if (String(oldVal ?? '') !== String(newVal ?? '')) {
            changes.push({
                field: fieldLabels[key] || key,
                key,
                old: formatValue(key, oldVal),
                new: formatValue(key, newVal),
            });
        }
    }
    return changes;
}

function formatValue(key, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (key === 'visit_date' || key === 'due_date' || key === 'submit_date' || key === 'deadline' || key === 'completed_at') {
        try {
            return new Date(value).toLocaleDateString('pl-PL');
        } catch {
            return value;
        }
    }
    if (typeof value === 'string' && value.length > 120) {
        return value.substring(0, 120) + '...';
    }
    return String(value);
}
</script>

<template>
    <Head title="Raport dzienny" />

    <div class="space-y-6">
        <!-- Nagłówek -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-slate-100">Raport dzienny pracy</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Sprawdź z jakimi klientami pracował użytkownik danego dnia</p>
            </div>
        </div>

        <!-- Filtry -->
        <Card>
            <div class="flex flex-wrap items-end gap-4">
                <!-- Wybór użytkownika -->
                <div class="min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Użytkownik</label>
                    <select
                        v-model="userId"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-sm"
                    >
                        <option value="">— Wybierz użytkownika —</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">
                            {{ user.name }}
                        </option>
                    </select>
                </div>

                <!-- Nawigacja daty -->
                <div class="min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Data</label>
                    <div class="flex items-center gap-1">
                        <button
                            @click="prevDay"
                            class="p-2 rounded-lg border border-gray-300 dark:border-slate-600 hover:bg-gray-100 dark:hover:bg-slate-600 text-gray-500 dark:text-slate-400 transition-colors"
                        >
                            <Icons name="chevron-left" class="w-4 h-4" />
                        </button>
                        <input
                            type="date"
                            v-model="date"
                            class="flex-1 px-3 py-2 border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-sm"
                        />
                        <button
                            @click="nextDay"
                            class="p-2 rounded-lg border border-gray-300 dark:border-slate-600 hover:bg-gray-100 dark:hover:bg-slate-600 text-gray-500 dark:text-slate-400 transition-colors"
                        >
                            <Icons name="chevron-right" class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <!-- Dzisiaj -->
                <button
                    @click="today"
                    class="px-3 py-2 text-sm font-medium text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors"
                >
                    Dzisiaj
                </button>

                <!-- Info dnia -->
                <div class="text-sm text-gray-500 dark:text-slate-400 ml-auto">
                    <span class="font-medium text-gray-700 dark:text-slate-300">{{ dayName }},</span>
                    {{ formattedDate }}
                </div>
            </div>
        </Card>

        <!-- Brak wybranego użytkownika -->
        <Card v-if="!userId">
            <div class="text-center py-12">
                <Icons name="users" class="w-12 h-12 text-gray-300 dark:text-slate-600 mx-auto mb-3" />
                <p class="text-gray-500 dark:text-slate-400">Wybierz użytkownika aby zobaczyć raport dzienny</p>
            </div>
        </Card>

        <!-- Wyniki -->
        <template v-else>
            <!-- Statystyki dnia -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 text-center">
                    <div class="text-2xl font-bold text-gray-900 dark:text-slate-100">{{ stats.total }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">Wszystkich akcji</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 text-center">
                    <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ stats.updates }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">Aktualizacji</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 text-center">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ stats.creates }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">Utworzeń</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 text-center">
                    <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ stats.deletes }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">Usunięć</div>
                </div>
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.uniqueClients }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400 mt-1">Klientów/zadań</div>
                </div>
            </div>

            <!-- Wizyty na ten dzień (klienci w kalendarzu) -->
            <Card v-if="visitsSummary && visitsSummary.length > 0" title="Klienci w kalendarzu na ten dzień">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-slate-700">
                                <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Godz.</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Klient</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Tytuł</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Status</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Opis</th>
                                <th class="text-right py-2 px-3 font-medium text-gray-500 dark:text-slate-400">Wartość</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr 
                                v-for="visit in visitsSummary" 
                                :key="visit.id"
                                class="border-b border-gray-100 dark:border-slate-700/50 hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors"
                            >
                                <td class="py-2.5 px-3 text-gray-500 dark:text-slate-400 font-mono text-xs">
                                    {{ visit.visit_time || '—' }}
                                </td>
                                <td class="py-2.5 px-3 font-medium text-gray-900 dark:text-slate-200">
                                    {{ visit.client_name }}
                                </td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-slate-300">
                                    {{ visit.title || '—' }}
                                </td>
                                <td class="py-2.5 px-3">
                                    <span 
                                        v-if="visit.status_name" 
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                        :style="{ backgroundColor: (visit.status_color || '#gray') + '20', color: visit.status_color }"
                                    >
                                        {{ visit.status_name }}
                                    </span>
                                    <span v-else class="text-gray-400 dark:text-slate-500">—</span>
                                </td>
                                <td class="py-2.5 px-3 text-gray-500 dark:text-slate-400 max-w-[250px] truncate">
                                    {{ visit.description || visit.notes || '—' }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-medium text-gray-900 dark:text-slate-200">
                                    <span v-if="visit.order_value">{{ Number(visit.order_value).toLocaleString('pl-PL', { style: 'currency', currency: 'PLN' }) }}</span>
                                    <span v-else class="text-gray-400 dark:text-slate-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Połączenia z Ringostat -->
            <Card title="Połączenia telefoniczne">
                <div v-if="loadingReportCalls" class="text-center py-6 text-gray-400">Ładowanie połączeń...</div>
                <div v-else-if="reportCalls.length === 0" class="text-center py-6 text-gray-400">
                    <Icons name="phone" class="w-8 h-8 mx-auto mb-2 opacity-30" />
                    <p>Brak połączeń w tym dniu</p>
                </div>
                <template v-else>
                    <!-- Statystyki połączeń -->
                    <div v-if="reportCallStats" class="grid grid-cols-4 gap-3 mb-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-2 text-center">
                            <p class="text-lg font-bold text-blue-600 dark:text-blue-400">{{ reportCallStats.total }}</p>
                            <p class="text-[10px] text-blue-500">Wszystkie</p>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-2 text-center">
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ reportCallStats.answered }}</p>
                            <p class="text-[10px] text-green-500">Odebrane</p>
                        </div>
                        <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-2 text-center">
                            <p class="text-lg font-bold text-red-600 dark:text-red-400">{{ reportCallStats.missed }}</p>
                            <p class="text-[10px] text-red-500">Nieodebrane</p>
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-2 text-center">
                            <p class="text-lg font-bold text-purple-600 dark:text-purple-400">{{ reportCallStats.formatted_total_duration }}</p>
                            <p class="text-[10px] text-purple-500">Łączny czas</p>
                        </div>
                    </div>

                    <!-- Tabela połączeń -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-slate-800/50">
                                <tr>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Godzina</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Typ</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Numer</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Klient</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Status</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Czas</th>
                                    <th class="py-2 px-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400">Nagranie</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-slate-700/50">
                                <tr v-for="call in reportCalls" :key="call.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/30">
                                    <td class="py-2 px-3 font-mono text-gray-600 dark:text-slate-300">{{ call.call_date }}</td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center gap-1">
                                            <Icons
                                                :name="call.call_type === 'out' ? 'phone-outgoing' : 'phone-incoming'"
                                                :class="['h-3.5 w-3.5', call.call_type === 'out' ? 'text-blue-500' : 'text-green-500']"
                                            />
                                            <span class="text-xs text-gray-500">{{ call.call_type_label }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2 px-3 font-mono text-gray-700 dark:text-slate-200">
                                        {{ call.call_type === 'out' ? call.destination : call.caller }}
                                    </td>
                                    <td class="py-2 px-3">
                                        <span v-if="call.client" class="text-amber-600 dark:text-amber-400 font-medium">{{ call.client.name }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="py-2 px-3">
                                        <span :class="[
                                            'px-1.5 py-0.5 rounded text-xs font-medium',
                                            call.disposition === 'ANSWERED'
                                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                        ]">
                                            {{ call.disposition_label }}
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 text-gray-600 dark:text-slate-300">{{ call.formatted_duration }}</td>
                                    <td class="py-2 px-3">
                                        <button
                                            v-if="call.has_recording"
                                            @click="toggleReportPlay(call)"
                                            :class="[
                                                'p-1 rounded text-xs transition',
                                                playingReportCallId === call.id ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-slate-700 dark:text-slate-300'
                                            ]"
                                        >
                                            <Icons :name="playingReportCallId === call.id ? 'pause' : 'play'" class="h-3.5 w-3.5" />
                                        </button>
                                        <span v-else class="text-gray-400 text-xs">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </Card>

            <!-- Szczegółowa historia zmian -->
            <Card title="Historia zmian w tym dniu">
                <template v-if="activities && activities.length > 0">
                    <!-- Grupowanie po kliencie/zadaniu -->
                    <div class="space-y-6">
                        <div 
                            v-for="group in groupedActivities" 
                            :key="group.model_type + ':' + group.model_id"
                            class="border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden"
                        >
                            <!-- Nagłówek grupy -->
                            <div class="bg-gray-50 dark:bg-slate-800/50 px-4 py-3 flex items-center gap-2 border-b border-gray-200 dark:border-slate-700">
                                <span class="text-xs font-medium uppercase tracking-wider px-2 py-0.5 rounded" :class="{
                                    'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400': group.model_name === 'Wizyta',
                                    'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400': group.model_name === 'Zadanie',
                                    'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400': group.model_name === 'Klient',
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': !['Wizyta','Zadanie','Klient'].includes(group.model_name),
                                }">{{ group.model_name }}</span>
                                <span class="font-medium text-gray-900 dark:text-slate-200">{{ group.label }}</span>
                                <Badge color="gray" class="ml-auto">{{ group.entries.length }} zmian</Badge>
                            </div>

                            <!-- Wpisy -->
                            <div class="divide-y divide-gray-100 dark:divide-slate-700/50">
                                <div 
                                    v-for="entry in group.entries" 
                                    :key="entry.id"
                                    class="px-4 py-3"
                                >
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs font-mono text-gray-400 dark:text-slate-500 w-16">{{ entry.time }}</span>
                                        <span class="text-xs px-1.5 py-0.5 rounded font-medium" :class="{
                                            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': entry.action === 'create',
                                            'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': entry.action === 'update',
                                            'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': entry.action === 'delete',
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': !['create','update','delete'].includes(entry.action),
                                        }">{{ entry.action_label }}</span>
                                        <span v-if="entry.description" class="text-xs text-gray-500 dark:text-slate-400">{{ entry.description }}</span>
                                    </div>

                                    <!-- Zmienione pola -->
                                    <div v-if="entry.action === 'update' && getChangedFields(entry).length > 0" class="ml-16 mt-1.5 space-y-1">
                                        <div 
                                            v-for="change in getChangedFields(entry)" 
                                            :key="change.key"
                                            class="text-xs bg-gray-50 dark:bg-slate-900/50 rounded px-3 py-1.5 flex items-start gap-2 flex-wrap"
                                        >
                                            <span class="font-medium text-gray-600 dark:text-slate-400 shrink-0 min-w-[100px]">{{ change.field }}:</span>
                                            <span v-if="change.old !== '—'" class="text-red-500 dark:text-red-400 line-through break-all">{{ change.old }}</span>
                                            <span class="text-gray-400 dark:text-slate-600">→</span>
                                            <span class="text-green-600 dark:text-green-400 font-medium break-all">{{ change.new }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wszystkie akcje chronologicznie -->
                    <details class="mt-6">
                        <summary class="cursor-pointer text-sm font-medium text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300">
                            Pokaż chronologicznie ({{ activities.length }} akcji)
                        </summary>
                        <div class="mt-3 space-y-0">
                            <div 
                                v-for="(act, index) in activities" 
                                :key="act.id"
                                class="relative pl-6 pb-4 last:pb-0"
                            >
                                <!-- Linia -->
                                <div v-if="index < activities.length - 1" class="absolute left-[9px] top-5 bottom-0 w-px bg-gray-200 dark:bg-slate-700"></div>

                                <!-- Kropka -->
                                <div class="absolute left-0 top-1 w-[18px] h-[18px] rounded-full border-2 flex items-center justify-center" :class="{
                                    'bg-green-100 border-green-400 dark:bg-green-900/30 dark:border-green-600': act.action === 'create',
                                    'bg-amber-100 border-amber-400 dark:bg-amber-900/30 dark:border-amber-600': act.action === 'update',
                                    'bg-red-100 border-red-400 dark:bg-red-900/30 dark:border-red-600': act.action === 'delete',
                                    'bg-gray-100 border-gray-400 dark:bg-gray-700 dark:border-gray-500': !['create','update','delete'].includes(act.action),
                                }">
                                    <div class="w-1.5 h-1.5 rounded-full" :class="{
                                        'bg-green-500': act.action === 'create',
                                        'bg-amber-500': act.action === 'update',
                                        'bg-red-500': act.action === 'delete',
                                        'bg-gray-400': !['create','update','delete'].includes(act.action),
                                    }"></div>
                                </div>

                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-mono text-gray-400 dark:text-slate-500">{{ act.time }}</span>
                                        <span class="text-xs px-1.5 py-0.5 rounded font-medium" :class="{
                                            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': act.action === 'create',
                                            'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': act.action === 'update',
                                            'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': act.action === 'delete',
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': !['create','update','delete'].includes(act.action),
                                        }">{{ act.action_label }}</span>
                                        <Badge color="gray" size="sm">{{ act.model_name }}</Badge>
                                        <span class="text-xs text-gray-500 dark:text-slate-400">{{ act.description }}</span>
                                    </div>

                                    <!-- Diff -->
                                    <div v-if="act.action === 'update' && getChangedFields(act).length > 0" class="mt-1.5 space-y-1">
                                        <div 
                                            v-for="change in getChangedFields(act)" 
                                            :key="change.key"
                                            class="text-xs bg-gray-50 dark:bg-slate-900/50 rounded px-3 py-1.5 flex items-start gap-2 flex-wrap"
                                        >
                                            <span class="font-medium text-gray-600 dark:text-slate-400 shrink-0 min-w-[100px]">{{ change.field }}:</span>
                                            <span v-if="change.old !== '—'" class="text-red-500 dark:text-red-400 line-through break-all">{{ change.old }}</span>
                                            <span class="text-gray-400 dark:text-slate-600">→</span>
                                            <span class="text-green-600 dark:text-green-400 font-medium break-all">{{ change.new }}</span>
                                        </div>
                                    </div>

                                    <span class="text-[10px] text-gray-300 dark:text-slate-600 font-mono">IP: {{ act.ip_address }}</span>
                                </div>
                            </div>
                        </div>
                    </details>
                </template>

                <!-- Brak aktywności -->
                <div v-else class="text-center py-12">
                    <Icons name="search" class="w-12 h-12 text-gray-300 dark:text-slate-600 mx-auto mb-3" />
                    <p class="text-gray-500 dark:text-slate-400">Brak zarejestrowanych zmian dla tego użytkownika w tym dniu</p>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">Zmiany są rejestrowane od momentu wdrożenia logowania aktywności</p>
                </div>
            </Card>
        </template>
    </div>
</template>
