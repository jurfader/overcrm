<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import ClientModal from '@/Pages/Calendar/ClientModal.vue';

const props = defineProps({
    showSearch: { type: Boolean, default: false },
});

const emit = defineEmits(['update:showSearch']);

const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const selectedIndex = ref(0);
const searchInputRef = ref(null);

// Wiele wizyt: { id, context, minimized }
const openVisits = ref([]);
const maximizedVisitId = ref(null);
const isLoadingVisit = ref(false);
const panelWidth = ref(420);
const panelGap = 8;
const maxOpenPanels = 5;

let searchDebounce = null;

function getVisitTitle(ctx) {
    const v = ctx?.visit;
    if (!v) return '';
    return v.client?.name || v.title || `Wizyta #${v.id}`;
}

function getVisitDateStr(ctx) {
    const v = ctx?.visit;
    if (!v?.visit_date) return '';
    const d = typeof v.visit_date === 'string' ? v.visit_date : v.visit_date?.split?.('T')?.[0];
    if (!d) return '';
    const [y, m, day] = d.split('-');
    const months = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
    return `${parseInt(day)} ${months[parseInt(m) - 1]} ${y}`;
}

const expandedVisits = computed(() => openVisits.value.filter(v => !v.minimized));
const minimizedVisits = computed(() => openVisits.value.filter(v => v.minimized));
const maximizedVisit = computed(() => {
    if (!maximizedVisitId.value) return null;
    return expandedVisits.value.find(v => v.id === maximizedVisitId.value);
});
const visibleSidebarPanels = computed(() =>
    expandedVisits.value.filter(v => v.id !== maximizedVisitId.value)
);

async function fetchVisits() {
    const q = searchQuery.value.trim();
    searchLoading.value = true;
    try {
        const url = route('calendar.visits-search') + '?q=' + encodeURIComponent(q) + '&limit=15';
        const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await r.json();
        searchResults.value = data.visits || [];
        selectedIndex.value = 0;
    } catch {
        searchResults.value = [];
    } finally {
        searchLoading.value = false;
    }
}

function onSearchInput() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => fetchVisits(), 200);
}

async function selectVisit(visit, startMinimized = false) {
    emit('update:showSearch', false);
    searchQuery.value = '';
    searchResults.value = [];

    const existing = openVisits.value.find(v => v.id === visit.id);
    if (existing) {
        existing.minimized = startMinimized ? true : false;
        openVisits.value = [...openVisits.value.filter(v => v.id !== visit.id), existing];
        // Zawsze pobierz świeże dane – unikamy „niezapisanych zmian” przy ponownym wyborze
        try {
            localStorage.removeItem(`visit-draft-${visit.id}`);
        } catch (_) {}
        isLoadingVisit.value = true;
        try {
            const url = route('calendar.visit-context', visit.id);
            const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await r.json();
            if (!data.error && data.visit) {
                data._refreshKey = Date.now();
                existing.context = data;
            }
        } catch (e) {
            console.error('Failed to refresh visit:', e);
        } finally {
            isLoadingVisit.value = false;
        }
        return;
    }

    if (openVisits.value.length >= maxOpenPanels) {
        openVisits.value = openVisits.value.slice(1);
    }

    isLoadingVisit.value = true;
    try {
        const url = route('calendar.visit-context', visit.id);
        const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await r.json();
        if (data.error) throw new Error(data.error);
        openVisits.value = [...openVisits.value, { id: visit.id, context: data, minimized: startMinimized }];
    } catch (e) {
        console.error('Failed to load visit:', e);
    } finally {
        isLoadingVisit.value = false;
    }
}

const clientModalRefs = {};
function setModalRef(id, el) {
    if (el) clientModalRefs[id] = el;
    else delete clientModalRefs[id];
}

async function closeVisit(item) {
    const modal = clientModalRefs[item.id];
    if (modal?.silentSave) await modal.silentSave();
    if (maximizedVisitId.value === item.id) {
        maximizedVisitId.value = null;
    }
    openVisits.value = openVisits.value.filter(v => v.id !== item.id);
    router.reload({ preserveScroll: true });
}

async function toggleMinimize(item) {
    if (!item.minimized) {
        const modal = clientModalRefs[item.id];
        if (modal?.silentSave) await modal.silentSave();
        router.reload({ preserveScroll: true });
    }
    item.minimized = !item.minimized;
}

function expandFromSidebar(item) {
    item.minimized = false;
}

function toggleMaximize(item) {
    maximizedVisitId.value = maximizedVisitId.value === item.id ? null : item.id;
}

function restoreFromMaximize() {
    maximizedVisitId.value = null;
}

function onRefresh(item) {
    if (!item?.context?.visit?.id) return;
    router.reload({ preserveScroll: true });
}

function handleKeydown(e) {
    if (e.key === 'Escape') {
        if (maximizedVisitId.value) {
            restoreFromMaximize();
            e.preventDefault();
            return;
        }
        if (props.showSearch) {
            emit('update:showSearch', false);
            e.preventDefault();
        }
        return;
    }
    if (!props.showSearch) return;
    if (e.key === 'ArrowDown') {
        selectedIndex.value = Math.min(selectedIndex.value + 1, searchResults.value.length - 1);
        e.preventDefault();
        return;
    }
    if (e.key === 'ArrowUp') {
        selectedIndex.value = Math.max(selectedIndex.value - 1, 0);
        e.preventDefault();
        return;
    }
    if (e.key === 'Enter' && searchResults.value[selectedIndex.value]) {
        selectVisit(searchResults.value[selectedIndex.value]);
        e.preventDefault();
    }
}

watch(() => props.showSearch, (v) => {
    if (v) {
        searchQuery.value = '';
        searchResults.value = [];
        fetchVisits();
        nextTick(() => searchInputRef.value?.focus());
    }
});

watch(searchQuery, () => onSearchInput());

function handleOpenVisitEvent(e) {
    const visit = e.detail?.visit;
    const minimized = e.detail?.minimized ?? false;
    if (visit?.id) selectVisit(visit, minimized);
}

function handleEscape(e) {
    if (e.key === 'Escape' && maximizedVisitId.value) {
        restoreFromMaximize();
        e.preventDefault();
    }
}

watch(maximizedVisitId, (id) => {
    if (id) {
        window.addEventListener('keydown', handleEscape);
    } else {
        window.removeEventListener('keydown', handleEscape);
    }
}, { immediate: true });

onMounted(() => {
    window.addEventListener('open-visit-floating', handleOpenVisitEvent);
});

onUnmounted(() => {
    window.removeEventListener('open-visit-floating', handleOpenVisitEvent);
    window.removeEventListener('keydown', handleEscape);
});
</script>

<template>
    <!-- Wyszukiwarka (overlay) -->
    <Teleport to="body">
        <div
            v-if="showSearch"
            class="fixed inset-0 z-[90] flex items-start justify-center pt-[15vh]"
            @keydown="handleKeydown"
        >
            <div class="fixed inset-0 bg-black/50 dark:bg-black/60" @click="emit('update:showSearch', false)"></div>
            <div
                class="relative w-full max-w-xl mx-4 bg-white dark:bg-slate-800 rounded-xl shadow-2xl overflow-hidden border border-slate-200 dark:border-slate-700"
                @click.stop
            >
                <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700">
                    <Icons name="search" class="h-5 w-5 text-slate-400 shrink-0" />
                    <input
                        ref="searchInputRef"
                        v-model="searchQuery"
                        type="text"
                        placeholder="Szukaj wizyt (klient, tytuł, NIP)"
                        class="flex-1 bg-transparent border-0 focus:ring-0 text-slate-900 dark:text-slate-100 placeholder-slate-400"
                    />
                    <kbd class="hidden sm:inline px-2 py-0.5 text-xs font-mono bg-slate-100 dark:bg-slate-700 rounded">Esc</kbd>
                </div>
                <div class="max-h-72 overflow-y-auto">
                    <div v-if="searchLoading" class="px-4 py-8 text-center text-slate-500">Ładowanie…</div>
                    <div v-else-if="searchResults.length === 0" class="px-4 py-8 text-center text-slate-500">
                        {{ searchQuery.length >= 2 ? 'Brak wyników' : 'Wpisz min. 2 znaki' }}
                    </div>
                    <button
                        v-for="(v, i) in searchResults"
                        :key="v.id"
                        type="button"
                        :class="[
                            'w-full flex items-center gap-3 px-4 py-3 text-left transition-colors',
                            i === selectedIndex
                                ? 'bg-amber-500/20 dark:bg-amber-500/30'
                                : 'hover:bg-slate-50 dark:hover:bg-slate-700/50',
                        ]"
                        @click="selectVisit(v)"
                    >
                        <div
                            class="w-2 h-2 rounded-full shrink-0"
                            :style="{ backgroundColor: v.status?.color || v.color || '#3B82F6' }"
                        ></div>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-slate-900 dark:text-slate-100 truncate">
                                {{ v.client?.name || v.title || 'Wizyta' }}
                            </div>
                            <div class="text-xs text-slate-500">
                                {{ v.visit_date }} {{ v.visit_time ? v.visit_time : '' }}
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Loading overlay przy ładowaniu wizyty -->
    <Teleport to="body">
        <div
            v-if="isLoadingVisit"
            class="fixed inset-0 z-[85] flex items-center justify-center bg-black/30 dark:bg-black/40"
        >
            <div class="flex items-center gap-3 px-6 py-4 bg-white dark:bg-slate-800 rounded-xl shadow-xl">
                <Icons name="spinner" class="h-6 w-6 animate-spin text-amber-500" />
                <span class="text-slate-700 dark:text-slate-300">Ładowanie wizyty…</span>
            </div>
        </div>
    </Teleport>

    <!-- Maksymalizowany panel – pełny ekran -->
    <Teleport to="body">
        <div
            v-if="maximizedVisit"
            class="fixed inset-0 z-[90] flex items-center justify-center p-4 bg-black/50 dark:bg-black/60"
            @click.self="restoreFromMaximize"
        >
            <div
                class="flex flex-col w-full max-w-4xl max-h-[90vh] bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden"
                @click.stop
            >
                <div class="flex items-center justify-between px-4 py-2 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 shrink-0">
                    <div class="flex items-center gap-2 min-w-0">
                        <div
                            class="w-2 h-2 rounded-full shrink-0"
                            :style="{ backgroundColor: maximizedVisit.context.visit?.status?.color || maximizedVisit.context.visit?.color || '#3B82F6' }"
                        ></div>
                        <span class="font-semibold text-slate-900 dark:text-slate-100 truncate">{{ getVisitTitle(maximizedVisit.context) }}</span>
                        <span class="text-xs text-slate-500 shrink-0">{{ getVisitDateStr(maximizedVisit.context) }}</span>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button
                            type="button"
                            class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                            title="Przywróć rozmiar"
                            @click="restoreFromMaximize"
                        >
                            <Icons name="arrows-in" class="h-5 w-5" />
                        </button>
                        <button
                            type="button"
                            class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                            title="Zamknij"
                            @click="closeVisit(maximizedVisit)"
                        >
                            <Icons name="close" class="h-5 w-5" />
                        </button>
                    </div>
                </div>
                <div class="flex-1 min-h-0 overflow-hidden flex flex-col">
                    <div class="flex-1 min-h-0 overflow-auto">
                        <ClientModal
                            :ref="el => setModalRef(maximizedVisit.id, el)"
                            :key="maximizedVisit.id + '-' + (maximizedVisit.context._refreshKey || 0)"
                            v-if="maximizedVisit.context.visit"
                            :visit="maximizedVisit.context.visit"
                            :clients="maximizedVisit.context.clients || []"
                            :users="maximizedVisit.context.users || []"
                            :email-templates="maximizedVisit.context.emailTemplates || []"
                            :mail-configs="maximizedVisit.context.mailConfigs || []"
                            :price-lists="maximizedVisit.context.priceLists || []"
                            :statuses="maximizedVisit.context.statuses || []"
                            :profile-options="maximizedVisit.context.profileOptions || {}"
                            :floating-mode="true"
                            @close="closeVisit(maximizedVisit)"
                            @refresh="onRefresh(maximizedVisit)"
                        />
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Panele wizyt (floating) – wiele obok siebie (ukryj gdy maksymalizowany) -->
    <div
        v-for="(item, idx) in visibleSidebarPanels"
        :key="item.id"
        class="fixed top-4 bottom-4 z-[85] flex flex-col bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden"
        :style="{
            width: panelWidth + 'px',
            maxWidth: '95vw',
            right: (16 + (visibleSidebarPanels.length - 1 - idx) * (panelWidth + panelGap)) + 'px',
        }"
    >
        <div class="flex items-center justify-between px-4 py-2 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 shrink-0">
            <div class="flex items-center gap-2 min-w-0">
                <div
                    class="w-2 h-2 rounded-full shrink-0"
                    :style="{ backgroundColor: item.context.visit?.status?.color || item.context.visit?.color || '#3B82F6' }"
                ></div>
                <span class="font-semibold text-slate-900 dark:text-slate-100 truncate">{{ getVisitTitle(item.context) }}</span>
                <span class="text-xs text-slate-500 shrink-0">{{ getVisitDateStr(item.context) }}</span>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button
                    type="button"
                    class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                    :title="maximizedVisitId === item.id ? 'Przywróć rozmiar' : 'Powiększ'"
                    @click="toggleMaximize(item)"
                >
                    <Icons :name="maximizedVisitId === item.id ? 'arrows-in' : 'arrows-out'" class="h-5 w-5" />
                </button>
                <button
                    type="button"
                    class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                    title="Minimalizuj do sidebara"
                    @click="toggleMinimize(item)"
                >
                    <Icons name="chevron-right" class="h-5 w-5" />
                </button>
                <button
                    type="button"
                    class="p-2 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400"
                    title="Zamknij"
                    @click="closeVisit(item)"
                >
                    <Icons name="close" class="h-5 w-5" />
                </button>
            </div>
        </div>
        <div class="flex-1 min-h-0 overflow-hidden flex flex-col">
            <div class="flex-1 min-h-0 overflow-hidden h-full">
                <ClientModal
                :ref="el => setModalRef(item.id, el)"
                :key="item.id + '-' + (item.context._refreshKey || 0)"
                v-if="item.context.visit"
                :visit="item.context.visit"
                :clients="item.context.clients || []"
                :users="item.context.users || []"
                :email-templates="item.context.emailTemplates || []"
                :mail-configs="item.context.mailConfigs || []"
                :price-lists="item.context.priceLists || []"
                :statuses="item.context.statuses || []"
                :profile-options="item.context.profileOptions || {}"
                :floating-mode="true"
                @close="closeVisit(item)"
                @refresh="onRefresh(item)"
            />
            </div>
        </div>
    </div>

    <!-- Zminimalizowane – teleport do sidebara (desktop) -->
    <Teleport to="#sidebar-minimized-visits">
        <template v-if="minimizedVisits.length > 0">
            <button
                v-for="item in minimizedVisits"
                :key="item.id"
                type="button"
                class="w-full flex items-center gap-x-3 rounded-md p-2 text-sm font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors group shrink-0"
                :title="'Maksymalizuj: ' + getVisitTitle(item.context)"
                @click="expandFromSidebar(item)"
            >
                <Icons name="calendar" class="h-5 w-5 shrink-0 text-amber-500" />
                <span class="flex-1 min-w-0 truncate text-left">{{ getVisitTitle(item.context) }}</span>
                <Icons name="chevron-left" class="h-4 w-4 shrink-0 text-slate-500 group-hover:text-white" title="Maksymalizuj" />
            </button>
        </template>
    </Teleport>

    <!-- Zminimalizowane – mobile -->
    <Teleport to="#mobile-minimized-visits">
        <template v-if="minimizedVisits.length > 0">
            <button
                v-for="item in minimizedVisits"
                :key="'m-' + item.id"
                type="button"
                class="w-full flex items-center gap-x-3 rounded-lg p-3 text-sm font-semibold bg-slate-800 text-slate-200 hover:bg-slate-700 transition-colors"
                :title="'Maksymalizuj: ' + getVisitTitle(item.context)"
                @click="expandFromSidebar(item)"
            >
                <Icons name="calendar" class="h-5 w-5 shrink-0 text-amber-500" />
                <span class="flex-1 min-w-0 truncate text-left">{{ getVisitTitle(item.context) }}</span>
                <Icons name="chevron-left" class="h-4 w-4 shrink-0" title="Maksymalizuj" />
            </button>
        </template>
    </Teleport>
</template>
