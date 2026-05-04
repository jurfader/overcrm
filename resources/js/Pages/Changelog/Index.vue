<script setup>
import { Head } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    entries: {
        type: Array,
        default: () => [],
    },
});

function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('pl-PL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    } catch {
        return dateStr;
    }
}
</script>

<template>
    <Head title="Changelog" />

    <div class="max-w-3xl mx-auto space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Changelog</h1>
            <p class="mt-1 text-slate-600 dark:text-slate-400">
                Historia zmian wprowadzonych na produkcję
            </p>
        </div>

        <div v-if="entries.length === 0" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-8 text-center text-slate-500 dark:text-slate-400">
            Brak wpisów w changelogu.
        </div>

        <div v-else class="space-y-8">
            <article
                v-for="(entry, idx) in entries"
                :key="idx"
                class="relative rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden"
            >
                <!-- Linia łącząca -->
                <div
                    v-if="idx < entries.length - 1"
                    class="absolute left-6 top-16 bottom-0 w-px bg-slate-200 dark:bg-slate-600"
                />
                <div class="relative p-6">
                    <div class="flex flex-wrap items-center gap-3 mb-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-sm font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400">
                            v{{ entry.version }}
                        </span>
                        <span class="text-sm text-slate-500 dark:text-slate-400">
                            {{ formatDate(entry.date) }}
                        </span>
                    </div>

                    <div class="space-y-4">
                        <div v-if="entry.added?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                                <Icons name="plus" class="w-4 h-4" />
                                Wprowadzone
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-slate-700 dark:text-slate-300">
                                <li v-for="(item, i) in entry.added" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                        <div v-if="entry.fixed?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                                <Icons name="check-circle" class="w-4 h-4" />
                                Naprawione
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-slate-700 dark:text-slate-300">
                                <li v-for="(item, i) in entry.fixed" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                        <div v-if="entry.removed?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-red-600 dark:text-red-400 flex items-center gap-2">
                                <Icons name="trash" class="w-4 h-4" />
                                Usunięte
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-slate-700 dark:text-slate-300">
                                <li v-for="(item, i) in entry.removed" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <p class="text-sm text-slate-500 dark:text-slate-400 text-center pt-4">
            Przy każdym deployu na produkcję dodawany jest nowy wpis z datą, wersją i opisem zmian.
        </p>
    </div>
</template>
