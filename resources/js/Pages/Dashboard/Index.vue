<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import {
    ClipboardDocumentListIcon,
    UserGroupIcon,
    CheckCircleIcon,
    ClockIcon,
    ArrowTrendingUpIcon,
    CalendarDaysIcon,
} from '@heroicons/vue/24/outline';

const page = usePage();
const user = computed(() => page.props.auth.user);

// Mock data for demonstration
const stats = [
    {
        name: 'Wszystkie zadania',
        value: '142',
        change: '+12%',
        changeType: 'positive',
        icon: ClipboardDocumentListIcon,
        color: 'bg-blue-500',
    },
    {
        name: 'Klienci',
        value: '38',
        change: '+4',
        changeType: 'positive',
        icon: UserGroupIcon,
        color: 'bg-purple-500',
    },
    {
        name: 'Wykonane',
        value: '89',
        change: '+23%',
        changeType: 'positive',
        icon: CheckCircleIcon,
        color: 'bg-green-500',
    },
    {
        name: 'W trakcie',
        value: '34',
        change: '-5%',
        changeType: 'negative',
        icon: ClockIcon,
        color: 'bg-yellow-500',
    },
];

const todayTasks = [
    { id: 1, title: 'Spotkanie z klientem ABC', time: '10:00', status: 'in_progress', priority: 'high' },
    { id: 2, title: 'Przygotowanie oferty dla XYZ', time: '14:00', status: 'new', priority: 'medium' },
    { id: 3, title: 'Telefon do dostawcy', time: '16:00', status: 'new', priority: 'low' },
    { id: 4, title: 'Raport tygodniowy', time: '17:00', status: 'new', priority: 'medium' },
];

const statusColors = {
    new: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    in_progress: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    done: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
};

const priorityColors = {
    low: 'border-gray-300 dark:border-gray-600',
    medium: 'border-yellow-400',
    high: 'border-red-500',
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        Witaj, {{ user?.name?.split(' ')[0] }}! 👋
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Oto podsumowanie Twojego dnia
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button class="btn-secondary">
                        <CalendarDaysIcon class="w-5 h-5" />
                        Kalendarz
                    </button>
                    <button class="btn-primary">
                        <ClipboardDocumentListIcon class="w-5 h-5" />
                        Nowe zadanie
                    </button>
                </div>
            </div>
        </template>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div
                v-for="stat in stats"
                :key="stat.name"
                class="card p-6 hover:shadow-md transition-shadow"
            >
                <div class="flex items-center justify-between">
                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center"
                        :class="stat.color"
                    >
                        <component :is="stat.icon" class="w-6 h-6 text-white" />
                    </div>
                    <div
                        class="flex items-center gap-1 text-sm font-medium"
                        :class="stat.changeType === 'positive' ? 'text-green-600' : 'text-red-600'"
                    >
                        <ArrowTrendingUpIcon
                            class="w-4 h-4"
                            :class="stat.changeType === 'negative' && 'rotate-180'"
                        />
                        {{ stat.change }}
                    </div>
                </div>
                <div class="mt-4">
                    <p class="text-3xl font-bold text-gray-900 dark:text-white">
                        {{ stat.value }}
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ stat.name }}
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Today's Tasks -->
            <div class="lg:col-span-2">
                <div class="card">
                    <div class="card-header flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            Dzisiejsze zadania
                        </h2>
                        <span class="badge-blue">{{ todayTasks.length }} zadań</span>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <div
                            v-for="task in todayTasks"
                            :key="task.id"
                            class="p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors cursor-pointer"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-1 h-12 rounded-full"
                                    :class="priorityColors[task.priority]"
                                ></div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-white truncate">
                                        {{ task.title }}
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ task.time }}
                                    </p>
                                </div>
                                <span
                                    class="badge"
                                    :class="statusColors[task.status]"
                                >
                                    {{ task.status === 'new' ? 'Nowe' : task.status === 'in_progress' ? 'W trakcie' : 'Zakończone' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="text-sm text-primary-600 hover:text-primary-700 font-medium">
                            Zobacz wszystkie zadania →
                        </button>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & Recent Activity -->
            <div class="space-y-6">
                <!-- Quick Actions -->
                <div class="card p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        Szybkie akcje
                    </h2>
                    <div class="space-y-3">
                        <button class="w-full btn-secondary justify-start">
                            <ClipboardDocumentListIcon class="w-5 h-5" />
                            Dodaj zadanie
                        </button>
                        <button class="w-full btn-secondary justify-start">
                            <UserGroupIcon class="w-5 h-5" />
                            Dodaj klienta
                        </button>
                        <button class="w-full btn-secondary justify-start">
                            <CalendarDaysIcon class="w-5 h-5" />
                            Zaplanuj spotkanie
                        </button>
                    </div>
                </div>

                <!-- System Info -->
                <div class="card p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        Informacje
                    </h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Wersja</span>
                            <span class="font-medium text-gray-900 dark:text-white">2.0.0</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Aktywne moduły</span>
                            <span class="font-medium text-gray-900 dark:text-white">6</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Ostatnia aktualizacja</span>
                            <span class="font-medium text-gray-900 dark:text-white">Dzisiaj</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
