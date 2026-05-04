<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

defineProps({
    stats: Object,
    recentLogs: Array,
});

const actionColors = {
    installed: 'bg-green-100 text-green-800',
    activated: 'bg-blue-100 text-blue-800',
    deactivated: 'bg-yellow-100 text-yellow-800',
    uninstalled: 'bg-red-100 text-red-800',
    configured: 'bg-purple-100 text-purple-800',
    updated: 'bg-indigo-100 text-indigo-800',
};
</script>

<template>
    <Head title="Panel Administracyjny" />
    
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Panel Administracyjny</h1>
                <p class="text-gray-500">Zarządzaj systemem, modułami i ustawieniami</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Użytkownicy</p>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.users }}</p>
                    </div>
                    <div class="p-3 bg-indigo-100 rounded-xl">
                        <Icons name="users" class="w-8 h-8 text-indigo-600" />
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Klienci</p>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.clients }}</p>
                    </div>
                    <div class="p-3 bg-green-100 rounded-xl">
                        <Icons name="clients" class="w-8 h-8 text-green-600" />
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Zadania</p>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.tasks }}</p>
                    </div>
                    <div class="p-3 bg-yellow-100 rounded-xl">
                        <Icons name="tasks" class="w-8 h-8 text-yellow-600" />
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Aktywne moduły</p>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.modules }}</p>
                    </div>
                    <div class="p-3 bg-purple-100 rounded-xl">
                        <Icons name="puzzle" class="w-8 h-8 text-purple-600" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions & Recent Activity -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Szybkie akcje</h2>
                </div>
                <div class="p-6 grid grid-cols-2 gap-4">
                    <Link 
                        :href="route('admin.modules.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                    >
                        <div class="p-2 bg-purple-100 rounded-lg">
                            <Icons name="puzzle" class="w-6 h-6 text-purple-600" />
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">Moduły</p>
                            <p class="text-sm text-gray-500">Zarządzaj modułami</p>
                        </div>
                    </Link>

                    <Link 
                        :href="route('admin.settings.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                    >
                        <div class="p-2 bg-gray-100 rounded-lg">
                            <Icons name="settings" class="w-6 h-6 text-gray-600" />
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">Ustawienia</p>
                            <p class="text-sm text-gray-500">Konfiguracja systemu</p>
                        </div>
                    </Link>

                    <Link 
                        :href="route('users.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                    >
                        <div class="p-2 bg-indigo-100 rounded-lg">
                            <Icons name="users" class="w-6 h-6 text-indigo-600" />
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">Użytkownicy</p>
                            <p class="text-sm text-gray-500">Zarządzaj kontami</p>
                        </div>
                    </Link>

                    <Link 
                        :href="route('statuses.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                    >
                        <div class="p-2 bg-green-100 rounded-lg">
                            <Icons name="statuses" class="w-6 h-6 text-green-600" />
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">Statusy</p>
                            <p class="text-sm text-gray-500">Statusy zadań</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Ostatnia aktywność modułów</h2>
                </div>
                <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                    <div 
                        v-for="log in recentLogs" 
                        :key="log.id"
                        class="px-6 py-4 flex items-center gap-4"
                    >
                        <div class="p-2 bg-gray-100 rounded-lg">
                            <Icons :name="log.module?.icon || 'puzzle'" class="w-5 h-5 text-gray-600" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">
                                {{ log.module?.display_name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ log.user?.name || 'System' }} · 
                                {{ new Date(log.created_at).toLocaleString('pl-PL') }}
                            </p>
                        </div>
                        <span :class="['px-2 py-1 text-xs font-medium rounded-full', actionColors[log.action] || 'bg-gray-100 text-gray-800']">
                            {{ log.action }}
                        </span>
                    </div>
                    <div v-if="recentLogs.length === 0" class="px-6 py-8 text-center text-gray-500">
                        Brak ostatniej aktywności
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
