<script setup>
import { Head } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    priceLists: {
        type: Array,
        default: () => [],
    },
});

function formatDate(dateStr) {
    if (!dateStr) return null;
    try {
        return new Date(dateStr).toLocaleDateString('pl-PL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    } catch {
        return null;
    }
}

function publicUrl(slug) {
    return '/cennik/' + slug;
}
</script>

<template>
    <Head title="Cenniki" />

    <div class="max-w-3xl mx-auto space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-foreground">Cenniki</h1>
            <p class="mt-1 text-foreground-muted">
                Dostępne cenniki produktów
            </p>
        </div>

        <div
            v-if="priceLists.length === 0"
            class="rounded-xl border border-border surface p-8 text-center text-foreground-muted"
        >
            Brak dostępnych cenników.
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2">
            <a
                v-for="pl in priceLists"
                :key="pl.id"
                :href="publicUrl(pl.slug)"
                target="_blank"
                rel="noopener"
                class="group relative flex flex-col rounded-xl border border-border surface p-6 hover:border-amber-400 dark:hover:border-amber-500 transition-colors"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/40 text-brand-primary">
                            <Icons name="price-list" class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="font-semibold text-foreground group-hover:text-brand-primary dark:group-hover:text-amber-400 transition-colors">
                                {{ pl.name }}
                            </h2>
                            <p v-if="pl.description" class="text-sm text-foreground-muted mt-0.5">
                                {{ pl.description }}
                            </p>
                        </div>
                    </div>
                    <Icons name="external-link" class="w-4 h-4 text-slate-400 group-hover:text-amber-500 flex-shrink-0 mt-1" />
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-foreground-muted">
                    <span v-if="pl.is_public" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">
                        <Icons name="eye" class="w-3 h-3" />
                        Publiczny
                    </span>
                    <span v-if="pl.sync_from_fakturownia" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">
                        <Icons name="refresh" class="w-3 h-3" />
                        Synchronizowany
                    </span>
                    <span v-if="pl.last_synced_at" class="ml-auto">
                        Synchron. {{ formatDate(pl.last_synced_at) }}
                    </span>
                </div>
            </a>
        </div>
    </div>
</template>
