<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import Icons from '@/Components/Icons.vue';

function gamesPath(path = '') {
    return '/games' + (path ? '/' + path : '');
}

function ensureHttps(url) {
    return typeof url === 'string' && url.startsWith('http://') ? url.replace('http://', 'https://') : url;
}

const page = usePage();
const isDeveloper = computed(() => page.props.auth?.user?.is_developer ?? false);

const show = ref(false);
const activeGame = ref(null);

const seq = 'bojarchuj'.split('');
let index = 0;
let resetTimeout = null;
const resetDelay = 2000;

/** Gry z Inertia shared props (bez API – unika 403) */
const games = computed(() => {
    const list = page.props.games ?? [];
    const mapped = list.length ? list.map(g => ({ ...g, url: ensureHttps(g.url) })) : [{ id: 'coming', name: 'Wkrótce', icon: 'plus' }];
    return mapped;
});

const uploadForm = ref({ file: null });
const fileInputRef = ref(null);
const uploading = ref(false);
const uploadError = ref(null);

const leaderboard = ref([]);
const leaderboardLoading = ref(false);

const gameSettings = ref({});
const gameSettingsLoading = ref(false);
const gameSettingsSaving = ref(false);
const gameSettingsHeadFile = ref(null);
const gameSettingsHeadInputRef = ref(null);

async function uploadGame() {
    if (!uploadForm.value.file) return;
    uploading.value = true;
    uploadError.value = null;
    try {
        const fd = new FormData();
        fd.append('game_file', uploadForm.value.file);
        await axios.post(gamesPath(), fd, { headers: { 'Accept': 'application/json' } });
        uploadForm.value = { file: null };
        fileInputRef.value && (fileInputRef.value.value = '');
        router.reload();
    } catch (e) {
        uploadError.value = e.response?.data?.message ?? 'Błąd instalacji';
    } finally {
        uploading.value = false;
    }
}

async function removeGame(game) {
    if (!confirm(`Usunąć grę „${game.name}"?`)) return;
    try {
        await axios.delete(gamesPath(game.id));
        if (activeGame.value?.id === game.id) activeGame.value = null;
        router.reload();
    } catch (e) {
        alert(e.response?.data?.message ?? 'Błąd usuwania');
    }
}

async function loadLeaderboard(gameId) {
    if (!gameId) return;
    leaderboardLoading.value = true;
    try {
        const { data } = await axios.get(gamesPath(gameId + '/leaderboard'));
        leaderboard.value = data ?? [];
    } catch {
        leaderboard.value = [];
    } finally {
        leaderboardLoading.value = false;
    }
}

async function loadGameSettings(gameId) {
    if (!gameId) return;
    gameSettingsLoading.value = true;
    try {
        const { data } = await axios.get(gamesPath(gameId + '/settings'));
        gameSettings.value = data ?? {};
    } catch {
        gameSettings.value = {};
    } finally {
        gameSettingsLoading.value = false;
    }
}

async function saveGameSettings(gameId) {
    if (!gameId) return;
    gameSettingsSaving.value = true;
    try {
        await axios.put(gamesPath(gameId + '/settings'), { settings: gameSettings.value });
        await loadGameSettings(gameId);
        gameSettingsHeadFile.value = null;
        gameSettingsHeadInputRef.value && (gameSettingsHeadInputRef.value.value = '');
        notifyGameSettingsUpdated(gameId);
    } catch (e) {
        alert(e.response?.data?.message ?? 'Błąd zapisu');
    } finally {
        gameSettingsSaving.value = false;
    }
}

function onHeadFileChange(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    const r = new FileReader();
    r.onload = () => {
        gameSettings.value = { ...gameSettings.value, head_image: r.result };
    };
    r.readAsDataURL(file);
}

function clearHeadImage() {
    gameSettings.value = { ...gameSettings.value, head_image: null };
}

watch(activeGame, (g) => {
    if (g?.id && g.id !== 'coming') {
        loadLeaderboard(g.id);
        if (g.id === 'snake') loadGameSettings(g.id);
        else gameSettings.value = {};
    }
});

const gameIframeRef = ref(null);

function onMessage(e) {
    if (e?.data?.type === 'gameScoreSubmitted' && e.data?.gameId) {
        loadLeaderboard(e.data.gameId);
    }
}

function notifyGameSettingsUpdated(gameId) {
    try {
        const iframe = gameIframeRef.value;
        if (iframe?.contentWindow) {
            iframe.contentWindow.postMessage({ type: 'gameSettingsUpdated', gameId }, '*');
        }
    } catch (_) {}
}

function checkSequence(key) {
    if (key == null || key === '') return;
    const char = String(key).toLowerCase();
    if (char === seq[index]) {
        index++;
        clearTimeout(resetTimeout);
        resetTimeout = setTimeout(() => { index = 0; }, resetDelay);
        if (index === seq.length) {
            index = 0;
            show.value = true;
        }
    } else {
        index = 0;
    }
}

function handleKeydown(e) {
    if (show.value && e.key === 'Escape') {
        show.value = false;
        activeGame.value = null;
        e.preventDefault();
        return;
    }
    if (!show.value) {
        checkSequence(e.key);
    }
}

function closePanel() {
    show.value = false;
    activeGame.value = null;
}

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('message', onMessage);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('message', onMessage);
    clearTimeout(resetTimeout);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm"
            @click.self="closePanel"
        >
            <div
                class="relative w-full max-w-5xl max-h-[95vh] mx-4 bg-slate-900 rounded-xl shadow-2xl border border-slate-700/80 overflow-hidden"
                @click.stop
            >
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700/80">
                    <h2 class="text-lg font-semibold text-slate-200 tracking-tight">Minigry</h2>
                    <button
                        type="button"
                        class="p-2 rounded-lg hover:bg-slate-800 text-slate-500 hover:text-slate-300 transition-colors"
                        @click="closePanel"
                    >
                        <Icons name="close" class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-6 overflow-y-auto max-h-[calc(95vh-80px)]">
                    <div v-if="isDeveloper" class="mb-6 p-4 rounded-lg bg-slate-800/40 border border-slate-700/60">
                        <p class="text-sm text-slate-500 mb-3">Dodaj grę z pliku ZIP (game.json + index.html w archiwum)</p>
                        <div class="flex flex-wrap items-center gap-3">
                            <input
                                ref="fileInputRef"
                                type="file"
                                accept=".zip"
                                class="text-sm text-slate-400 file:mr-3 file:py-2 file:px-3 file:rounded file:border file:border-slate-600 file:bg-slate-800 file:text-slate-300 file:cursor-pointer hover:file:bg-slate-700 file:text-xs"
                                @change="uploadForm.file = $event.target.files?.[0]"
                            />
                            <button
                                type="button"
                                :disabled="!uploadForm.file || uploading"
                                class="px-3 py-2 rounded bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
                                @click="uploadGame"
                            >
                                {{ uploading ? 'Instalowanie…' : 'Zainstaluj' }}
                            </button>
                        </div>
                        <p v-if="uploadError" class="mt-2 text-sm text-red-500">{{ uploadError }}</p>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div
                            v-for="game in games"
                            :key="game.id"
                            class="relative group/card"
                        >
                            <button
                                type="button"
                                class="flex flex-col items-center gap-3 p-5 rounded-lg bg-slate-800/60 hover:bg-slate-700/80 border border-slate-700/80 hover:border-slate-600 transition-all duration-150 w-full"
                                @click="activeGame = game"
                            >
                                <div class="w-12 h-12 rounded-lg bg-slate-700/80 flex items-center justify-center">
                                    <Icons :name="(game.icon === 'game' ? 'puzzle' : game.icon) || 'plus'" class="h-6 w-6 text-slate-400" />
                                </div>
                                <span class="text-sm font-medium text-slate-400">{{ game.name }}</span>
                            </button>
                            <button
                                v-if="isDeveloper && game.url && game.id !== 'snake'"
                                type="button"
                                class="absolute top-2 right-2 p-1.5 rounded bg-slate-700 text-slate-500 opacity-0 group-hover/card:opacity-100 hover:text-red-400 hover:bg-slate-600 transition-all"
                                title="Usuń grę"
                                @click.stop="removeGame(game)"
                            >
                                <Icons name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div v-if="activeGame?.url" class="mt-6 space-y-4">
                        <div v-if="isDeveloper && activeGame.id === 'snake'" class="rounded-lg bg-slate-800/40 border border-slate-700/60 p-4">
                            <h3 class="text-sm font-medium text-slate-400 mb-3">Ustawienia gry</h3>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Głowa węża (zdjęcie)</label>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input
                                            ref="gameSettingsHeadInputRef"
                                            type="file"
                                            accept="image/*"
                                            class="text-sm text-slate-400 file:mr-2 file:py-1.5 file:px-2 file:rounded file:border file:border-slate-600 file:bg-slate-800 file:text-slate-300 file:text-xs file:cursor-pointer"
                                            @change="onHeadFileChange"
                                        />
                                        <button
                                            type="button"
                                            class="px-3 py-1.5 rounded bg-slate-700 text-slate-300 hover:bg-slate-600 text-xs font-medium disabled:opacity-50"
                                            :disabled="gameSettingsSaving"
                                            @click="saveGameSettings(activeGame.id)"
                                        >
                                            {{ gameSettingsSaving ? 'Zapisywanie…' : 'Zapisz' }}
                                        </button>
                                        <button
                                            v-if="gameSettings.head_image"
                                            type="button"
                                            class="px-3 py-1.5 rounded bg-slate-700/50 text-slate-500 hover:bg-slate-600 text-xs"
                                            @click="clearHeadImage(); saveGameSettings(activeGame.id)"
                                        >
                                            Usuń
                                        </button>
                                    </div>
                                    <img v-if="gameSettings.head_image" :src="gameSettings.head_image" alt="Głowa" class="mt-2 w-10 h-10 rounded object-cover border border-slate-600" />
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg overflow-hidden bg-slate-950 border border-slate-800">
                            <iframe ref="gameIframeRef" :src="activeGame.url" class="w-full h-full min-h-[560px]" title="Gra" />
                        </div>
                        <div class="rounded-lg bg-slate-800/40 border border-slate-700/60 p-4">
                            <h3 class="text-sm font-medium text-slate-400 mb-3">Ranking</h3>
                            <div v-if="leaderboardLoading" class="text-sm text-slate-500">Ładowanie…</div>
                            <div v-else-if="!leaderboard.length" class="text-sm text-slate-500">Brak wyników. Zagraj i zapisz swój wynik.</div>
                            <ol v-else class="space-y-2">
                                <li
                                    v-for="(entry, i) in leaderboard"
                                    :key="i"
                                    class="flex items-center gap-3 text-sm py-1.5 border-b border-slate-700/50 last:border-0"
                                >
                                    <span class="w-5 text-xs font-medium text-slate-500">{{ entry.rank }}.</span>
                                    <img
                                        v-if="entry.avatar_url"
                                        :src="entry.avatar_url"
                                        :alt="entry.user_name"
                                        class="w-6 h-6 rounded-full object-cover"
                                    />
                                    <span v-else class="w-6 h-6 rounded-full bg-slate-700 flex items-center justify-center text-xs text-slate-500 font-medium">{{ entry.user_name?.slice(0, 1) }}</span>
                                    <span class="flex-1 text-slate-400">{{ entry.user_name }}</span>
                                    <span class="font-medium text-slate-300">{{ activeGame?.id === 'reaction' ? (10000 - entry.score) + ' ms' : entry.score }}</span>
                                </li>
                            </ol>
                        </div>
                    </div>
                    <div v-else-if="activeGame?.id === 'coming'" class="mt-8 p-8 text-center rounded-lg bg-slate-800/40 border border-slate-700/60">
                        <p class="text-slate-500">Mini-gry w przygotowaniu.</p>
                        <p class="text-sm text-slate-600 mt-1">Będziesz mógł tu grać wkrótce.</p>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
