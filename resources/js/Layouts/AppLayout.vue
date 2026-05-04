<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Icons from '@/Components/Icons.vue';
import KeyboardShortcutsHelp from '@/Components/KeyboardShortcutsHelp.vue';
import FloatingVisitPanel from '@/Components/FloatingVisitPanel.vue';
import HiddenGamesPanel from '@/Components/HiddenGamesPanel.vue';
import { useKeyboardShortcuts } from '@/Composables/useKeyboardShortcuts';

const showFloatingVisitSearch = ref(false);

// Powiadomienie o nowym deployu – odśwież z Shift
const knownBuildVersion = ref(null);
const showDeployNotification = ref(false);
watch(() => usePage().props.buildVersion, (newVersion) => {
    if (newVersion && knownBuildVersion.value !== null && knownBuildVersion.value !== newVersion) {
        showDeployNotification.value = true;
    }
    if (newVersion && knownBuildVersion.value === null) {
        knownBuildVersion.value = newVersion;
    }
}, { immediate: true });

let buildVersionPollInterval = null;
onMounted(() => {
    buildVersionPollInterval = setInterval(async () => {
        try {
            const res = await fetch(route('build-version'), { credentials: 'same-origin' });
            if (res.ok) {
                const { buildVersion } = await res.json();
                if (buildVersion && knownBuildVersion.value !== null && knownBuildVersion.value !== buildVersion) {
                    showDeployNotification.value = true;
                }
            }
        } catch (_) {}
    }, 60000);
});
onBeforeUnmount(() => {
    if (buildVersionPollInterval) clearInterval(buildVersionPollInterval);
});

function hardRefresh() {
    window.location.reload();
}
const { showHelp, shortcuts } = useKeyboardShortcuts({
    onQuickOpenVisit: () => { showFloatingVisitSearch.value = true; },
});

const page = usePage();
const isTestEnv = computed(() => page.props.isTestEnv ?? false);
const showMobileMenu = ref(false);
const showUserMenu = ref(false);

// Dark mode
const isDark = ref(false);

function initTheme() {
    if (typeof window === 'undefined') return;
    const stored = localStorage.getItem('theme');
    if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    } else {
        isDark.value = false;
        document.documentElement.classList.remove('dark');
    }
}

function toggleDarkMode() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
}

initTheme();

// Pobierz aktualnego użytkownika
const currentUser = computed(() => page.props.auth?.user);
const userRole = computed(() => currentUser.value?.role || 'user');

// Pobierz ustawienia aplikacji
const appSettings = computed(() => page.props.appSettings || {});
const inboxUnreadCount = computed(() => page.props.inboxUnreadCount ?? 0);
const appName = computed(() => appSettings.value.app_name || 'CHICKENKING Planner');
const appLogo = computed(() => appSettings.value.app_logo);

// Pobierz aktywne moduły z props
const activeModules = computed(() => page.props.activeModules || []);

const logout = () => {
    router.post(route('logout'));
};

const navigation = [
    { name: 'Dashboard', route: 'dashboard', icon: 'dashboard' },
    { name: 'Kalendarz', route: 'calendar.index', icon: 'calendar', pattern: 'calendar.*' },
    { name: 'Zadania', route: 'tasks.index', icon: 'tasks', pattern: 'tasks.*' },
    { name: 'Klienci', route: 'clients.index', icon: 'clients', pattern: 'clients.*' },
    { name: 'Changelog', route: 'changelog.index', icon: 'document-text', pattern: 'changelog.*' },
    { name: 'Cenniki', route: 'price-lists.index', icon: 'price-list', pattern: 'price-lists.*' },
];

// Dynamiczne linki do modułów
const moduleNavigation = computed(() => {
    return activeModules.value
        .filter(mod => mod.menu && mod.menu.length > 0)
        .flatMap(mod => mod.menu.map(menuItem => ({
            name: menuItem.label,
            route: menuItem.route,
            icon: menuItem.icon || mod.icon || 'puzzle',
            pattern: mod.name + '.*',
        })));
});

const adminNavigation = [
    { name: 'Użytkownicy', route: 'users.index', icon: 'users', pattern: 'users.*', roles: ['admin', 'manager'] },
    { name: 'Statusy', route: 'statuses.index', icon: 'statuses', pattern: 'statuses.*', roles: ['admin'] },
    { name: 'Szablony Email', route: 'admin.email-templates.index', icon: 'mail', pattern: 'admin.email-templates.*', roles: ['admin'] },
    { name: 'Raport dzienny', route: 'admin.daily-report', icon: 'document-text', pattern: 'admin.daily-report', roles: ['admin'] },
    { name: 'Logi integracji', route: 'admin.integration-logs', icon: 'activity', pattern: 'admin.integration-logs', roles: ['admin'] },
    { name: 'Moduły', route: 'admin.modules.index', icon: 'puzzle', pattern: 'admin.modules.*', roles: ['admin'] },
    { name: 'Cenniki', route: 'admin.price-lists.index', icon: 'price-list', pattern: 'admin.price-lists.*', roles: ['admin'] },
    { name: 'Uczenie AI', route: 'admin.ai-training.index', icon: 'sparkles', pattern: 'admin.ai-training.*', roles: ['admin'] },
    { name: 'Ustawienia', route: 'admin.settings.index', icon: 'settings', pattern: 'admin.settings.*', roles: ['admin'] },
];

// Menu użytkownika zależne od roli
const userMenuItems = [
    { name: 'Mój profil', action: 'profile', icon: 'user', roles: ['admin', 'manager', 'user'] },
    { name: 'Serwer pocztowy', action: 'settings', icon: 'mail', roles: ['admin', 'manager', 'user'] },
    { name: 'Zabezpieczenia (2FA)', action: '2fa', icon: 'lock', roles: ['admin', 'manager', 'user'] },
];

function isActive(item) {
    if (item.pattern) {
        return route().current(item.pattern);
    }
    return route().current(item.route);
}

function canAccess(item) {
    if (!item.roles) return true;
    return item.roles.includes(userRole.value);
}

function handleUserMenuAction(item) {
    showUserMenu.value = false;
    if (item.route) {
        router.visit(route(item.route));
    } else if (item.action === 'profile') {
        // TODO: Otwórz modal profilu lub przekieruj
        alert('Profil użytkownika - wkrótce');
    } else if (item.action === 'settings') {
        // Przejdź do ustawień serwera pocztowego
        router.visit(route('settings.mail.index'));
    } else if (item.action === '2fa') {
        router.visit(route('two-factor.setup'));
    } else if (item.action === 'notifications') {
        // TODO: Otwórz powiadomienia
        alert('Powiadomienia - wkrótce');
    }
}
</script>

<template>
    <div class="min-h-screen bg-slate-100 dark:bg-slate-900 transition-colors duration-200">
        <!-- Baner: wersja testowa -->
        <div
            v-if="isTestEnv"
            class="fixed top-0 left-0 right-0 z-[110] bg-amber-500 text-slate-900 px-4 py-2.5 text-center text-base font-bold shadow-lg border-b-2 border-amber-600"
        >
            ⚠️ WERSJA TESTOWA APLIKACJI — test.crm.chickenking.co
        </div>
        <!-- Powiadomienie o nowym deployu -->
        <div
            v-if="showDeployNotification"
            class="fixed top-0 left-0 right-0 z-[100] bg-amber-500 text-white px-4 py-3 text-center text-sm font-medium shadow-lg"
        >
            <span>Nowa wersja aplikacji jest dostępna. Odśwież stronę trzymając <kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs font-bold">Shift</kbd> i klikając odśwież (lub <kbd class="px-1.5 py-0.5 bg-amber-600 rounded text-xs font-bold">Shift+F5</kbd>), aby załadować zmiany.</span>
            <button
                @click="hardRefresh"
                class="ml-3 px-3 py-1 bg-amber-600 hover:bg-amber-700 rounded font-semibold transition-colors"
            >
                Odśwież teraz
            </button>
        </div>
        <!-- Sidebar (desktop) -->
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-64 lg:flex-col" :class="{ 'lg:top-12': isTestEnv }">
            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-slate-900 px-6 pb-4">
                <!-- Logo -->
                <div class="flex h-16 shrink-0 items-center">
                    <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-8 w-auto" />
                    <span v-else class="text-xl font-bold text-white">{{ appName }}</span>
                </div>

                <!-- Navigation -->
                <nav class="flex flex-1 flex-col">
                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                        <li>
                                            <ul role="list" class="-mx-2 space-y-1">
                                                <li v-for="item in navigation" :key="item.name">
                                                    <Link
                                                        :href="route(item.route)"
                                                        :class="[
                                                            isActive(item)
                                                                ? 'bg-amber-500 text-white'
                                                                : 'text-slate-300 hover:text-white hover:bg-slate-800',
                                                            'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold transition-colors'
                                                        ]"
                                                    >
                                                        <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                                        {{ item.name }}
                                                    </Link>
                                                </li>
                                            </ul>
                                        </li>

                                        <!-- Moduły -->
                                        <li v-if="moduleNavigation.length > 0">
                                            <div class="text-xs font-semibold leading-6 text-slate-500 uppercase tracking-wider">Moduły</div>
                                            <ul role="list" class="-mx-2 mt-2 space-y-1">
                                                <li v-for="item in moduleNavigation" :key="item.name">
                                                    <Link
                                                        :href="route(item.route)"
                                                        :class="[
                                                            isActive(item)
                                                                ? 'bg-amber-500 text-white'
                                                                : 'text-slate-300 hover:text-white hover:bg-slate-800',
                                                            'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold transition-colors'
                                                        ]"
                                                    >
                                                        <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                                        <span class="flex-1">{{ item.name }}</span>
                                                        <span
                                                            v-if="item.route === 'email.inbox.index' && inboxUnreadCount > 0"
                                                            class="min-w-[1.25rem] h-5 px-1.5 flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold"
                                                        >
                                                            {{ inboxUnreadCount > 99 ? '99+' : inboxUnreadCount }}
                                                        </span>
                                                    </Link>
                                                </li>
                                            </ul>
                                        </li>

                                        <!-- Admin Section -->
                        <li v-if="adminNavigation.some(item => canAccess(item))">
                            <div class="text-xs font-semibold leading-6 text-slate-500 uppercase tracking-wider">Administracja</div>
                            <ul role="list" class="-mx-2 mt-2 space-y-1">
                                <li v-for="item in adminNavigation" :key="item.name">
                                    <Link
                                        v-if="canAccess(item)"
                                        :href="route(item.route)"
                                        :class="[
                                            isActive(item)
                                                ? 'bg-amber-500 text-white'
                                                : 'text-slate-300 hover:text-white hover:bg-slate-800',
                                            'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold transition-colors'
                                        ]"
                                    >
                                        <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                        {{ item.name }}
                                    </Link>
                                </li>
                            </ul>
                        </li>

                        <!-- Dark mode toggle + shortcuts -->
                        <li class="mt-auto space-y-1">
                            <button 
                                @click="toggleDarkMode"
                                class="w-full flex items-center gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <Icons :name="isDark ? 'sun' : 'moon'" class="h-6 w-6 shrink-0" />
                                {{ isDark ? 'Tryb jasny' : 'Tryb ciemny' }}
                            </button>
                            <button 
                                @click="showHelp = true"
                                class="w-full flex items-center gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <span class="flex items-center justify-center h-6 w-6 shrink-0 rounded border border-slate-600 text-xs font-bold">?</span>
                                Skróty klawiszowe
                            </button>
                        </li>

                        <!-- Zminimalizowane wizyty (floating panel) – pod modułami, nad profilem -->
                        <li id="sidebar-minimized-visits" class="space-y-1 -mx-2 max-h-40 overflow-y-auto shrink-0"></li>

                        <!-- User profile with dropdown -->
                        <li class="relative shrink-0">
                            <button 
                                @click="showUserMenu = !showUserMenu"
                                class="w-full flex items-center gap-x-4 px-2 py-3 text-sm font-semibold leading-6 text-white border-t border-slate-700 pt-4 hover:bg-slate-800 rounded-md transition-colors"
                            >
                                <img 
                                    v-if="$page.props.auth.user?.avatar_url" 
                                    :src="$page.props.auth.user.avatar_url" 
                                    :alt="$page.props.auth.user.name" 
                                    class="h-10 w-10 rounded-full object-cover"
                                />
                                <div v-else class="h-10 w-10 rounded-full bg-amber-500 flex items-center justify-center text-sm font-bold text-white">
                                    {{ $page.props.auth.user?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p class="truncate">{{ $page.props.auth.user?.name }}</p>
                                    <p class="text-xs text-slate-400 truncate">{{ $page.props.auth.user?.email }}</p>
                                </div>
                                <Icons name="chevron-up" :class="['h-5 w-5 text-slate-400 transition-transform', showUserMenu ? '' : 'rotate-180']" />
                            </button>

                            <!-- User dropdown menu -->
                            <div 
                                v-if="showUserMenu" 
                                class="absolute bottom-full left-0 right-0 mb-2 bg-slate-800 rounded-lg shadow-xl border border-slate-700 overflow-hidden"
                            >
                                <div class="py-1">
                                    <template v-for="item in userMenuItems" :key="item.name">
                                        <button
                                            v-if="canAccess(item)"
                                            @click="handleUserMenuAction(item)"
                                            class="w-full flex items-center gap-3 px-4 py-2 text-sm text-slate-300 hover:bg-slate-700 hover:text-white transition-colors"
                                        >
                                            <Icons :name="item.icon" class="h-4 w-4" />
                                            {{ item.name }}
                                        </button>
                                    </template>
                                    <hr class="my-1 border-slate-700" />
                                    <button 
                                        @click="logout" 
                                        class="w-full flex items-center gap-3 px-4 py-2 text-sm text-red-400 hover:bg-slate-700 hover:text-red-300 transition-colors"
                                    >
                                        <Icons name="logout" class="h-4 w-4" />
                                        Wyloguj się
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- Mobile header -->
        <div class="sticky z-40 flex items-center gap-x-6 bg-slate-900 dark:bg-slate-950 px-4 py-4 shadow-sm sm:px-6 lg:hidden" :class="isTestEnv ? 'top-12' : 'top-0'">
            <button type="button" class="-m-2.5 p-2.5 text-slate-400" @click="showMobileMenu = true">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <div class="flex-1 text-sm font-semibold leading-6 text-white">
                <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-6 w-auto inline" />
                <template v-else>{{ appName }}</template>
            </div>
        </div>

        <!-- Mobile menu -->
        <div v-if="showMobileMenu" class="relative z-50 lg:hidden">
            <div class="fixed inset-0 bg-slate-900/80" @click="showMobileMenu = false"></div>
            <div class="fixed inset-0 flex">
                <div class="relative mr-16 flex w-full max-w-xs flex-1">
                    <div class="absolute left-full top-0 flex w-16 justify-center pt-5">
                        <button type="button" class="-m-2.5 p-2.5" @click="showMobileMenu = false">
                            <Icons name="close" class="h-6 w-6 text-white" />
                        </button>
                    </div>
                    <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-slate-900 px-6 pb-4">
                        <div class="flex h-16 shrink-0 items-center">
                            <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-8 w-auto" />
                            <span v-else class="text-xl font-bold text-white">{{ appName }}</span>
                        </div>
                        <nav class="flex flex-1 flex-col">
                            <ul role="list" class="flex flex-1 flex-col gap-y-7">
                                <li>
                                    <ul role="list" class="-mx-2 space-y-1">
                                        <li v-for="item in navigation" :key="item.name">
                                            <Link
                                                :href="route(item.route)"
                                                :class="[
                                                    isActive(item) ? 'bg-amber-500 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold'
                                                ]"
                                                @click="showMobileMenu = false"
                                            >
                                                <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                                {{ item.name }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li v-if="moduleNavigation.length > 0">
                                    <div class="text-xs font-semibold leading-6 text-slate-500 uppercase">Moduły</div>
                                    <ul role="list" class="-mx-2 mt-2 space-y-1">
                                        <li v-for="item in moduleNavigation" :key="item.name">
                                            <Link
                                                :href="route(item.route)"
                                                :class="[
                                                    isActive(item) ? 'bg-amber-500 text-white' : 'text-slate-300 hover:text-white hover:bg-slate-800',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold'
                                                ]"
                                                @click="showMobileMenu = false"
                                            >
                                                <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                                <span class="flex-1">{{ item.name }}</span>
                                                <span
                                                    v-if="item.route === 'email.inbox.index' && inboxUnreadCount > 0"
                                                    class="min-w-[1.25rem] h-5 px-1.5 flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold"
                                                >
                                                    {{ inboxUnreadCount > 99 ? '99+' : inboxUnreadCount }}
                                                </span>
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                                <li v-if="adminNavigation.some(item => canAccess(item))">
                                    <div class="text-xs font-semibold leading-6 text-slate-500 uppercase">Administracja</div>
                                    <ul role="list" class="-mx-2 mt-2 space-y-1">
                                        <li v-for="item in adminNavigation" :key="item.name">
                                            <Link
                                                v-if="canAccess(item)"
                                                :href="route(item.route)"
                                                :class="[
                                                    isActive(item) ? 'bg-amber-500 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm leading-6 font-semibold'
                                                ]"
                                                @click="showMobileMenu = false"
                                            >
                                                <Icons :name="item.icon" class="h-6 w-6 shrink-0" />
                                                {{ item.name }}
                                            </Link>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- Click outside to close user menu -->
        <div v-if="showUserMenu" class="fixed inset-0 z-40" @click="showUserMenu = false"></div>

        <!-- Main content -->
        <main class="lg:pl-64" :class="{ 'pt-12': isTestEnv }">
            <div class="px-4 py-8 sm:px-6 lg:px-8">
                <Breadcrumbs />
                <FlashMessages />
                <slot />
            </div>
        </main>
    </div>

    <!-- Zminimalizowane wizyty – mobile (sidebar ukryty) -->
    <div id="mobile-minimized-visits" class="fixed bottom-20 left-4 right-4 z-50 max-h-40 overflow-y-auto flex flex-col gap-1 lg:hidden"></div>

    <KeyboardShortcutsHelp :show="showHelp" :shortcuts="shortcuts" @close="showHelp = false" />
    <FloatingVisitPanel
        :show-search="showFloatingVisitSearch"
        @update:show-search="showFloatingVisitSearch = $event"
    />
    <HiddenGamesPanel />
</template>
