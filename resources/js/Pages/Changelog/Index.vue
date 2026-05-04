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
            <h1 class="text-2xl font-bold gradient-brand-text">Changelog</h1>
            <p class="text-foreground-muted text-sm mt-1">
                Historia zmian wprowadzonych na produkcję
            </p>
        </div>

        <div v-if="entries.length === 0" class="glass-card p-8 text-center text-foreground-muted">
            Brak wpisów w changelogu.
        </div>

        <div v-else class="space-y-8">
            <article
                v-for="(entry, idx) in entries"
                :key="idx"
                class="relative glass-card overflow-hidden"
            >
                <!-- Linia łącząca -->
                <div
                    v-if="idx < entries.length - 1"
                    class="absolute left-6 top-16 bottom-0 w-px bg-border"
                />
                <div class="relative p-6">
                    <div class="flex flex-wrap items-center gap-3 mb-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-sm font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400">
                            v{{ entry.version }}
                        </span>
                        <span class="text-sm text-foreground-muted">
                            {{ formatDate(entry.date) }}
                        </span>
                    </div>

                    <div class="space-y-4">
                        <div v-if="entry.added?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center gap-2">
                                <Icons name="plus" class="w-4 h-4" />
                                Wprowadzone
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-foreground">
                                <li v-for="(item, i) in entry.added" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                        <div v-if="entry.fixed?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center gap-2">
                                <Icons name="check-circle" class="w-4 h-4" />
                                Naprawione
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-foreground">
                                <li v-for="(item, i) in entry.fixed" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                        <div v-if="entry.removed?.length" class="space-y-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-destructive flex items-center gap-2">
                                <Icons name="trash" class="w-4 h-4" />
                                Usunięte
                            </h3>
                            <ul class="list-disc list-inside space-y-1 text-foreground">
                                <li v-for="(item, i) in entry.removed" :key="i">{{ item }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <p class="text-sm text-foreground-muted text-center pt-4">
            Przy każdym deployu na produkcję dodawany jest nowy wpis z datą, wersją i opisem zmian.
        </p>
    </div>
</template>
