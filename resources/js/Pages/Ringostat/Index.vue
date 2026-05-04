<script setup>
import { ref, computed, watch, onBeforeUnmount, onMounted, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    calls: Object,
    stats: Object,
    users: Array,
    filters: Object,
    isConfigured: Boolean,
    isAdmin: Boolean,
});

const localFilters = ref({
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    user_id: props.filters?.user_id || '',
    call_type: props.filters?.call_type || '',
    disposition: props.filters?.disposition || '',
    search: props.filters?.search || '',
});

const syncing = ref(false);
const syncMessage = ref('');

// Audio player
const playingCallId = ref(null);
const playingCall = ref(null);
const audioRef = ref(null);
const audioCurrentTime = ref(0);
const audioDuration = ref(0);
const audioLoading = ref(false);
const analyzingCallId = ref(null);
const analyzeError = ref('');

// Selected call for right panel (split-view)
const selectedCallId = ref(null);
const selectedCall = computed(() => props.calls.data.find(c => c.id === selectedCallId.value));
const activeTab = ref('info');

// Progress bar analizy AI
const analyzeProgress = ref(0);
const analyzeStage = ref('');
let _progressInterval = null;

const ANALYZE_STAGES = [
    { label: 'Pobieranie nagrania…', target: 12, duration: 4000 },
    { label: 'Odszyfrowanie (RSA)…', target: 25, duration: 3000 },
    { label: 'Transkrypcja audio…', target: 62, duration: 25000 },
    { label: 'Analiza AI…', target: 90, duration: 45000 },
    { label: 'Zapisywanie wyników…', target: 97, duration: 5000 },
];

function startAnalyzeProgress() {
    analyzeProgress.value = 0;
    analyzeStage.value = ANALYZE_STAGES[0].label;
    let stageIdx = 0;
    let stageStart = Date.now();
    _progressInterval = setInterval(() => {
        const stage = ANALYZE_STAGES[stageIdx];
        const frac = Math.min((Date.now() - stageStart) / stage.duration, 1);
        const prev = stageIdx > 0 ? ANALYZE_STAGES[stageIdx - 1].target : 0;
        analyzeProgress.value = Math.round(prev + frac * (stage.target - prev));
        if (frac >= 1 && stageIdx < ANALYZE_STAGES.length - 1) {
            stageIdx++;
            stageStart = Date.now();
            analyzeStage.value = ANALYZE_STAGES[stageIdx].label;
        }
    }, 80);
}

function stopAnalyzeProgress(success = true) {
    clearInterval(_progressInterval);
    if (success) {
        analyzeProgress.value = 100;
        analyzeStage.value = 'Gotowe!';
        setTimeout(() => { analyzeProgress.value = 0; analyzeStage.value = ''; }, 800);
    } else {
        analyzeProgress.value = 0;
        analyzeStage.value = '';
    }
}

// AI suggestions state
const selectedSuggestions = ref({});
const applyingSuggestions = ref(null);

const suggestionLabels = {
    venue_type: 'Typ lokalu', venue_size: 'Wielkość lokalu', kitchen_staff: 'Pracownicy kuchni',
    total_staff: 'Łączna liczba pracowników', years_in_business: 'Lata działania',
    specialty: 'Specjalność', cuisine: 'Kuchnia', price_level: 'Poziom cen',
    delivery: 'Dowozy', delivery_volume: 'Ilość dowozów', platforms: 'Platformy',
    rush_hours: 'Godziny ruchu', customer_profiles: 'Profil klientów',
    serves_chicken: 'Serwuje kurczaka', serving_form: 'Forma kurczaka', chicken_volume: 'Ilość kurczaka',
    own_production: 'Własna produkcja', uses_semi_finished: 'Półprodukty', suppliers: 'Dostawcy',
    decision_maker: 'Kto decyduje', ordering_person: 'Osoba zamawiająca', ordering_frequency: 'Częstotliwość zamówień',
    personality: 'Osobowość', promo_activities: 'Promocja', current_products: 'Co kupują',
    menu_changes: 'Zmiany menu', open_to_tests: 'Otwarty na testy',
};

function formatSuggestionValue(val) {
    if (val === true) return 'Tak';
    if (val === false) return 'Nie';
    if (Array.isArray(val)) return val.join(', ');
    return String(val);
}

function toggleSuggestion(callId, key) {
    if (!selectedSuggestions.value[callId]) selectedSuggestions.value[callId] = {};
    selectedSuggestions.value[callId][key] = !selectedSuggestions.value[callId][key];
}

function selectAllSuggestions(callId, suggestions) {
    if (!selectedSuggestions.value[callId]) selectedSuggestions.value[callId] = {};
    const allSelected = Object.keys(suggestions).every(k => selectedSuggestions.value[callId][k]);
    Object.keys(suggestions).forEach(k => { selectedSuggestions.value[callId][k] = !allSelected; });
}

function applySuggestions(call) {
    const selected = selectedSuggestions.value[call.id] || {};
    const accepted = Object.keys(selected).filter(k => selected[k]);
    if (!accepted.length) return;

    applyingSuggestions.value = call.id;

    fetch(route('ringostat.apply-profile-suggestions', call.id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ accepted }),
    })
    .then(r => r.json().then(data => ({ ok: r.ok, data })))
    .then(({ ok, data }) => {
        if (ok && data.success) {
            syncMessage.value = data.message;
            if (data.applied) {
                data.applied.forEach(k => {
                    if (call.ai_profile_suggestions) delete call.ai_profile_suggestions[k];
                });
                if (call.ai_profile_suggestions && !Object.keys(call.ai_profile_suggestions).length) {
                    call.ai_profile_suggestions = null;
                }
            }
            delete selectedSuggestions.value[call.id];
            setTimeout(() => syncMessage.value = '', 5000);
        }
    })
    .catch(err => { analyzeError.value = err.message; setTimeout(() => analyzeError.value = '', 5000); })
    .finally(() => { applyingSuggestions.value = null; });
}

// Score helpers
function scoreColor(score) {
    const pct = score / 10;
    if (pct >= 0.7) return 'text-emerald-600 dark:text-emerald-400';
    if (pct >= 0.4) return 'text-amber-500 dark:text-amber-400';
    return 'text-red-500 dark:text-red-400';
}
function scoreBarColor(score) {
    const pct = score / 10;
    if (pct >= 0.7) return 'bg-emerald-500';
    if (pct >= 0.4) return 'bg-amber-500';
    return 'bg-red-500';
}
function scoreBgColor(score) {
    const pct = score / 10;
    if (pct >= 0.7) return 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800';
    if (pct >= 0.4) return 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800';
    return 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800';
}
function errorSeverityColor(level) {
    if (level === 0) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
    if (level === 1) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    if (level === 2) return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400';
    return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
}
function errorSeverityLabel(level) {
    return ['Brak', 'Lekki', 'Wyraźny', 'Bardzo silny'][level] || '?';
}
function saleColor(val) {
    if (val === 'TAK') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
    if (val === 'CZĘŚCIOWO') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
}

const scoreLabels = {
    opening: 'Otwarcie', diagnosis: 'Diagnoza klienta', questions: 'Jakość pytań',
    listening: 'Słuchanie', leading: 'Prowadzenie', presentation: 'Prezentacja',
    guiding: 'Naprowadzanie', next_step: 'Następny krok', style: 'Styl handlowca',
};

const typicalErrorLabels = {
    monologue: 'Monolog handlowca', assumptions: 'Założenia o kliencie',
    premature_pitch: 'Oferta przed diagnozą', no_deepening: 'Brak pogłębiania',
    chaos: 'Chaos rozmowy', over_convincing: 'Nadmierne przekonywanie',
    no_next_step: 'Brak następnego kroku',
};

function applyAnalysisData(call, payload) {
    Object.assign(call, {
        ai_summary: payload.ai_summary,
        ai_transcript: payload.ai_transcript,
        ai_customer_mood: payload.ai_customer_mood,
        ai_employee_mood: payload.ai_employee_mood,
        ai_overall_mood: payload.ai_overall_mood,
        ai_recommendations: payload.ai_recommendations,
        ai_analysis: payload.ai_analysis,
        ai_profile_suggestions: payload.ai_profile_suggestions,
        has_ai_analysis: payload.has_ai_analysis ?? true,
    });
}

const POLL_INTERVAL_MS = 4000;
const POLL_MAX_ATTEMPTS = 90; // 90 * 4s = 6 minut max

function pollAnalysisStatus(call, attempt = 0) {
    if (analyzingCallId.value !== call.id) return; // anulowane

    if (attempt >= POLL_MAX_ATTEMPTS) {
        stopAnalyzeProgress(false);
        analyzeError.value = 'Analiza trwa zbyt długo — sprawdź ponownie za chwilę';
        setTimeout(() => analyzeError.value = '', 8000);
        analyzingCallId.value = null;
        return;
    }

    fetch(route('ringostat.analyze-call.status', call.id), {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && !data.pending && data.data) {
            stopAnalyzeProgress(true);
            applyAnalysisData(call, data.data);
            activeTab.value = 'analysis';
            analyzingCallId.value = null;
        } else {
            setTimeout(() => pollAnalysisStatus(call, attempt + 1), POLL_INTERVAL_MS);
        }
    })
    .catch(() => {
        setTimeout(() => pollAnalysisStatus(call, attempt + 1), POLL_INTERVAL_MS);
    });
}

function analyzeWithAi(call) {
    if (analyzingCallId.value) return;
    analyzingCallId.value = call.id;
    analyzeError.value = '';
    startAnalyzeProgress();

    fetch(route('ringostat.analyze-call', call.id), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
    })
    .then(r => r.json().then(data => ({ ok: r.ok, status: r.status, data })))
    .then(({ ok, status, data }) => {
        if (!ok || !data.success) {
            stopAnalyzeProgress(false);
            analyzeError.value = data.message || 'Błąd analizy AI';
            setTimeout(() => analyzeError.value = '', 6000);
            analyzingCallId.value = null;
            return;
        }

        // Analiza już istnieje (cache) — zwrócona od razu
        if (data.data && data.data.has_ai_analysis) {
            stopAnalyzeProgress(true);
            applyAnalysisData(call, data.data);
            activeTab.value = 'analysis';
            analyzingCallId.value = null;
            return;
        }

        // Job zakolejkowany — poll co 4s
        pollAnalysisStatus(call);
    })
    .catch(err => {
        stopAnalyzeProgress(false);
        analyzeError.value = 'Błąd połączenia: ' + err.message;
        setTimeout(() => analyzeError.value = '', 6000);
        analyzingCallId.value = null;
    });
}

function applyFilters() {
    const params = {};
    Object.entries(localFilters.value).forEach(([key, val]) => {
        if (val) params[key] = val;
    });
    router.get(route('ringostat.index'), params, { preserveState: true });
}

function resetFilters() {
    localFilters.value = { date_from: '', date_to: '', user_id: '', call_type: '', disposition: '', search: '' };
    router.get(route('ringostat.index'));
}

// Chip quick-filters (toggle value)
function toggleChip(field, value) {
    localFilters.value[field] = localFilters.value[field] === value ? '' : value;
    applyFilters();
}

function syncCalls() {
    syncing.value = true;
    syncMessage.value = '';
    fetch(route('ringostat.sync-calls'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ hours: 48 }),
    })
    .then(r => r.json())
    .then(data => {
        syncMessage.value = data.message || 'Synchronizacja zakończona';
        if (data.synced > 0) router.reload();
    })
    .catch(err => { syncMessage.value = 'Błąd synchronizacji: ' + err.message; })
    .finally(() => {
        syncing.value = false;
        setTimeout(() => syncMessage.value = '', 5000);
    });
}

// Audio player
function playRecording(call) {
    const url = call.recording_url;
    if (!url) return;

    if (playingCallId.value === call.id && audioRef.value) {
        if (audioRef.value.paused) audioRef.value.play();
        else audioRef.value.pause();
        return;
    }

    if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value = null;
    }

    playingCallId.value = call.id;
    playingCall.value = call;
    audioLoading.value = true;
    audioCurrentTime.value = 0;
    audioDuration.value = 0;

    const audio = new Audio(url);
    audioRef.value = audio;

    audio.addEventListener('loadedmetadata', () => {
        audioDuration.value = audio.duration;
        audioLoading.value = false;
    });
    audio.addEventListener('timeupdate', () => { audioCurrentTime.value = audio.currentTime; });
    audio.addEventListener('ended', () => {
        playingCallId.value = null;
        playingCall.value = null;
        audioRef.value = null;
        audioCurrentTime.value = 0;
        audioDuration.value = 0;
    });
    audio.addEventListener('error', () => {
        audioLoading.value = false;
        playingCallId.value = null;
        playingCall.value = null;
        audioRef.value = null;
        syncMessage.value = 'Błąd odtwarzania nagrania';
        setTimeout(() => syncMessage.value = '', 5000);
    });

    audio.play().catch(() => { audioLoading.value = false; });
}

function stopRecording() {
    if (audioRef.value) { audioRef.value.pause(); audioRef.value = null; }
    playingCallId.value = null;
    playingCall.value = null;
    audioCurrentTime.value = 0;
    audioDuration.value = 0;
}

function seekAudio(e) {
    if (!audioRef.value || !audioDuration.value) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const pct = (e.clientX - rect.left) / rect.width;
    audioRef.value.currentTime = pct * audioDuration.value;
}

function formatAudioTime(s) {
    if (!s || isNaN(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    return `${m}:${sec.toString().padStart(2, '0')}`;
}

const audioProgress = computed(() =>
    audioDuration.value ? (audioCurrentTime.value / audioDuration.value) * 100 : 0
);
const isAudioPaused = computed(() => !audioRef.value || audioRef.value.paused);

onBeforeUnmount(() => { if (audioRef.value) audioRef.value.pause(); });

function formatDuration(seconds) {
    if (!seconds) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

const dispositionColor = (d) => {
    switch ((d || '').toUpperCase()) {
        case 'ANSWERED': case 'CONNECTED': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'NO ANSWER': case 'MISSED': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        case 'ESTABLISHED': case 'REDIRECTED': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
        default: return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }
};
const dispositionLabel = (d) => {
    switch ((d || '').toUpperCase()) {
        case 'ANSWERED': return 'Odebrane';
        case 'CONNECTED': return 'Odebrane (przekierowanie)';
        case 'ESTABLISHED': return 'Nawiązane';
        case 'REDIRECTED': return 'Przekierowane';
        case 'NO ANSWER': case 'MISSED': return 'Nieodebrane';
        case 'BUSY': return 'Zajęte';
        case 'FAILED': return 'Nieudane';
        default: return d;
    }
};
const callTypeIcon = (t) => t === 'out' ? 'phone-outgoing' : 'phone-incoming';
const callTypeLabel = (t) => t === 'out' ? 'Wychodzące' : (t === 'callback' ? 'Callback' : 'Przychodzące');

// === SPLIT-VIEW: grupowanie + keyboard ===

const callsByDay = computed(() => {
    const groups = {};
    for (const call of (props.calls.data || [])) {
        const date = call.call_date?.slice(0, 10) || '—';
        if (!groups[date]) groups[date] = [];
        groups[date].push(call);
    }
    return Object.entries(groups).map(([date, calls]) => ({
        date,
        label: formatDayLabel(date),
        calls,
        missed: calls.filter(c => ['NO ANSWER', 'MISSED'].includes((c.disposition || '').toUpperCase())).length,
        answered: calls.filter(c => ['ANSWERED', 'CONNECTED'].includes((c.disposition || '').toUpperCase())).length,
    }));
});

function formatDayLabel(iso) {
    if (iso === '—') return 'Bez daty';
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
    const toYmd = (x) => x.toISOString().slice(0, 10);

    if (toYmd(d) === toYmd(today)) return 'Dzisiaj · ' + d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long' });
    if (toYmd(d) === toYmd(yesterday)) return 'Wczoraj · ' + d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long' });
    return d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function selectCall(id) {
    selectedCallId.value = id;
    activeTab.value = 'info';
    // Auto-scroll na liście do zaznaczonej karty
    nextTick(() => {
        const el = document.querySelector(`[data-call-item="${id}"]`);
        el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
}

function nextCall() {
    const list = props.calls.data;
    if (!list.length) return;
    const idx = list.findIndex(c => c.id === selectedCallId.value);
    const next = list[Math.min(idx + 1, list.length - 1)];
    if (next) selectCall(next.id);
}

function prevCall() {
    const list = props.calls.data;
    if (!list.length) return;
    const idx = list.findIndex(c => c.id === selectedCallId.value);
    if (idx <= 0) return;
    selectCall(list[idx - 1].id);
}

function handleKeyboard(e) {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (e.key === 'j' || e.key === 'J' || e.key === 'ArrowDown') { e.preventDefault(); nextCall(); }
    else if (e.key === 'k' || e.key === 'K' || e.key === 'ArrowUp') { e.preventDefault(); prevCall(); }
    else if (e.key === ' ' && selectedCall.value?.recording_url) { e.preventDefault(); playRecording(selectedCall.value); }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeyboard);
    // Auto-select first call
    if (!selectedCallId.value && props.calls.data?.length) {
        selectedCallId.value = props.calls.data[0].id;
    }
});
onBeforeUnmount(() => { document.removeEventListener('keydown', handleKeyboard); });

watch(() => props.calls.data, (newData) => {
    if (!newData?.length) { selectedCallId.value = null; return; }
    if (!newData.find(c => c.id === selectedCallId.value)) {
        selectedCallId.value = newData[0].id;
    }
});

// Klient/handlowiec display helpers

function callerNumber(call) {
    return call.call_type === 'out' ? call.destination : call.caller;
}

function clientLabel(call) {
    return call.client?.name || null;
}

// Tytuł wizyty (priorytet nad nazwą klienta gdy numer pasuje do wizyty)
function visitTitle(call) {
    return call.visit?.title || null;
}

// Etykieta główna w UI: tytuł wizyty > nazwa klienta > numer
function primaryLabel(call) {
    return visitTitle(call) || clientLabel(call) || callerNumber(call);
}

function employeeLabel(call) {
    return call.employee_name || call.user?.name || null;
}

function employeeFallback(call) {
    // Gdy brak user_id/employee_name — pokaż numer wewnętrzny
    return call.answered_by_number || call.employee_id || null;
}
</script>

<template>
    <Head title="Play Centrala" />

    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Play Centrala</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ isAdmin ? 'Historia połączeń' : 'Moje połączenia' }}
                    <span class="ml-2 text-xs text-slate-400">· <kbd class="px-1 py-0.5 rounded bg-slate-200 dark:bg-slate-700 font-mono text-[10px]">J/K</kbd> nawigacja · <kbd class="px-1 py-0.5 rounded bg-slate-200 dark:bg-slate-700 font-mono text-[10px]">Spacja</kbd> odsłuchaj</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="analyzeError" class="text-sm text-red-600 dark:text-red-400">{{ analyzeError }}</span>
                <span v-if="syncMessage" class="text-sm text-amber-600 dark:text-amber-400">{{ syncMessage }}</span>
                <button v-if="isAdmin" @click="syncCalls" :disabled="syncing"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium transition disabled:opacity-50">
                    <Icons name="sync" :class="['h-4 w-4', syncing ? 'animate-spin' : '']" />
                    {{ syncing ? 'Synchronizacja...' : 'Synchronizuj' }}
                </button>
            </div>
        </div>

        <!-- Brak konfiguracji -->
        <div v-if="isAdmin && !isConfigured" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-6 text-center">
            <Icons name="phone" class="h-12 w-12 mx-auto text-yellow-500 mb-3" />
            <h3 class="text-lg font-medium text-yellow-800 dark:text-yellow-300">Play Centrala nie jest skonfigurowana</h3>
            <p class="text-yellow-600 dark:text-yellow-400 mt-1">Uzupełnij Client ID i Client Secret w module Play Centrala.</p>
        </div>

        <!-- Hero stats + filtry chipsami -->
        <div class="grid grid-cols-4 gap-3">
            <button @click="toggleChip('disposition', '')"
                class="bg-white dark:bg-slate-800 rounded-xl p-4 border-2 border-slate-200 dark:border-slate-700 hover:border-blue-400 transition text-left">
                <div class="flex items-center gap-2">
                    <Icons name="phone" class="h-4 w-4 text-blue-500" />
                    <span class="text-xs text-slate-500 dark:text-slate-400 uppercase">Dziś</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-white mt-1">{{ stats.total }}</p>
            </button>
            <button @click="toggleChip('disposition', 'answered')"
                :class="['bg-white dark:bg-slate-800 rounded-xl p-4 border-2 transition text-left',
                         localFilters.disposition === 'answered' ? 'border-emerald-500' : 'border-slate-200 dark:border-slate-700 hover:border-emerald-400']">
                <div class="flex items-center gap-2">
                    <Icons name="phone-incoming" class="h-4 w-4 text-emerald-500" />
                    <span class="text-xs text-slate-500 dark:text-slate-400 uppercase">Odebrane</span>
                </div>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.answered }}</p>
            </button>
            <button @click="toggleChip('disposition', 'missed')"
                :class="['bg-white dark:bg-slate-800 rounded-xl p-4 border-2 transition text-left',
                         localFilters.disposition === 'missed' ? 'border-red-500' : 'border-slate-200 dark:border-slate-700 hover:border-red-400']">
                <div class="flex items-center gap-2">
                    <Icons name="phone-missed" class="h-4 w-4 text-red-500" />
                    <span class="text-xs text-slate-500 dark:text-slate-400 uppercase">Nieodebrane</span>
                </div>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ stats.missed }}</p>
            </button>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border-2 border-slate-200 dark:border-slate-700">
                <div class="flex items-center gap-2">
                    <Icons name="clock" class="h-4 w-4 text-purple-500" />
                    <span class="text-xs text-slate-500 dark:text-slate-400 uppercase">Śr. czas</span>
                </div>
                <p class="text-2xl font-bold text-slate-800 dark:text-white mt-1">{{ formatDuration(stats.avg_duration) }}</p>
            </div>
        </div>

        <!-- Filtry: chipsami dla typu + full search + daty -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 flex items-center gap-3 flex-wrap">
            <!-- Typ chipsy -->
            <div class="flex gap-1.5">
                <button @click="toggleChip('call_type', 'in')"
                    :class="['px-3 py-1.5 rounded-full text-xs font-medium transition border',
                             localFilters.call_type === 'in' ? 'bg-emerald-500 text-white border-emerald-500' : 'bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600 hover:border-emerald-400']">
                    <Icons name="phone-incoming" class="h-3 w-3 inline" /> Przychodzące
                </button>
                <button @click="toggleChip('call_type', 'out')"
                    :class="['px-3 py-1.5 rounded-full text-xs font-medium transition border',
                             localFilters.call_type === 'out' ? 'bg-blue-500 text-white border-blue-500' : 'bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600 hover:border-blue-400']">
                    <Icons name="phone-outgoing" class="h-3 w-3 inline" /> Wychodzące
                </button>
            </div>

            <div class="w-px h-6 bg-slate-200 dark:bg-slate-700"></div>

            <!-- Daty -->
            <input v-model="localFilters.date_from" @change="applyFilters" type="date"
                class="rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-xs px-2 py-1.5" />
            <span class="text-xs text-slate-400">—</span>
            <input v-model="localFilters.date_to" @change="applyFilters" type="date"
                class="rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-xs px-2 py-1.5" />

            <!-- User (admin) -->
            <select v-if="isAdmin" v-model="localFilters.user_id" @change="applyFilters"
                class="rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-xs px-2 py-1.5">
                <option value="">Wszyscy pracownicy</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>

            <!-- Search (na prawo) -->
            <div class="flex-1 min-w-[200px] relative">
                <Icons name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                <input v-model="localFilters.search" @keydown.enter="applyFilters" type="text"
                    placeholder="Szukaj numeru lub nazwy klienta..."
                    class="w-full pl-9 pr-3 py-1.5 rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-xs" />
            </div>

            <button v-if="Object.values(localFilters).some(v => v)" @click="resetFilters"
                class="text-xs text-slate-500 hover:text-slate-700 dark:hover:text-white">Wyczyść</button>
        </div>

        <!-- SPLIT-VIEW: lista | szczegóły -->
        <div class="grid grid-cols-12 gap-4 h-[calc(100vh-330px)] min-h-[500px]">
            <!-- LISTA (lewa kolumna) -->
            <div class="col-span-12 lg:col-span-5 xl:col-span-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden flex flex-col">
                <div class="p-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                        {{ calls.total }} {{ calls.total === 1 ? 'połączenie' : 'połączeń' }}
                    </p>
                    <p v-if="calls.last_page > 1" class="text-xs text-slate-400">
                        Strona {{ calls.current_page }} / {{ calls.last_page }}
                    </p>
                </div>

                <div class="flex-1 overflow-y-auto">
                    <div v-for="group in callsByDay" :key="group.date">
                        <div class="sticky top-0 z-10 px-3 py-2 bg-slate-100 dark:bg-slate-900/80 backdrop-blur text-xs font-semibold text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                            <span class="uppercase tracking-wide">{{ group.label }}</span>
                            <span class="text-slate-400 font-normal">
                                <span class="text-emerald-500">{{ group.answered }}✓</span>
                                <span class="ml-1.5 text-red-500" v-if="group.missed">{{ group.missed }}✗</span>
                            </span>
                        </div>

                        <button v-for="call in group.calls" :key="call.id"
                            :data-call-item="call.id"
                            @click="selectCall(call.id)"
                            :class="['w-full text-left px-3 py-3 border-b border-slate-100 dark:border-slate-700 transition-colors',
                                     selectedCallId === call.id
                                         ? 'bg-amber-50 dark:bg-amber-900/20 border-l-4 border-l-amber-500'
                                         : 'hover:bg-slate-50 dark:hover:bg-slate-700/50 border-l-4 border-l-transparent']">
                            <div class="flex items-start gap-2">
                                <!-- Ikona kierunku -->
                                <div :class="['flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center',
                                              call.call_type === 'out' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-emerald-100 dark:bg-emerald-900/30']">
                                    <Icons :name="callTypeIcon(call.call_type)" :class="['h-4 w-4',
                                        call.call_type === 'out' ? 'text-blue-600 dark:text-blue-400' : 'text-emerald-600 dark:text-emerald-400']" />
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white truncate flex items-center gap-1">
                                            <Icons v-if="visitTitle(call)" name="calendar" class="h-3 w-3 text-amber-500 shrink-0" title="Pasuje do wizyty w kalendarzu" />
                                            {{ primaryLabel(call) }}
                                        </p>
                                        <span class="text-xs text-slate-500 dark:text-slate-400 shrink-0 font-mono">
                                            {{ call.call_date ? new Date(call.call_date).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : '' }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span v-if="visitTitle(call) || clientLabel(call)" class="text-xs text-slate-500 dark:text-slate-400 font-mono truncate">
                                            {{ callerNumber(call) }}
                                        </span>
                                        <span v-else class="text-xs italic text-slate-400">Nieznany numer</span>
                                    </div>

                                    <div class="flex items-center gap-2 mt-1">
                                        <span :class="[dispositionColor(call.disposition), 'px-1.5 py-0.5 rounded text-[10px] font-medium']">
                                            {{ dispositionLabel(call.disposition) }}
                                        </span>
                                        <span v-if="call.billsec || call.duration" class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ formatDuration(call.billsec || call.duration) }}
                                        </span>
                                        <span v-if="isAdmin" class="text-xs text-slate-500 dark:text-slate-400 truncate ml-auto">
                                            {{ employeeLabel(call) || (employeeFallback(call) ? '📞 ' + employeeFallback(call) : '—') }}
                                        </span>
                                        <Icons v-if="call.has_recording" name="play" class="h-3 w-3 text-amber-500" title="Nagranie dostępne" />
                                        <Icons v-if="call.has_ai_analysis" name="sparkles" class="h-3 w-3 text-purple-500" title="Analiza AI" />
                                    </div>
                                </div>
                            </div>
                        </button>
                    </div>

                    <div v-if="!calls.data?.length" class="p-8 text-center text-slate-400">
                        <Icons name="phone" class="h-10 w-10 mx-auto mb-2 opacity-30" />
                        <p class="text-sm">Brak połączeń</p>
                    </div>
                </div>

                <!-- Paginacja (kompaktowa) -->
                <div v-if="calls.last_page > 1" class="p-2 border-t border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1">
                    <Link v-for="link in calls.links" :key="link.label"
                        :href="link.url || '#'" v-html="link.label"
                        :class="[
                            'px-2 py-1 rounded text-xs transition',
                            link.active ? 'bg-amber-500 text-white' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700',
                            !link.url ? 'opacity-50 pointer-events-none' : ''
                        ]"
                    />
                </div>
            </div>

            <!-- SZCZEGÓŁY (prawa kolumna) -->
            <div class="col-span-12 lg:col-span-7 xl:col-span-8 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden flex flex-col">
                <!-- Placeholder gdy nic nie wybrane -->
                <div v-if="!selectedCall" class="flex-1 flex items-center justify-center p-8">
                    <div class="text-center text-slate-400">
                        <Icons name="phone" class="h-16 w-16 mx-auto mb-3 opacity-20" />
                        <p class="text-sm">Wybierz rozmowę z listy</p>
                        <p class="text-xs mt-1">lub użyj strzałek / J–K do nawigacji</p>
                    </div>
                </div>

                <template v-else>
                    <!-- Header szczegółów -->
                    <div class="p-5 border-b border-slate-200 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="flex items-start gap-3 min-w-0">
                                <div :class="['flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center',
                                              selectedCall.call_type === 'out' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-emerald-100 dark:bg-emerald-900/30']">
                                    <Icons :name="callTypeIcon(selectedCall.call_type)" :class="['h-6 w-6',
                                        selectedCall.call_type === 'out' ? 'text-blue-600 dark:text-blue-400' : 'text-emerald-600 dark:text-emerald-400']" />
                                </div>
                                <div>
                                    <!-- Priorytet: wizyta > klient > numer -->
                                    <div v-if="selectedCall.visit" class="flex items-center gap-2 mb-1 flex-wrap">
                                        <Link :href="route('calendar.index') + '?openVisit=' + selectedCall.visit.id"
                                            class="text-lg font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                                            <Icons name="calendar" class="h-4 w-4" />
                                            {{ selectedCall.visit.title }}
                                        </Link>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                                            Wizyta w kalendarzu
                                        </span>
                                        <Link v-if="selectedCall.client" :href="route('clients.show', selectedCall.client.id)"
                                            class="text-xs text-slate-500 dark:text-slate-400 hover:underline">
                                            → {{ selectedCall.client.name }}
                                        </Link>
                                    </div>
                                    <div v-else-if="selectedCall.client" class="flex items-center gap-2 mb-1">
                                        <Link :href="route('clients.show', selectedCall.client.id)"
                                            class="text-lg font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400">
                                            {{ selectedCall.client.name }}
                                        </Link>
                                    </div>
                                    <div v-else class="flex items-center gap-2 mb-1">
                                        <span class="text-lg font-bold text-slate-800 dark:text-white">{{ callerNumber(selectedCall) }}</span>
                                        <Link :href="route('clients.create')"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 hover:bg-emerald-200 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 transition">
                                            <Icons name="plus" class="h-3 w-3" /> Dodaj klienta
                                        </Link>
                                    </div>

                                    <div class="flex items-center gap-3 text-sm text-slate-500 dark:text-slate-400 flex-wrap">
                                        <span class="font-mono">{{ callerNumber(selectedCall) }}</span>
                                        <span v-if="selectedCall.call_date">{{ new Date(selectedCall.call_date).toLocaleString('pl-PL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</span>
                                        <span>{{ callTypeLabel(selectedCall.call_type) }}</span>
                                        <span v-if="selectedCall.billsec || selectedCall.duration">· {{ formatDuration(selectedCall.billsec || selectedCall.duration) }}</span>
                                    </div>

                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <span :class="[dispositionColor(selectedCall.disposition), 'px-2 py-0.5 rounded-full text-xs font-medium']">
                                            {{ dispositionLabel(selectedCall.disposition) }}
                                        </span>
                                        <span v-if="isAdmin && employeeLabel(selectedCall)"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                                            <Icons name="user" class="h-3 w-3" /> {{ employeeLabel(selectedCall) }}
                                        </span>
                                        <span v-else-if="isAdmin && employeeFallback(selectedCall)"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400"
                                            title="Numer wewnętrzny — nie zmapowany do żadnego użytkownika CRM">
                                            <Icons name="phone" class="h-3 w-3" /> {{ employeeFallback(selectedCall) }} (nie zmapowany)
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Action buttons -->
                            <div class="flex gap-2 shrink-0">
                                <button v-if="selectedCall.recording_url" @click="playRecording(selectedCall)"
                                    class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition"
                                    :class="playingCallId === selectedCall.id
                                        ? 'bg-amber-500 text-white'
                                        : 'bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/30 dark:text-amber-400'">
                                    <Icons :name="audioLoading && playingCallId === selectedCall.id ? 'sync' : (!isAudioPaused && playingCallId === selectedCall.id ? 'pause' : 'play')"
                                        :class="['h-4 w-4', audioLoading && playingCallId === selectedCall.id ? 'animate-spin' : '']" />
                                    {{ !isAudioPaused && playingCallId === selectedCall.id ? 'Pauza' : 'Odsłuchaj' }}
                                </button>
                                <button v-if="selectedCall.recording_url && !selectedCall.has_ai_analysis"
                                    @click="analyzeWithAi(selectedCall)" :disabled="analyzingCallId === selectedCall.id"
                                    class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium bg-violet-100 hover:bg-violet-200 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400 transition disabled:opacity-50">
                                    <Icons :name="analyzingCallId === selectedCall.id ? 'sync' : 'sparkles'"
                                        :class="['h-4 w-4', analyzingCallId === selectedCall.id ? 'animate-spin' : '']" />
                                    {{ analyzingCallId === selectedCall.id ? 'Analizuję...' : 'Analiza AI' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs (tylko gdy jest AI analiza) -->
                    <div v-if="selectedCall.has_ai_analysis" class="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50">
                        <nav class="flex px-5 gap-0 overflow-x-auto">
                            <button v-for="(label, tab) in { info: 'Info', analysis: 'Analiza AI', errors_tab: 'Typowe błędy', transcript: 'Transkrypcja', profile: 'Profil klienta' }" :key="tab"
                                @click="activeTab = tab"
                                :class="['px-4 py-2.5 text-xs font-semibold uppercase tracking-wide border-b-2 transition-colors whitespace-nowrap',
                                    activeTab === tab
                                        ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                        : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200']">
                                {{ label }}
                            </button>
                        </nav>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 overflow-y-auto p-5">
                        <!-- === INFO tab (zawsze widoczny jeśli nie wybrano innego) === -->
                        <div v-if="activeTab === 'info' || !selectedCall.has_ai_analysis" class="space-y-4">
                            <div v-if="!selectedCall.has_ai_analysis && selectedCall.recording_url" class="p-4 rounded-lg bg-violet-50 dark:bg-violet-900/10 border border-violet-200 dark:border-violet-800">
                                <p class="text-sm text-violet-700 dark:text-violet-300">
                                    <Icons name="sparkles" class="h-4 w-4 inline" />
                                    Ta rozmowa nie została jeszcze przeanalizowana AI — kliknij <strong>Analiza AI</strong>.
                                </p>
                            </div>

                            <!-- Quick summary if already analyzed -->
                            <div v-if="selectedCall.ai_summary" class="p-4 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700">
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Podsumowanie AI</p>
                                <p class="text-sm text-slate-700 dark:text-slate-300">{{ selectedCall.ai_summary }}</p>
                            </div>

                            <!-- Ostrzeżenie o niepełnej analizie -->
                            <div v-if="selectedCall.ai_summary && !selectedCall.ai_analysis?.scores && selectedCall.recording_url"
                                 class="p-4 rounded-lg bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800 flex items-start gap-3">
                                <Icons name="sparkles" class="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
                                <div class="flex-1">
                                    <p class="text-sm text-amber-800 dark:text-amber-300 font-medium">Niepełna analiza</p>
                                    <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">Ta rozmowa ma tylko podsumowanie — brak transkrypcji, ocen etapów i listy błędów. Uruchom analizę ponownie żeby uzyskać pełen raport.</p>
                                    <button @click="analyzeWithAi(selectedCall)" :disabled="analyzingCallId === selectedCall.id"
                                        class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-500 hover:bg-amber-600 text-white transition disabled:opacity-50">
                                        <Icons :name="analyzingCallId === selectedCall.id ? 'sync' : 'sparkles'"
                                            :class="['h-3.5 w-3.5', analyzingCallId === selectedCall.id ? 'animate-spin' : '']" />
                                        {{ analyzingCallId === selectedCall.id ? 'Analizuję...' : 'Analizuj ponownie' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Basic metadata -->
                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-900/50">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Numer dzwoniącego</p>
                                    <p class="text-sm font-mono font-semibold text-slate-800 dark:text-white">{{ selectedCall.caller || '—' }}</p>
                                </div>
                                <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-900/50">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Numer docelowy</p>
                                    <p class="text-sm font-mono font-semibold text-slate-800 dark:text-white">{{ selectedCall.destination || '—' }}</p>
                                </div>
                                <div v-if="selectedCall.answered_by_number" class="p-3 rounded-lg bg-slate-50 dark:bg-slate-900/50">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Odebrał (wew.)</p>
                                    <p class="text-sm font-mono font-semibold text-slate-800 dark:text-white">{{ selectedCall.answered_by_number }}</p>
                                </div>
                                <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-900/50">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Data i godzina</p>
                                    <p class="text-sm font-semibold text-slate-800 dark:text-white">
                                        {{ selectedCall.call_date ? new Date(selectedCall.call_date).toLocaleString('pl-PL') : '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- === ANALIZA AI tab === -->
                        <div v-else-if="activeTab === 'analysis'" class="space-y-5">
                            <!-- Overall score -->
                            <div v-if="selectedCall.ai_analysis?.scores?.overall" class="flex items-center gap-5 p-4 rounded-lg border-2" :class="scoreBgColor(selectedCall.ai_analysis.scores.overall)">
                                <div class="flex-shrink-0 text-center">
                                    <div :class="['relative w-20 h-20 rounded-full border-4 flex items-center justify-center', scoreBgColor(selectedCall.ai_analysis.scores.overall)]">
                                        <span :class="['text-3xl font-bold', scoreColor(selectedCall.ai_analysis.scores.overall)]">{{ selectedCall.ai_analysis.scores.overall }}</span>
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">/ 10</p>
                                </div>
                                <div class="flex-1 space-y-2">
                                    <div v-if="selectedCall.ai_analysis?.advanced_sale">
                                        <span :class="[saleColor(selectedCall.ai_analysis.advanced_sale), 'inline-block px-2 py-0.5 rounded-full text-xs font-bold']">
                                            {{ selectedCall.ai_analysis.advanced_sale === 'TAK' ? '✓ Przybliżyła sprzedaż' : selectedCall.ai_analysis.advanced_sale === 'CZĘŚCIOWO' ? '~ Częściowo' : '✗ Nie przybliżyła' }}
                                        </span>
                                    </div>
                                    <div class="flex gap-3 flex-wrap text-sm">
                                        <span v-if="selectedCall.ai_customer_mood">
                                            <span class="text-slate-500 dark:text-slate-400">Klient:</span>
                                            <span class="ml-1 font-semibold text-blue-700 dark:text-blue-400">{{ selectedCall.ai_customer_mood }}</span>
                                        </span>
                                        <span v-if="selectedCall.ai_employee_mood">
                                            <span class="text-slate-500 dark:text-slate-400">Pracownik:</span>
                                            <span class="ml-1 font-semibold text-emerald-700 dark:text-emerald-400">{{ selectedCall.ai_employee_mood }}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Stage scores -->
                            <div v-if="selectedCall.ai_analysis?.scores" class="p-4 rounded-lg bg-slate-50 dark:bg-slate-900/50 space-y-1.5">
                                <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase mb-2">Oceny etapów rozmowy</h4>
                                <template v-for="(label, key) in scoreLabels" :key="key">
                                    <div v-if="selectedCall.ai_analysis.scores[key] != null" class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600 dark:text-slate-400 w-32 text-right truncate">{{ label }}</span>
                                        <div class="flex-1 h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                            <div :class="[scoreBarColor(selectedCall.ai_analysis.scores[key]), 'h-full rounded-full transition-all']"
                                                :style="{ width: ((selectedCall.ai_analysis.scores[key] || 0) / 10 * 100) + '%' }"></div>
                                        </div>
                                        <span :class="['text-xs font-bold w-6 text-right', scoreColor(selectedCall.ai_analysis.scores[key])]">{{ selectedCall.ai_analysis.scores[key] }}</span>
                                    </div>
                                </template>
                            </div>

                            <!-- Good / Errors / Improvements -->
                            <div v-if="selectedCall.ai_analysis?.good_aspects?.length">
                                <h4 class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase mb-2">✓ Co zrobił dobrze</h4>
                                <ul class="space-y-1.5">
                                    <li v-for="(good, i) in selectedCall.ai_analysis.good_aspects" :key="i" class="text-sm text-slate-700 dark:text-slate-300 pl-4 relative">
                                        <span class="absolute left-0 top-2 w-1.5 h-1.5 rounded-full bg-emerald-500"></span>{{ good }}
                                    </li>
                                </ul>
                            </div>
                            <div v-if="selectedCall.ai_analysis?.errors?.length">
                                <h4 class="text-xs font-semibold text-red-600 dark:text-red-400 uppercase mb-2">✗ Błędy</h4>
                                <ul class="space-y-1.5">
                                    <li v-for="(err, i) in selectedCall.ai_analysis.errors" :key="i" class="text-sm text-slate-700 dark:text-slate-300 pl-4 relative">
                                        <span class="absolute left-0 top-2 w-1.5 h-1.5 rounded-full bg-red-500"></span>{{ err }}
                                    </li>
                                </ul>
                            </div>
                            <div v-if="selectedCall.ai_analysis?.improvements?.length">
                                <h4 class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase mb-2">↗ Do poprawy</h4>
                                <ul class="space-y-1.5">
                                    <li v-for="(imp, i) in selectedCall.ai_analysis.improvements" :key="i" class="text-sm text-slate-700 dark:text-slate-300 pl-4 relative">
                                        <span class="absolute left-0 top-2 w-1.5 h-1.5 rounded-full bg-amber-500"></span>{{ imp }}
                                    </li>
                                </ul>
                            </div>
                            <div v-if="selectedCall.ai_analysis?.next_steps?.length">
                                <h4 class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase mb-2">→ Kolejne kroki</h4>
                                <ol class="space-y-2">
                                    <li v-for="(step, i) in selectedCall.ai_analysis.next_steps" :key="i" class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
                                        <span class="flex-shrink-0 w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs font-bold">{{ i + 1 }}</span>
                                        {{ step }}
                                    </li>
                                </ol>
                            </div>
                            <div v-if="selectedCall.ai_analysis?.crm_notes">
                                <h4 class="text-xs font-semibold text-violet-600 dark:text-violet-400 uppercase mb-2">📋 Do CRM</h4>
                                <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line bg-violet-50 dark:bg-violet-900/10 border border-violet-200 dark:border-violet-800 rounded-lg p-3">{{ selectedCall.ai_analysis.crm_notes }}</p>
                            </div>
                        </div>

                        <!-- === TYPOWE BŁĘDY tab === -->
                        <div v-else-if="activeTab === 'errors_tab'" class="space-y-4">
                            <div v-if="selectedCall.ai_analysis?.typical_errors" class="space-y-3">
                                <template v-for="(label, key) in typicalErrorLabels" :key="key">
                                    <div v-if="selectedCall.ai_analysis.typical_errors[key] != null" class="flex items-center gap-3">
                                        <span class="text-sm text-slate-600 dark:text-slate-300 w-44 text-right">{{ label }}</span>
                                        <div class="flex gap-1">
                                            <div v-for="level in [0,1,2,3]" :key="level"
                                                :class="['w-8 h-6 rounded flex items-center justify-center text-xs font-bold',
                                                    level <= selectedCall.ai_analysis.typical_errors[key]
                                                        ? errorSeverityColor(selectedCall.ai_analysis.typical_errors[key])
                                                        : 'bg-slate-100 text-slate-300 dark:bg-slate-800 dark:text-slate-600']">{{ level }}</div>
                                        </div>
                                        <span :class="[errorSeverityColor(selectedCall.ai_analysis.typical_errors[key]), 'px-2 py-0.5 rounded text-xs font-medium']">
                                            {{ errorSeverityLabel(selectedCall.ai_analysis.typical_errors[key]) }}
                                        </span>
                                    </div>
                                </template>
                                <div v-if="selectedCall.ai_analysis.typical_errors.biggest_problem" class="p-4 rounded-lg bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 mt-4">
                                    <h4 class="text-xs font-semibold text-red-700 dark:text-red-400 uppercase mb-1">Największy problem</h4>
                                    <p class="text-sm text-red-800 dark:text-red-300 font-medium">{{ selectedCall.ai_analysis.typical_errors.biggest_problem }}</p>
                                </div>
                                <div v-if="selectedCall.ai_analysis.typical_errors.one_thing_to_improve" class="p-4 rounded-lg bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800">
                                    <h4 class="text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase mb-1">Najważniejsza poprawa</h4>
                                    <p class="text-sm text-amber-800 dark:text-amber-300 font-medium">{{ selectedCall.ai_analysis.typical_errors.one_thing_to_improve }}</p>
                                </div>
                            </div>
                            <p v-else class="text-center py-8 text-slate-400 text-sm">Brak danych o typowych błędach — uruchom analizę AI.</p>
                        </div>

                        <!-- === TRANSKRYPCJA tab === -->
                        <div v-else-if="activeTab === 'transcript'" class="space-y-2">
                            <div v-if="selectedCall.ai_transcript">
                                <div v-for="(line, i) in selectedCall.ai_transcript.split('\n').filter(l => l.trim())" :key="i"
                                    :class="['py-2 px-3 rounded-lg mb-1 text-sm',
                                        line.startsWith('Pracownik:') ? 'bg-blue-50 dark:bg-blue-900/20 ml-0 mr-12'
                                        : line.startsWith('Klient:') ? 'bg-amber-50 dark:bg-amber-900/20 ml-12 mr-0'
                                        : 'bg-slate-50 dark:bg-slate-800']">
                                    <span v-if="line.startsWith('Pracownik:')" class="text-xs font-bold text-blue-600 dark:text-blue-400">Pracownik</span>
                                    <span v-else-if="line.startsWith('Klient:')" class="text-xs font-bold text-amber-600 dark:text-amber-400">Klient</span>
                                    <p class="text-slate-700 dark:text-slate-300 mt-1">{{ line.replace(/^(Pracownik:|Klient:)\s*/, '') }}</p>
                                </div>
                            </div>
                            <p v-else class="text-center py-8 text-slate-400 text-sm">Brak transkrypcji.</p>
                        </div>

                        <!-- === PROFIL KLIENTA tab === -->
                        <div v-else-if="activeTab === 'profile'">
                            <div v-if="selectedCall.ai_profile_suggestions && Object.keys(selectedCall.ai_profile_suggestions).length" class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 uppercase">Sugestie do profilu klienta</p>
                                    <div class="flex gap-2">
                                        <button @click="selectAllSuggestions(selectedCall.id, selectedCall.ai_profile_suggestions)"
                                            class="text-xs text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">Zaznacz wszystkie</button>
                                        <button @click="applySuggestions(selectedCall)"
                                            :disabled="applyingSuggestions === selectedCall.id || !selectedSuggestions[selectedCall.id] || !Object.values(selectedSuggestions[selectedCall.id] || {}).some(v => v)"
                                            class="inline-flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-medium bg-emerald-500 hover:bg-emerald-600 text-white transition disabled:opacity-40">
                                            <Icons name="check" class="h-3 w-3" />
                                            {{ applyingSuggestions === selectedCall.id ? 'Zapisuję...' : 'Zastosuj wybrane' }}
                                        </button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                    <label v-for="(val, key) in selectedCall.ai_profile_suggestions" :key="key"
                                        class="flex items-start gap-2 p-2.5 rounded-lg cursor-pointer border transition"
                                        :class="selectedSuggestions[selectedCall.id]?.[key]
                                            ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-300 dark:border-emerald-700'
                                            : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 hover:border-emerald-300'">
                                        <input type="checkbox" :checked="selectedSuggestions[selectedCall.id]?.[key]" @change="toggleSuggestion(selectedCall.id, key)"
                                            class="mt-0.5 rounded border-slate-300 text-emerald-500 focus:ring-emerald-500" />
                                        <div class="min-w-0">
                                            <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ suggestionLabels[key] || key }}</span>
                                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200 truncate">{{ formatSuggestionValue(val) }}</p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <p v-else class="text-center py-8 text-slate-400 text-sm">
                                {{ selectedCall.client ? 'Brak sugestii profilu z tej rozmowy.' : 'Sugestie pojawią się gdy rozmowa zostanie przypisana do klienta.' }}
                            </p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Floating: audio player -->
    <Transition enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0" enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-4 opacity-0">
        <div v-if="playingCallId" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-[480px] max-w-[calc(100vw-2rem)] bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="flex items-center justify-between px-4 pt-3 pb-1">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                        <Icons name="phone" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white truncate">
                            {{ playingCall?.client?.name || playingCall?.caller || playingCall?.destination || 'Nagranie' }}
                        </p>
                        <p class="text-xs text-slate-400 truncate">
                            {{ playingCall?.employee_name || '' }}
                            <span v-if="playingCall?.call_date"> · {{ new Date(playingCall.call_date).toLocaleDateString('pl-PL') }}</span>
                        </p>
                    </div>
                </div>
                <button @click="stopRecording" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-400">
                    <Icons name="x-mark" class="w-4 h-4" />
                </button>
            </div>
            <div class="px-4 pb-3 space-y-2">
                <div class="w-full h-2 bg-slate-100 dark:bg-slate-700 rounded-full cursor-pointer group relative" @click="seekAudio">
                    <div class="h-2 bg-amber-500 rounded-full transition-all duration-100 relative" :style="{ width: audioProgress + '%' }">
                        <div class="absolute right-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-amber-500 rounded-full shadow opacity-0 group-hover:opacity-100 transition" />
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="playRecording(playingCall)" class="w-9 h-9 rounded-full bg-amber-500 hover:bg-amber-600 text-white flex items-center justify-center transition shrink-0">
                        <Icons v-if="audioLoading" name="sync" class="w-4 h-4 animate-spin" />
                        <Icons v-else-if="isAudioPaused" name="play" class="w-4 h-4" />
                        <Icons v-else name="pause" class="w-4 h-4" />
                    </button>
                    <span class="text-xs font-mono text-slate-500 dark:text-slate-400 shrink-0">
                        {{ formatAudioTime(audioCurrentTime) }} / {{ formatAudioTime(audioDuration) }}
                    </span>
                </div>
            </div>
        </div>
    </Transition>

    <!-- Floating: pasek postępu analizy -->
    <Transition enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0" enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-4 opacity-0">
        <div v-if="analyzingCallId" class="fixed bottom-6 right-6 z-50 w-80 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-violet-200 dark:border-violet-800 overflow-hidden">
            <div class="flex items-center gap-2.5 px-4 py-3 bg-violet-50 dark:bg-violet-950/40 border-b border-violet-200 dark:border-violet-800">
                <Icons name="sparkles" class="w-4 h-4 text-violet-500 animate-pulse shrink-0" />
                <span class="text-sm font-semibold text-violet-700 dark:text-violet-300 flex-1 truncate">{{ analyzeStage || 'Analiza AI…' }}</span>
                <span class="text-xs font-mono text-violet-500 dark:text-violet-400 shrink-0">{{ analyzeProgress }}%</span>
            </div>
            <div class="px-4 py-3 space-y-2">
                <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full transition-all duration-200"
                        :class="analyzeProgress === 100 ? 'bg-emerald-500' : 'bg-violet-500'"
                        :style="{ width: analyzeProgress + '%' }" />
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500">Analiza trwa zwykle 30–90s w zależności od długości rozmowy</p>
            </div>
        </div>
    </Transition>
</template>
