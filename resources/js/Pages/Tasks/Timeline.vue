<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Select from '@/Components/Select.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    tasks: Array,
    users: Array,
    statuses: Array,
    filters: Object,
});

const myTasks = ref(props.filters.my_tasks);
const assignedFilter = ref(props.filters.assigned_to);
const startDate = ref(props.filters.start);
const endDate = ref(props.filters.end);

watch([myTasks, assignedFilter], () => applyFilters());

function applyFilters() {
    router.get(route('tasks.timeline'), {
        my_tasks: myTasks.value || undefined,
        assigned_to: assignedFilter.value || undefined,
        start: startDate.value,
        end: endDate.value,
    }, { preserveState: true, preserveScroll: true });
}

function shiftRange(weeks) {
    const s = new Date(startDate.value);
    const e = new Date(endDate.value);
    s.setDate(s.getDate() + weeks * 7);
    e.setDate(e.getDate() + weeks * 7);
    startDate.value = fmt(s);
    endDate.value = fmt(e);
    applyFilters();
}

function fmt(d) {
    return d.toISOString().split('T')[0];
}

// Generuj tablicę dni
const days = computed(() => {
    const result = [];
    const s = new Date(startDate.value);
    const e = new Date(endDate.value);
    for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        result.push({
            date: fmt(new Date(d)),
            day: d.getDate(),
            weekday: d.toLocaleDateString('pl-PL', { weekday: 'short' }),
            month: d.toLocaleDateString('pl-PL', { month: 'short' }),
            isToday: fmt(new Date(d)) === fmt(new Date()),
            isWeekend: d.getDay() === 0 || d.getDay() === 6,
        });
    }
    return result;
});

// Pogrupuj zadania po assignee
const groupedTasks = computed(() => {
    const groups = {};

    // Najpierw "Nieprzypisane"
    groups['unassigned'] = {
        label: 'Nieprzypisane',
        tasks: props.tasks.filter(t => !t.assignee),
    };

    // Potem po użytkownikach
    const usersWithTasks = new Map();
    props.tasks.forEach(t => {
        if (t.assignee) {
            if (!usersWithTasks.has(t.assignee.id)) {
                usersWithTasks.set(t.assignee.id, {
                    label: t.assignee.name,
                    tasks: [],
                });
            }
            usersWithTasks.get(t.assignee.id).tasks.push(t);
        }
    });

    usersWithTasks.forEach((v, k) => { groups[k] = v; });

    // Filtruj puste grupy
    return Object.fromEntries(Object.entries(groups).filter(([, v]) => v.tasks.length > 0));
});

// Pozycja zadania na osi czasu
function taskPosition(task) {
    const start = new Date(startDate.value);
    const due = new Date(task.due_date);
    const submit = task.submit_date ? new Date(task.submit_date) : due;
    const totalDays = days.value.length;

    const barStart = Math.max(0, Math.round((submit - start) / 86400000));
    const barEnd = Math.max(barStart + 1, Math.round((due - start) / 86400000) + 1);

    return {
        left: (barStart / totalDays) * 100,
        width: Math.max(2, ((barEnd - barStart) / totalDays) * 100),
    };
}

function isOverdue(task) {
    if (!task.due_date || task.status?.is_final) return false;
    return new Date(task.due_date) < new Date().setHours(0, 0, 0, 0);
}

const priorityColors = {
    low: '#94a3b8',
    medium: '#3b82f6',
    high: '#f59e0b',
    urgent: '#ef4444',
};

// Pozycja markera "dziś"
const todayPosition = computed(() => {
    const start = new Date(startDate.value);
    const today = new Date();
    const totalDays = days.value.length;
    const offset = Math.round((today - start) / 86400000);
    if (offset < 0 || offset >= totalDays) return null;
    return (offset / totalDays) * 100;
});
</script>

<template>
    <Head title="Timeline" />

    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Timeline</h1>
                <p class="text-foreground-muted">Widok osi czasu zadań</p>
            </div>
            <div class="flex items-center gap-2">
                <Link :href="route('tasks.index')">
                    <Button variant="secondary" size="sm">
                        <Icons name="tasks" class="w-4 h-4 mr-1" /> Lista
                    </Button>
                </Link>
                <Link :href="route('tasks.kanban')">
                    <Button variant="secondary" size="sm">
                        <Icons name="kanban" class="w-4 h-4 mr-1" /> Kanban
                    </Button>
                </Link>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="surface rounded-xl border border-border p-3 flex items-center gap-4 flex-wrap">
            <!-- Nawigacja dat -->
            <div class="flex items-center gap-1">
                <button @click="shiftRange(-2)" class="p-1.5 rounded-lg hover:bg-surface-elevated text-foreground-muted" title="2 tygodnie wstecz">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" /></svg>
                </button>
                <button @click="shiftRange(-1)" class="p-1.5 rounded-lg hover:bg-surface-elevated text-foreground-muted" title="Tydzień wstecz">
                    <Icons name="chevron-left" class="w-4 h-4" />
                </button>
                <span class="text-sm font-medium text-foreground px-2 min-w-[180px] text-center">
                    {{ new Date(startDate).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' }) }}
                    —
                    {{ new Date(endDate).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' }) }}
                </span>
                <button @click="shiftRange(1)" class="p-1.5 rounded-lg hover:bg-surface-elevated text-foreground-muted" title="Tydzień dalej">
                    <Icons name="chevron-right" class="w-4 h-4" />
                </button>
                <button @click="shiftRange(2)" class="p-1.5 rounded-lg hover:bg-surface-elevated text-foreground-muted" title="2 tygodnie dalej">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 4.5l7.5 7.5-7.5 7.5m-6-15l7.5 7.5-7.5 7.5" /></svg>
                </button>
            </div>

            <div class="h-6 w-px bg-gray-200 dark:bg-slate-700"></div>

            <!-- Filtry -->
            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="myTasks" class="rounded border-border-bright text-amber-600 focus:ring-brand-primary dark:bg-slate-700" />
                <span class="text-sm text-foreground">Tylko moje</span>
            </label>

            <div class="w-40">
                <Select v-model="assignedFilter" :options="users" placeholder="Wszyscy" size="sm" />
            </div>
        </div>

        <!-- Timeline -->
        <div class="surface rounded-xl border border-border overflow-hidden">
            <!-- Header z dniami -->
            <div class="flex border-b border-border">
                <!-- Kolumna nazw -->
                <div class="w-48 shrink-0 surface-2 border-r border-border px-3 py-2">
                    <span class="text-xs font-medium text-foreground-muted uppercase">Przypisany</span>
                </div>
                <!-- Dni -->
                <div class="flex-1 overflow-x-auto">
                    <div class="flex min-w-[800px]">
                        <div 
                            v-for="day in days" 
                            :key="day.date"
                            class="flex-1 min-w-[28px] text-center py-1 border-r border-gray-100 dark:border-slate-700/50 last:border-0"
                            :class="{
                                'bg-amber-50 dark:bg-amber-900/10': day.isToday,
                                'bg-gray-50/50 dark:bg-slate-900/30': day.isWeekend && !day.isToday,
                            }"
                        >
                            <div class="text-[10px] text-gray-400 dark:text-slate-500 leading-tight">{{ day.weekday }}</div>
                            <div 
                                class="text-xs font-medium leading-tight"
                                :class="day.isToday ? 'text-amber-600 dark:text-amber-400 font-bold' : 'text-foreground-muted'"
                            >
                                {{ day.day }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grupy -->
            <div v-if="Object.keys(groupedTasks).length > 0">
                <div v-for="(group, key) in groupedTasks" :key="key" class="border-b border-border last:border-0">
                    <!-- Nazwa grupy -->
                    <div class="flex">
                        <div class="w-48 shrink-0 surface-2 border-r border-border px-3 py-2 flex items-center">
                            <span class="text-sm font-medium text-foreground truncate">{{ group.label }}</span>
                            <span class="ml-auto text-xs text-gray-400 dark:text-slate-500">{{ group.tasks.length }}</span>
                        </div>
                        <div class="flex-1 relative min-h-[40px]">
                            <!-- Marker "dziś" -->
                            <div 
                                v-if="todayPosition !== null"
                                class="absolute top-0 bottom-0 w-px bg-amber-400 dark:bg-amber-500 z-10"
                                :style="{ left: todayPosition + '%' }"
                            ></div>
                        </div>
                    </div>

                    <!-- Zadania w grupie -->
                    <div v-for="task in group.tasks" :key="task.id" class="flex hover:bg-surface-elevated/30 transition-colors">
                        <div class="w-48 shrink-0 border-r border-border px-3 py-1.5 flex items-center">
                            <Link :href="route('tasks.show', task.id)" class="text-xs text-foreground hover:text-amber-600 dark:hover:text-amber-400 truncate">
                                {{ task.title }}
                            </Link>
                        </div>
                        <div class="flex-1 relative h-8 overflow-x-auto">
                            <div class="min-w-[800px] h-full relative">
                                <!-- Today marker -->
                                <div 
                                    v-if="todayPosition !== null"
                                    class="absolute top-0 bottom-0 w-px bg-amber-400/30 dark:bg-amber-500/20 z-0"
                                    :style="{ left: todayPosition + '%' }"
                                ></div>
                                <!-- Task bar -->
                                <div
                                    class="absolute top-1 h-6 rounded-md flex items-center px-2 text-[10px] font-medium text-white truncate cursor-pointer transition-opacity hover:opacity-90 z-10"
                                    :style="{
                                        left: taskPosition(task).left + '%',
                                        width: taskPosition(task).width + '%',
                                        backgroundColor: task.status?.color || priorityColors[task.priority] || '#94a3b8',
                                        opacity: isOverdue(task) ? 0.7 : 1,
                                    }"
                                    :title="task.title + ' (' + (task.due_date || '') + ')'"
                                    @click="router.visit(route('tasks.show', task.id))"
                                >
                                    <span class="truncate">{{ task.title }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="px-6 py-12 text-center">
                <Icons name="tasks" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                <p class="text-foreground-muted">Brak zadań w wybranym zakresie dat</p>
                <p class="text-sm text-gray-400 dark:text-slate-500 mt-1">Zmień zakres lub filtry</p>
            </div>
        </div>
    </div>
</template>
