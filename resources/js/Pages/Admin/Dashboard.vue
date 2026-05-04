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
                <h1 class="text-2xl font-bold gradient-brand-text">Panel Administracyjny</h1>
                <p class="text-foreground-muted text-sm mt-1">Zarządzaj systemem, modułami i ustawieniami</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-foreground-muted">Użytkownicy</p>
                        <p class="text-3xl font-bold text-foreground">{{ stats.users }}</p>
                    </div>
                    <div class="p-3 bg-amber-100 dark:bg-amber-900/30 rounded-xl">
                        <Icons name="users" class="w-8 h-8 text-brand-primary" />
                    </div>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-foreground-muted">Klienci</p>
                        <p class="text-3xl font-bold text-foreground">{{ stats.clients }}</p>
                    </div>
                    <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl">
                        <Icons name="clients" class="w-8 h-8 text-green-600" />
                    </div>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-foreground-muted">Zadania</p>
                        <p class="text-3xl font-bold text-foreground">{{ stats.tasks }}</p>
                    </div>
                    <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl">
                        <Icons name="tasks" class="w-8 h-8 text-yellow-600" />
                    </div>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-foreground-muted">Aktywne moduły</p>
                        <p class="text-3xl font-bold text-foreground">{{ stats.modules }}</p>
                    </div>
                    <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
                        <Icons name="puzzle" class="w-8 h-8 text-purple-600" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions & Recent Activity -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Quick Actions -->
            <div class="glass-card">
                <div class="px-6 py-4 border-b border-border">
                    <h2 class="text-lg font-semibold text-foreground">Szybkie akcje</h2>
                </div>
                <div class="p-6 grid grid-cols-2 gap-4">
                    <Link
                        :href="route('admin.modules.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-border hover:bg-surface-elevated/50 transition-colors"
                    >
                        <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                            <Icons name="puzzle" class="w-6 h-6 text-purple-600" />
                        </div>
                        <div>
                            <p class="font-medium text-foreground">Moduły</p>
                            <p class="text-sm text-foreground-muted">Zarządzaj modułami</p>
                        </div>
                    </Link>

                    <Link
                        :href="route('admin.settings.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-border hover:bg-surface-elevated/50 transition-colors"
                    >
                        <div class="p-2 surface-elevated rounded-lg">
                            <Icons name="settings" class="w-6 h-6 text-foreground-muted" />
                        </div>
                        <div>
                            <p class="font-medium text-foreground">Ustawienia</p>
                            <p class="text-sm text-foreground-muted">Konfiguracja systemu</p>
                        </div>
                    </Link>

                    <Link
                        :href="route('users.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-border hover:bg-surface-elevated/50 transition-colors"
                    >
                        <div class="p-2 bg-amber-100 dark:bg-amber-900/30 rounded-lg">
                            <Icons name="users" class="w-6 h-6 text-brand-primary" />
                        </div>
                        <div>
                            <p class="font-medium text-foreground">Użytkownicy</p>
                            <p class="text-sm text-foreground-muted">Zarządzaj kontami</p>
                        </div>
                    </Link>

                    <Link
                        :href="route('statuses.index')"
                        class="flex items-center gap-3 p-4 rounded-lg border border-border hover:bg-surface-elevated/50 transition-colors"
                    >
                        <div class="p-2 bg-green-100 dark:bg-green-900/30 rounded-lg">
                            <Icons name="statuses" class="w-6 h-6 text-green-600" />
                        </div>
                        <div>
                            <p class="font-medium text-foreground">Statusy</p>
                            <p class="text-sm text-foreground-muted">Statusy zadań</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="glass-card">
                <div class="px-6 py-4 border-b border-border">
                    <h2 class="text-lg font-semibold text-foreground">Ostatnia aktywność modułów</h2>
                </div>
                <div class="divide-y divide-border max-h-80 overflow-y-auto">
                    <div
                        v-for="log in recentLogs"
                        :key="log.id"
                        class="px-6 py-4 flex items-center gap-4"
                    >
                        <div class="p-2 surface-elevated rounded-lg">
                            <Icons :name="log.module?.icon || 'puzzle'" class="w-5 h-5 text-foreground-muted" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-foreground">
                                {{ log.module?.display_name }}
                            </p>
                            <p class="text-xs text-foreground-muted">
                                {{ log.user?.name || 'System' }} ·
                                {{ new Date(log.created_at).toLocaleString('pl-PL') }}
                            </p>
                        </div>
                        <span :class="['px-2 py-1 text-xs font-medium rounded-full', actionColors[log.action] || 'surface-elevated text-foreground']">
                            {{ log.action }}
                        </span>
                    </div>
                    <div v-if="recentLogs.length === 0" class="px-6 py-8 text-center text-foreground-muted">
                        Brak ostatniej aktywności
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
