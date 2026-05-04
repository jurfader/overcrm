<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    calls: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || '');
const callType = ref(props.filters.call_type || '');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');

const playingId = ref(null);
const audioEl = ref(null);

const selectedCall = ref(null);

function applyFilters() {
    router.get(route('ringostat.index'), {
        search: search.value || undefined,
        call_type: callType.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, replace: true });
}

function resetFilters() {
    search.value = '';
    callType.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}

function togglePlay(call) {
    if (!call.recording_url) return;
    if (playingId.value === call.id) {
        audioEl.value?.pause();
        playingId.value = null;
        return;
    }
    if (audioEl.value) audioEl.value.pause();
    playingId.value = call.id;
    audioEl.value = new Audio(call.recording_url);
    audioEl.value.play();
    audioEl.value.onended = () => { playingId.value = null; };
}

function selectCall(call) {
    selectedCall.value = selectedCall.value?.id === call.id ? null : call;
}

const callTypeLabels = {
    incoming: 'Przychodzące',
    outgoing: 'Wychodzące',
    missed: 'Nieodebrane',
};

const isAdmin = computed(() => true); // permissions handled server-side

function syncCalls() {
    if (!confirm('Pobrać nowe połączenia z Play Centrali?')) return;
    router.post(route('ringostat.sync-calls'), {}, {
        onSuccess: () => router.reload({ only: ['calls', 'pagination'] }),
    });
}
</script>

<template>
    <Head title="Połączenia" />

    <AppLayout>
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between gap-4">
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Połączenia</h1>
                <button v-if="isAdmin" @click="syncCalls"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium transition">
                    <Icons name="refresh" class="w-4 h-4" />
                    Synchronizuj
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 p-4 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                <input v-model="search" @keyup.enter="applyFilters" type="search" placeholder="Numer / klient"
                       class="px-3 py-2 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                <select v-model="callType" @change="applyFilters"
                        class="px-3 py-2 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm">
                    <option value="">Wszystkie typy</option>
                    <option value="incoming">Przychodzące</option>
                    <option value="outgoing">Wychodzące</option>
                    <option value="missed">Nieodebrane</option>
                </select>
                <input v-model="dateFrom" @change="applyFilters" type="date"
                       class="px-3 py-2 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                <div class="flex gap-2">
                    <input v-model="dateTo" @change="applyFilters" type="date"
                           class="flex-1 px-3 py-2 rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm" />
                    <button @click="resetFilters"
                            class="px-3 py-2 rounded border border-slate-300 dark:border-slate-600 text-sm hover:bg-slate-100 dark:hover:bg-slate-700">
                        Reset
                    </button>
                </div>
            </div>

            <div class="rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/50 text-xs uppercase text-slate-600 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3 text-left">Data</th>
                            <th class="px-4 py-3 text-left">Typ</th>
                            <th class="px-4 py-3 text-left">Numer</th>
                            <th class="px-4 py-3 text-left">Klient</th>
                            <th class="px-4 py-3 text-left">Pracownik</th>
                            <th class="px-4 py-3 text-right">Czas</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <tr v-for="call in props.calls" :key="call.id"
                            @click="selectCall(call)"
                            :class="['hover:bg-slate-50 dark:hover:bg-slate-700/50 cursor-pointer',
                                     selectedCall?.id === call.id ? 'bg-amber-50 dark:bg-amber-900/20' : '']">
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-300 whitespace-nowrap">{{ call.call_at }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-1 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                                    {{ callTypeLabels[call.call_type] || call.call_type }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-slate-700 dark:text-slate-300">{{ call.caller || call.destination }}</td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ call.client_name || '—' }}</td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ call.employee_name || '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400 font-mono">{{ call.formatted_duration }}</td>
                            <td class="px-4 py-3 text-right">
                                <button v-if="call.has_recording" @click.stop="togglePlay(call)"
                                        class="p-1.5 rounded hover:bg-slate-200 dark:hover:bg-slate-600">
                                    <Icons :name="playingId === call.id ? 'pause' : 'play'" class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!props.calls.length">
                            <td colspan="7" class="px-4 py-12 text-center text-slate-400">Brak połączeń.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
