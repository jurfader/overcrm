<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    data: { type: Array, default: () => [] },
});

function formatDate(d) {
    return new Date(d).toLocaleDateString('pl-PL');
}
function untilLabel(days) {
    if (days === 0) return 'dziś';
    if (days === 1) return 'za 1 dzień';
    return `za ${days} dni`;
}
</script>

<template>
    <div class="p-4 h-full overflow-y-auto">
        <div v-if="!data?.length" class="text-center py-8 text-foreground-subtle">
            <p class="text-sm">Brak nadchodzących urodzin lokali.</p>
        </div>
        <ul v-else class="space-y-2">
            <li v-for="item in data" :key="item.id"
                class="flex items-center justify-between gap-3 p-3 rounded-md gradient-subtle hover:bg-surface-elevated transition-colors">
                <Link :href="route('clients.show', item.id)" class="text-sm font-medium text-foreground hover:text-brand-primary truncate">
                    {{ item.name }}
                </Link>
                <span class="text-xs text-foreground-muted shrink-0">
                    {{ formatDate(item.date) }}
                    <span class="text-brand-primary font-medium ml-1">{{ untilLabel(item.days_until) }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
