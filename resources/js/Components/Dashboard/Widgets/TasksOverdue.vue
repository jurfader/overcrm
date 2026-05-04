<script setup>
import { Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

defineProps({
    data: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="flex-1 p-4 overflow-y-auto">
            <div v-if="!data?.length" class="text-center py-8 text-foreground-subtle">
                <Icons name="check" class="w-10 h-10 mx-auto mb-3 text-success" />
                <p class="text-sm">Brak przeterminowanych zadań!</p>
            </div>
            <ul v-else class="divide-y divide-border -my-3">
                <li v-for="task in data" :key="task.id" class="py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <Link :href="route('tasks.show', task.id)" class="text-sm font-medium text-foreground hover:text-brand-primary transition-colors">
                                {{ task.title }}
                            </Link>
                            <div class="mt-1 flex items-center gap-1.5 text-xs text-destructive">
                                <Icons name="alert" class="w-3.5 h-3.5" />
                                Termin: {{ new Date(task.due_date).toLocaleDateString('pl-PL') }}
                            </div>
                        </div>
                        <div v-if="task.assignee" class="shrink-0 text-xs text-foreground-muted">
                            {{ task.assignee.name }}
                        </div>
                    </div>
                </li>
            </ul>
        </div>
        <div v-if="data?.length" class="px-4 py-2 border-t border-border bg-surface-2">
            <Link :href="route('tasks.index', { overdue: true })" class="text-xs text-destructive hover:underline font-medium">
                Zobacz wszystkie przeterminowane →
            </Link>
        </div>
    </div>
</template>
