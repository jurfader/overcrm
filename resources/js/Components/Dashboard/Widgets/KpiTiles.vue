<script setup>
import { computed } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    data: { type: Object, default: () => ({}) },
});

const tiles = computed(() => [
    { label: 'Wszystkie zadania', value: props.data.tasks ?? 0,        icon: 'tasks',    color: 'brand-primary' },
    { label: 'Na dziś',           value: props.data.todayTasks ?? 0,    icon: 'calendar', color: 'info' },
    { label: 'Przeterminowane',   value: props.data.overdueTasks ?? 0,  icon: 'alert',    color: 'destructive', highlight: true },
    { label: 'Klienci',           value: props.data.clients ?? 0,       icon: 'clients',  color: 'success' },
    { label: 'Użytkownicy',       value: props.data.users ?? 0,         icon: 'users',    color: 'brand-secondary' },
]);

function tileIconClass(color) {
    return {
        'brand-primary':   'gradient-brand text-white',
        'brand-secondary': 'gradient-brand text-white',
        'info':            'bg-info/15 text-info',
        'destructive':     'bg-destructive/15 text-destructive',
        'success':         'bg-success/15 text-success',
    }[color];
}
</script>

<template>
    <div class="p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <div v-for="tile in tiles" :key="tile.label"
             class="surface-elevated rounded-lg p-4 flex items-center gap-3">
            <div :class="['shrink-0 w-10 h-10 rounded-lg flex items-center justify-center', tileIconClass(tile.color)]">
                <Icons :name="tile.icon" class="w-5 h-5" />
            </div>
            <div class="min-w-0">
                <p class="text-[10px] font-medium text-foreground-muted uppercase tracking-wider">{{ tile.label }}</p>
                <p :class="['text-xl font-bold mt-0.5', tile.highlight && tile.value > 0 ? 'text-destructive' : 'text-foreground']">
                    {{ tile.value }}
                </p>
            </div>
        </div>
    </div>
</template>
