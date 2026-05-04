<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    regions: Object,
});

const selectedRegion = ref('');
const selectedCity = ref('');
const sources = ref(['pyszne', 'openstreetmap']);
const limit = ref(200);
const searching = ref(false);
const results = ref([]);
const searchStats = ref(null);
const selectedResults = ref(new Set());
const importing = ref(false);

const cities = computed(() => {
    if (!selectedRegion.value) return [];
    return props.regions[selectedRegion.value]?.cities || [];
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function search() {
    if (!selectedRegion.value && !selectedCity.value) {
        alert('Wybierz województwo lub miasto');
        return;
    }
    searching.value = true;
    results.value = [];
    selectedResults.value = new Set();
    searchStats.value = null;

    try {
        const body = {
            sources: sources.value,
            limit: limit.value,
        };
        if (selectedCity.value) {
            body.city = selectedCity.value;
        } else {
            body.voivodeship = selectedRegion.value;
        }
        const res = await fetch(route('leads.search.run'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success) {
            results.value = data.results;
            searchStats.value = {
                scraped: data.total_scraped,
                unique: data.total_unique,
                scored: data.total_scored,
                cities: data.cities_searched || [],
                debug: data.debug || [],
            };
        } else {
            alert(data.error || 'Błąd wyszukiwania');
        }
    } catch (e) {
        alert('Błąd połączenia: ' + e.message);
    } finally {
        searching.value = false;
    }
}

function toggleSelect(index) {
    if (selectedResults.value.has(index)) {
        selectedResults.value.delete(index);
    } else {
        selectedResults.value.add(index);
    }
    selectedResults.value = new Set(selectedResults.value); // trigger reactivity
}

function selectAllGood() {
    results.value.forEach((r, i) => {
        if ((r.ai_score ?? 0) >= 6 && !r.is_existing_client) {
            selectedResults.value.add(i);
        }
    });
    selectedResults.value = new Set(selectedResults.value);
}

function deselectAll() {
    selectedResults.value = new Set();
}

async function importSelected() {
    const leads = [...selectedResults.value].map(i => results.value[i]).filter(Boolean);
    if (leads.length === 0) {
        alert('Zaznacz przynajmniej jednego leada');
        return;
    }
    importing.value = true;
    try {
        const res = await fetch(route('leads.search.import'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: JSON.stringify({ leads }),
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            selectedResults.value = new Set();
        } else {
            alert(data.error || 'Błąd importu');
        }
    } catch (e) {
        alert('Błąd: ' + e.message);
    } finally {
        importing.value = false;
    }
}

function sourceTagLabel(source) {
    const map = { pyszne: 'Pyszne.pl', openstreetmap: 'OSM', google_maps: 'Google', glovo: 'Glovo', ubereats: 'Uber Eats', wolt: 'Wolt' };
    return map[source] || source;
}

function sourceTagClass(source) {
    const map = {
        pyszne: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        google_maps: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        openstreetmap: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400',
        glovo: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        ubereats: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        wolt: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400',
    };
    return map[source] || 'bg-slate-100 text-slate-700';
}

function scoreColor(score) {
    if (score >= 8) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
    if (score >= 6) return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
    if (score >= 4) return 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300';
    return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
}
</script>

<template>
    <Head title="Szukaj leadów" />

    <div class="max-w-6xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Szukaj leadów</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Scraping Pyszne.pl + Google Maps z oceną AI</p>
            </div>
            <Link :href="route('leads.index')" class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                Wróć do Kanban
            </Link>
        </div>

        <!-- Search Form -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Województwo -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Województwo</label>
                    <select
                        v-model="selectedRegion"
                        class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm px-3 py-2"
                        @change="selectedCity = ''"
                    >
                        <option value="">— Wybierz —</option>
                        <option v-for="(region, key) in regions" :key="key" :value="key">{{ region.name }}</option>
                    </select>
                </div>

                <!-- Miasto -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miasto <span class="font-normal text-slate-400">(opcjonalne)</span></label>
                    <select v-model="selectedCity" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm px-3 py-2" :disabled="!selectedRegion">
                        <option value="">— Cale województwo —</option>
                        <option v-for="city in cities" :key="city" :value="city">{{ city }}</option>
                    </select>
                    <p v-if="selectedRegion && !selectedCity" class="text-xs text-amber-600 dark:text-amber-400 mt-1">Przeszuka wszystkie miasta w województwie</p>
                </div>

                <!-- Przycisk -->
                <div class="flex items-end">
                    <button
                        @click="search"
                        :disabled="searching || (!selectedCity && !selectedRegion)"
                        class="w-full py-2.5 bg-amber-500 text-white rounded-lg font-medium hover:bg-amber-600 disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
                    >
                        <Icons v-if="!searching" name="search" class="w-4 h-4" />
                        <span v-else class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                        {{ searching ? 'Szukam...' : 'Szukaj' }}
                    </button>
                </div>
            </div>

            <!-- Źródła -->
            <div class="flex flex-wrap items-center gap-4 mt-4">
                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Źródła:</span>
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input type="checkbox" value="pyszne" v-model="sources" class="rounded border-slate-300 text-amber-500" />
                    Pyszne.pl
                </label>
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input type="checkbox" value="openstreetmap" v-model="sources" class="rounded border-slate-300 text-amber-500" />
                    OpenStreetMap
                </label>
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input type="checkbox" value="google_maps" v-model="sources" class="rounded border-slate-300 text-amber-500" />
                    Google
                </label>
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input type="checkbox" value="delivery" v-model="sources" class="rounded border-slate-300 text-amber-500" />
                    Glovo/Uber/Wolt
                </label>
            </div>

            <!-- Stats -->
            <div v-if="searchStats" class="mt-4 flex flex-wrap gap-6 text-sm text-slate-500 dark:text-slate-400">
                <span>Znaleziono: {{ searchStats.scraped }}</span>
                <span>Unikalne: {{ searchStats.unique }}</span>
                <span>Po ocenie: {{ searchStats.scored }}</span>
                <span v-if="searchStats.cities">Miasta: {{ searchStats.cities.join(', ') }}</span>
            </div>
            <div v-if="searchStats?.debug?.length" class="mt-2 text-xs text-slate-400 dark:text-slate-500 font-mono space-y-0.5">
                <div v-for="(line, i) in searchStats.debug" :key="i" :class="line.includes('BŁĄD') ? 'text-red-400' : ''">{{ line }}</div>
            </div>
        </div>

        <!-- Results -->
        <div v-if="results.length > 0" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <!-- Actions bar -->
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-3 bg-slate-50 dark:bg-slate-900/50">
                <div class="flex items-center gap-3">
                    <button @click="selectAllGood" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400">
                        Zaznacz score 6+
                    </button>
                    <button @click="deselectAll" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">
                        Odznacz wszystko
                    </button>
                    <span class="text-sm text-slate-400">Zaznaczono: {{ selectedResults.size }}</span>
                </div>
                <button
                    @click="importSelected"
                    :disabled="importing || selectedResults.size === 0"
                    class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 disabled:opacity-50 transition-colors flex items-center gap-2"
                >
                    <Icons name="plus" class="w-4 h-4" />
                    {{ importing ? 'Importuję...' : `Importuj (${selectedResults.size})` }}
                </button>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 dark:text-slate-400 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30">
                            <th class="px-4 py-3 w-10"></th>
                            <th class="px-4 py-3 w-14">Score</th>
                            <th class="px-4 py-3">Nazwa</th>
                            <th class="px-4 py-3">Miasto</th>
                            <th class="px-4 py-3">Źródło</th>
                            <th class="px-4 py-3">Ocena AI</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr
                            v-for="(r, i) in results"
                            :key="i"
                            :class="[
                                r.is_existing_client ? 'bg-yellow-50/50 dark:bg-yellow-900/10' : '',
                                selectedResults.has(i) ? 'bg-blue-50 dark:bg-blue-900/20' : '',
                            ]"
                            class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors"
                        >
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    :checked="selectedResults.has(i)"
                                    :disabled="r.is_existing_client"
                                    class="rounded border-slate-300 text-amber-500"
                                    @change="toggleSelect(i)"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <span :class="scoreColor(r.ai_score ?? 0)" class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold">
                                    {{ r.ai_score ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900 dark:text-white">{{ r.name }}</div>
                                <div v-if="r.address" class="text-xs text-slate-500 dark:text-slate-400 truncate max-w-xs">{{ r.address }}</div>
                                <div class="flex flex-wrap gap-3 mt-1">
                                    <span v-if="r.phone" class="text-xs text-blue-600 dark:text-blue-400">{{ r.phone }}</span>
                                    <span v-if="r.email" class="text-xs text-blue-600 dark:text-blue-400">{{ r.email }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ r.city }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded" :class="sourceTagClass(r.source)">
                                    {{ sourceTagLabel(r.source) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300 max-w-xs">{{ r.ai_reason }}</td>
                            <td class="px-4 py-3">
                                <span v-if="r.is_existing_client" class="text-xs px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">
                                    Już klient
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="!searching && searchStats" class="text-center py-12 text-slate-500 dark:text-slate-400">
            Brak wyników dla tego miasta.
        </div>
    </div>
</template>
