<script setup>
import { Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

defineProps({
    data: { type: Object, default: () => ({ count: 0, recent: [] }) },
});
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="flex-1 p-4 overflow-y-auto">
            <div v-if="!data?.count" class="text-center py-8 text-foreground-subtle">
                <Icons name="document-text" class="w-10 h-10 mx-auto mb-3 opacity-50" />
                <p class="text-sm">Brak aktywności dzisiaj.</p>
            </div>
            <template v-else>
                <div class="mb-4 flex items-baseline justify-between">
                    <p class="text-3xl font-bold gradient-brand-text">{{ data.count }}</p>
                    <p class="text-xs text-foreground-muted uppercase tracking-wider">akcji dziś</p>
                </div>
                <ul class="space-y-2">
                    <li v-for="entry in data.recent" :key="entry.id"
                        class="flex items-center gap-3 p-2 rounded-md surface-elevated">
                        <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: entry.color }"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-foreground truncate">{{ entry.label }}</p>
                            <p v-if="entry.description" class="text-xs text-foreground-muted truncate">{{ entry.description }}</p>
                        </div>
                        <span class="text-xs text-foreground-muted font-mono shrink-0">{{ entry.time }}</span>
                    </li>
                </ul>
            </template>
        </div>
        <div class="px-4 py-2 border-t border-border bg-surface-2">
            <Link :href="route('dailyreport.index')" class="text-xs text-brand-primary hover:underline font-medium">
                Pełny raport dzienny →
            </Link>
        </div>
    </div>
</template>
