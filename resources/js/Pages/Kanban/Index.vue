<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    statuses: Array,
    tasksByStatus: Object,
    filters: Object,
});

const myTasks = ref(props.filters.my_tasks);

const priorityColors = {
    low: 'gray',
    medium: 'blue',
    high: 'yellow',
    urgent: 'red',
};

function toggleMyTasks() {
    router.get(route('kanban.index'), {
        my_tasks: !myTasks.value || undefined,
    }, {
        preserveState: true,
    });
}

function changeStatus(taskId, newStatusId) {
    router.patch(route('tasks.update-status', taskId), {
        status_id: newStatusId,
    }, {
        preserveScroll: true,
    });
}

function formatDate(date) {
    if (!date) return '';
    const d = new Date(date);
    const today = new Date();
    today.setHours(0,0,0,0);
    
    if (d < today) return 'Przeterminowane';
    if (d.toDateString() === today.toDateString()) return 'Dziś';
    
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (d.toDateString() === tomorrow.toDateString()) return 'Jutro';
    
    return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
}

function isOverdue(task) {
    if (!task.due_date) return false;
    return new Date(task.due_date) < new Date().setHours(0,0,0,0);
}
</script>

<template>
    <Head title="Kanban" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Tablica Kanban</h1>
                <p class="text-gray-600">Przeciągaj zadania między kolumnami</p>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex items-center bg-white px-4 py-2 rounded-lg border border-gray-200">
                    <input type="checkbox" v-model="myTasks" @change="toggleMyTasks" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span class="ml-2 text-sm text-gray-700">Tylko moje zadania</span>
                </label>
                <Link :href="route('tasks.index')">
                    <Button variant="secondary">
                        <Icons name="list" class="w-5 h-5 mr-2" />
                        Lista
                    </Button>
                </Link>
                <Link :href="route('tasks.create')">
                    <Button>
                        <Icons name="plus" class="w-5 h-5 mr-2" />
                        Nowe zadanie
                    </Button>
                </Link>
            </div>
        </div>

        <!-- Kolumny Kanban -->
        <div class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="status in statuses"
                :key="status.id"
                class="flex-shrink-0 w-80 bg-gray-100 rounded-lg"
            >
                <!-- Nagłówek kolumny -->
                <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-3 h-3 rounded-full mr-2" :style="{ backgroundColor: status.color }"></div>
                        <h3 class="font-semibold text-gray-900">{{ status.name }}</h3>
                    </div>
                    <span class="text-sm text-gray-500">{{ tasksByStatus[status.id]?.length || 0 }}</span>
                </div>

                <!-- Zadania -->
                <div class="p-2 space-y-2 min-h-[200px] max-h-[calc(100vh-300px)] overflow-y-auto">
                    <div
                        v-for="task in tasksByStatus[status.id]"
                        :key="task.id"
                        class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 cursor-pointer hover:shadow-md transition-shadow"
                        :class="{ 'border-red-300 bg-red-50': isOverdue(task) }"
                    >
                        <Link :href="route('tasks.show', task.id)" class="block">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">{{ task.title }}</h4>
                            
                            <div class="flex items-center justify-between text-xs">
                                <Badge :color="priorityColors[task.priority]" size="sm">
                                    {{ task.priority === 'low' ? 'Niski' : task.priority === 'medium' ? 'Średni' : task.priority === 'high' ? 'Wysoki' : 'Pilny' }}
                                </Badge>
                                <span v-if="task.due_date" :class="isOverdue(task) ? 'text-red-600' : 'text-gray-500'">
                                    {{ formatDate(task.due_date) }}
                                </span>
                            </div>
                            
                            <div v-if="task.client || task.assignee" class="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                                <span v-if="task.client">{{ task.client.short_name || task.client.name }}</span>
                                <span v-if="task.assignee" class="flex items-center">
                                    <span class="w-5 h-5 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-[10px] font-medium mr-1">
                                        {{ task.assignee.name.split(' ').map(n => n[0]).join('').slice(0,2) }}
                                    </span>
                                    {{ task.assignee.name.split(' ')[0] }}
                                </span>
                            </div>
                        </Link>

                        <!-- Szybka zmiana statusu -->
                        <div class="mt-2 pt-2 border-t border-gray-100 flex gap-1">
                            <button
                                v-for="s in statuses.filter(st => st.id !== status.id)"
                                :key="s.id"
                                @click.prevent="changeStatus(task.id, s.id)"
                                class="flex-1 px-2 py-1 text-xs rounded hover:opacity-80 transition-opacity"
                                :style="{ backgroundColor: s.color + '20', color: s.color }"
                                :title="'Przenieś do: ' + s.name"
                            >
                                {{ s.name }}
                            </button>
                        </div>
                    </div>

                    <div v-if="!tasksByStatus[status.id]?.length" class="text-center py-8 text-gray-400">
                        <Icons name="tasks" class="w-8 h-8 mx-auto mb-2" />
                        <p class="text-sm">Brak zadań</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
