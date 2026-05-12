<script setup>
import { Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import WidgetBody from '@/Components/Dashboard/WidgetBody.vue';

defineProps({
    data: { type: Array, default: () => [] },
});

const priorityClass = {
    urgent: 'bg-destructive/15 text-destructive border-destructive/30',
    high:   'bg-warning/15 text-warning border-warning/30',
    medium: 'bg-info/15 text-info border-info/30',
    low:    'bg-surface-elevated text-foreground-muted border-border',
};
const priorityLabel = { urgent: 'Pilny', high: 'Wysoki', medium: 'Średni', low: 'Niski' };
</script>

<template>
    <WidgetBody>
        <div v-if="!data?.length" class="text-center py-8 text-foreground-subtle">
            <Icons name="check" class="w-10 h-10 mx-auto mb-3 text-success" />
            <p class="text-sm">Brak zadań na dziś. Świetna robota!</p>
        </div>
        <ul v-else class="divide-y divide-border -my-3">
            <li v-for="task in data" :key="task.id" class="py-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-foreground hover:text-brand-primary transition-colors">
                            {{ task.title }}
                        </Link>
                        <div class="mt-1 flex items-center gap-2 flex-wrap">
                            <span :class="['inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border', priorityClass[task.priority] || priorityClass.low]">
                                {{ priorityLabel[task.priority] || 'Niski' }}
                            </span>
                            <span v-if="task.client" class="text-xs text-foreground-muted">
                                {{ task.client.short_name || task.client.name }}
                            </span>
                        </div>
                    </div>
                    <span v-if="task.status"
                          class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium shrink-0 border"
                          :style="{ backgroundColor: task.status.color + '20', color: task.status.color, borderColor: task.status.color + '50' }">
                        {{ task.status.name }}
                    </span>
                </div>
            </li>
        </ul>

        <template v-if="data?.length" #footer>
            <Link :href="route('tasks.index', { today: true })" class="text-xs text-brand-primary hover:underline font-medium">
                Zobacz wszystkie zadania na dziś →
            </Link>
        </template>
    </WidgetBody>
</template>
