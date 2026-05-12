<script setup>
import { Link } from '@inertiajs/vue3';
import WidgetBody from '@/Components/Dashboard/WidgetBody.vue';

defineProps({
    data: { type: Array, default: () => [] },
});
</script>

<template>
    <WidgetBody>
        <div v-if="!data?.length" class="text-center py-8 text-foreground-subtle">
            <p class="text-sm">Brak klientów w systemie.</p>
        </div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <Link
                v-for="client in data" :key="client.id"
                :href="route('clients.show', client.id)"
                class="flex items-center gap-3 p-2 rounded-md surface-elevated hover:border-brand-primary/50 transition-all group"
            >
                <div class="w-9 h-9 shrink-0 rounded-full gradient-brand flex items-center justify-center text-white text-xs font-semibold">
                    {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-foreground group-hover:text-brand-primary truncate">{{ client.short_name || client.name }}</p>
                    <p class="text-xs text-foreground-muted">{{ client.type === 'company' ? 'Firma' : 'Osoba' }}</p>
                </div>
            </Link>
        </div>

        <template #footer>
            <Link :href="route('clients.index')" class="text-xs text-brand-primary hover:underline font-medium">
                Zobacz wszystkich klientów →
            </Link>
        </template>
    </WidgetBody>
</template>
