<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';
import Icons from '@/Components/Icons.vue';
import KeyboardShortcutsHelp from '@/Components/KeyboardShortcutsHelp.vue';
import FloatingVisitPanel from '@/Components/FloatingVisitPanel.vue';
import SupportTicketModal from '@/Components/SupportTicketModal.vue';
import ThemeToggle from '@/Components/UI/ThemeToggle.vue';
import Tooltip from '@/Components/UI/Tooltip.vue';
import BrandLogo from '@/Components/UI/BrandLogo.vue';
import { useKeyboardShortcuts } from '@/Composables/useKeyboardShortcuts';

const page = usePage();

// ====================== STAN UI ======================
const SIDEBAR_KEY = 'overcrm-sidebar-collapsed';
const sidebarCollapsed = ref(false);
const showUserMenu = ref(false);
const showFloatingVisitSearch = ref(false);

onMounted(() => {
    try { sidebarCollapsed.value = localStorage.getItem(SIDEBAR_KEY) === '1'; } catch {}
});

function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    try { localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? '1' : '0'); } catch {}
}

// Zamknij user menu po kliknięciu poza
function handleClickOutside(e) {
    if (!e.target.closest('[data-user-menu]')) showUserMenu.value = false;
}
onMounted(() => document.addEventListener('click', handleClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', handleClickOutside));

// ====================== POWIADOMIENIE O DEPLOYU ======================
const knownBuildVersion = ref(null);
const showDeployNotification = ref(false);
watch(() => page.props.buildVersion, (newVersion) => {
    if (newVersion && knownBuildVersion.value !== null && knownBuildVersion.value !== newVersion) {
        showDeployNotification.value = true;
    }
    if (newVersion && knownBuildVersion.value === null) knownBuildVersion.value = newVersion;
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
        } catch {}
    }, 60000);
});
onBeforeUnmount(() => { if (buildVersionPollInterval) clearInterval(buildVersionPollInterval); });
function hardRefresh() { window.location.reload(); }

// ====================== KEYBOARD SHORTCUTS ======================
const { showHelp, shortcuts } = useKeyboardShortcuts({
    onQuickOpenVisit: () => { showFloatingVisitSearch.value = true; },
});

// ====================== USER + BRAND ======================
const currentUser = computed(() => page.props.auth?.user);
const userRole = computed(() => currentUser.value?.role || 'user');
const userInitials = computed(() => {
    const name = currentUser.value?.name || '?';
    return name.split(' ').slice(0, 2).map(s => s[0] || '').join('').toUpperCase();
});
const inboxUnreadCount = computed(() => page.props.inboxUnreadCount ?? 0);
const showSupportModal = ref(false);
const environmentBanner = computed(() => page.props.environmentBanner || '');
const activeModules = computed(() => page.props.activeModules || []);

// Graceful — nie crashuj UI gdy moduł Email nie jest aktywny (route nie istnieje w Ziggy)
const hasInboxRoute = computed(() => {
    try { route('email.inbox.index'); return true; } catch { return false; }
});

function logout() { router.post(route('logout')); }

// ====================== NAWIGACJA ======================
const navMain = [
    { name: 'Dashboard',  route: 'dashboard',          icon: 'dashboard' },
    { name: 'Kalendarz',  route: 'calendar.index',     icon: 'calendar', pattern: 'calendar.*' },
    { name: 'Zadania',    route: 'tasks.index',        icon: 'tasks',    pattern: 'tasks.*' },
    { name: 'Klienci',    route: 'clients.index',      icon: 'clients',  pattern: 'clients.*' },
    { name: 'Cenniki',    route: 'price-lists.index',  icon: 'price-list', pattern: 'price-lists.*' },
    { name: 'Changelog',  route: 'changelog.index',    icon: 'document-text', pattern: 'changelog.*' },
];

const navModules = computed(() =>
    activeModules.value
        .filter(mod => mod.menu && mod.menu.length > 0)
        .flatMap(mod => mod.menu.map(m => ({
            name: m.label, route: m.route,
            icon: m.icon || mod.icon || 'puzzle',
            pattern: mod.name + '.*',
        })))
);

const navAdmin = [
    { name: 'Użytkownicy',     route: 'users.index',                  icon: 'users',         pattern: 'users.*',                  roles: ['admin', 'manager'] },
    { name: 'Statusy',         route: 'statuses.index',               icon: 'statuses',      pattern: 'statuses.*',               roles: ['admin'] },
    { name: 'Szablony Email',  route: 'admin.email-templates.index',  icon: 'mail',          pattern: 'admin.email-templates.*',  roles: ['admin'] },
    { name: 'Raport dzienny',  route: 'admin.daily-report',           icon: 'document-text', pattern: 'admin.daily-report',       roles: ['admin'] },
    { name: 'Logi integracji', route: 'admin.integration-logs',       icon: 'activity',      pattern: 'admin.integration-logs',   roles: ['admin'] },
    { name: 'Moduły',          route: 'admin.modules.index',          icon: 'puzzle',        pattern: 'admin.modules.*',          roles: ['admin'] },
    { name: 'Cenniki',         route: 'admin.price-lists.index',      icon: 'price-list',    pattern: 'admin.price-lists.*',      roles: ['admin'] },
    { name: 'Ustawienia',      route: 'admin.settings.index',         icon: 'settings',      pattern: 'admin.settings.*',         roles: ['admin'] },
];

const userMenuItems = [
    { name: 'Mój profil',         action: 'profile' },
    { name: 'Serwer pocztowy',    action: 'settings' },
    { name: 'Zabezpieczenia (2FA)', action: '2fa' },
];

function isActive(item) {
    if (item.pattern) return route().current(item.pattern);
    return route().current(item.route);
}
function canAccess(item) {
    if (!item.roles) return true;
    return item.roles.includes(userRole.value);
}
function handleUserMenuAction(item) {
    showUserMenu.value = false;
    if (item.action === 'settings') router.visit(route('settings.mail.index'));
    else if (item.action === '2fa') router.visit(route('two-factor.setup'));
    else if (item.action === 'profile') alert('Profil — wkrótce');
}

const visibleAdmin = computed(() => navAdmin.filter(canAccess));
</script>

<template>
    <div class="min-h-screen flex">
        <!-- ====================== SIDEBAR ====================== -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 glass border-r border-border flex flex-col',
                'transition-all duration-300 ease-out',
                sidebarCollapsed ? 'w-sidebar-collapsed' : 'w-sidebar',
            ]"
        >
            <!-- Logo -->
            <div class="h-topbar shrink-0 flex items-center justify-center px-3 border-b border-border">
                <Link :href="route('dashboard')" class="block">
                    <BrandLogo :show-name="!sidebarCollapsed" :size="sidebarCollapsed ? 'sm' : 'md'" />
                </Link>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-6">
                <!-- Główne -->
                <div>
                    <p v-if="!sidebarCollapsed" class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-foreground-subtle">
                        Główne
                    </p>
                    <Tooltip v-for="item in navMain" :key="item.route" :content="sidebarCollapsed ? item.name : ''" placement="right" class="block w-full">
                        <Link
                            :href="route(item.route)"
                            :class="[
                                'group relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors w-full',
                                isActive(item)
                                    ? 'gradient-subtle text-brand-primary font-medium'
                                    : 'text-foreground-muted hover:text-foreground hover:bg-surface-elevated',
                                sidebarCollapsed && 'justify-center',
                            ]"
                        >
                            <span v-if="isActive(item)" class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-5 rounded-r gradient-brand" />
                            <Icons :name="item.icon" class="w-4 h-4 shrink-0" />
                            <span v-if="!sidebarCollapsed" class="truncate">{{ item.name }}</span>
                        </Link>
                    </Tooltip>
                </div>

                <!-- Moduły -->
                <div v-if="navModules.length">
                    <p v-if="!sidebarCollapsed" class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-foreground-subtle">
                        Moduły
                    </p>
                    <Tooltip v-for="item in navModules" :key="item.route" :content="sidebarCollapsed ? item.name : ''" placement="right" class="block w-full">
                        <Link
                            :href="route(item.route)"
                            :class="[
                                'group relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors w-full',
                                isActive(item)
                                    ? 'gradient-subtle text-brand-primary font-medium'
                                    : 'text-foreground-muted hover:text-foreground hover:bg-surface-elevated',
                                sidebarCollapsed && 'justify-center',
                            ]"
                        >
                            <span v-if="isActive(item)" class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-5 rounded-r gradient-brand" />
                            <Icons :name="item.icon" class="w-4 h-4 shrink-0" />
                            <span v-if="!sidebarCollapsed" class="truncate">{{ item.name }}</span>
                        </Link>
                    </Tooltip>
                </div>

                <!-- Admin -->
                <div v-if="visibleAdmin.length">
                    <p v-if="!sidebarCollapsed" class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-foreground-subtle">
                        Administracja
                    </p>
                    <Tooltip v-for="item in visibleAdmin" :key="item.route" :content="sidebarCollapsed ? item.name : ''" placement="right" class="block w-full">
                        <Link
                            :href="route(item.route)"
                            :class="[
                                'group relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors w-full',
                                isActive(item)
                                    ? 'gradient-subtle text-brand-primary font-medium'
                                    : 'text-foreground-muted hover:text-foreground hover:bg-surface-elevated',
                                sidebarCollapsed && 'justify-center',
                            ]"
                        >
                            <span v-if="isActive(item)" class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-5 rounded-r gradient-brand" />
                            <Icons :name="item.icon" class="w-4 h-4 shrink-0" />
                            <span v-if="!sidebarCollapsed" class="truncate">{{ item.name }}</span>
                        </Link>
                    </Tooltip>
                </div>
            </nav>

            <!-- Footer sidebar: collapse toggle + version -->
            <div class="border-t border-border p-2 flex items-center" :class="sidebarCollapsed ? 'justify-center' : 'justify-between gap-2'">
                <button
                    @click="toggleSidebar"
                    class="h-9 w-9 inline-flex items-center justify-center rounded-md text-foreground-muted hover:text-foreground hover:bg-surface-elevated transition-colors"
                    :title="sidebarCollapsed ? 'Rozwiń menu' : 'Zwiń menu'"
                >
                    <Icons :name="sidebarCollapsed ? 'chevron-right' : 'chevron-left'" class="w-4 h-4" />
                </button>
                <span v-if="!sidebarCollapsed" class="text-[10px] font-mono text-foreground-subtle">{{ page.props.buildVersion || 'dev' }}</span>
            </div>
        </aside>

        <!-- ====================== MAIN COLUMN ====================== -->
        <div :class="['flex-1 flex flex-col min-w-0 transition-all duration-300', sidebarCollapsed ? 'pl-sidebar-collapsed' : 'pl-sidebar']">
            <!-- ====================== TOPBAR ====================== -->
            <header class="sticky top-0 z-30 h-topbar glass border-b border-border flex items-center px-4 gap-3">
                <!-- Banner środowiska (np. „STAGING") -->
                <div v-if="environmentBanner" class="px-3 py-1 rounded-md text-xs font-semibold gradient-subtle text-brand-primary border border-brand-primary/30">
                    {{ environmentBanner }}
                </div>

                <div class="flex-1" />

                <!-- Inbox — pokazuje się tylko gdy moduł Email jest aktywny i route istnieje -->
                <Link
                    v-if="currentUser && hasInboxRoute"
                    :href="route('email.inbox.index')"
                    class="relative h-9 w-9 inline-flex items-center justify-center rounded-md text-foreground-muted hover:text-foreground hover:bg-surface-elevated transition-colors"
                    title="Skrzynka odbiorcza"
                >
                    <Icons name="mail" class="w-4 h-4" />
                    <span v-if="inboxUnreadCount > 0" class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full gradient-brand text-white text-[10px] font-bold flex items-center justify-center">
                        {{ inboxUnreadCount > 99 ? '99+' : inboxUnreadCount }}
                    </span>
                </Link>

                <!-- Zgłoś problem -->
                <button
                    type="button"
                    @click="showSupportModal = true"
                    class="h-9 w-9 inline-flex items-center justify-center rounded-md text-foreground-muted hover:text-foreground hover:bg-surface-elevated transition-colors"
                    title="Zgłoś problem do supportu"
                >
                    <Icons name="info" class="w-4 h-4" />
                </button>

                <!-- Theme toggle -->
                <ThemeToggle />

                <!-- Separator -->
                <div class="w-px h-5 bg-border-bright" />

                <!-- User menu -->
                <div v-if="currentUser" class="relative" data-user-menu>
                    <button
                        @click.stop="showUserMenu = !showUserMenu"
                        class="h-9 inline-flex items-center gap-2.5 pr-2 pl-1 rounded-md hover:bg-surface-elevated transition-colors"
                    >
                        <span class="w-7 h-7 rounded-full gradient-brand flex items-center justify-center text-white text-xs font-bold">
                            {{ userInitials }}
                        </span>
                        <span class="text-sm text-foreground hidden sm:inline">{{ currentUser.name }}</span>
                        <Icons name="chevron-down" class="w-3 h-3 text-foreground-muted hidden sm:inline" />
                    </button>
                    <Transition
                        enter-active-class="transition duration-100 ease-out"
                        enter-from-class="opacity-0 scale-95"
                        enter-to-class="opacity-100 scale-100"
                        leave-active-class="transition duration-75 ease-in"
                        leave-from-class="opacity-100 scale-100"
                        leave-to-class="opacity-0 scale-95"
                    >
                        <div
                            v-if="showUserMenu"
                            class="absolute right-0 mt-1 w-56 origin-top-right glass-card rounded-md shadow-lg overflow-hidden"
                        >
                            <div class="px-3 py-2.5 border-b border-border">
                                <p class="text-sm font-medium text-foreground truncate">{{ currentUser.name }}</p>
                                <p class="text-xs text-foreground-muted truncate">{{ currentUser.email }}</p>
                            </div>
                            <div class="py-1">
                                <button
                                    v-for="item in userMenuItems" :key="item.action"
                                    @click="handleUserMenuAction(item)"
                                    class="w-full text-left px-3 py-2 text-sm text-foreground hover:bg-surface-elevated transition-colors"
                                >
                                    {{ item.name }}
                                </button>
                            </div>
                            <div class="border-t border-border py-1">
                                <button
                                    @click="logout"
                                    class="w-full text-left px-3 py-2 text-sm text-destructive hover:bg-destructive/10 transition-colors"
                                >
                                    Wyloguj
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </header>

            <!-- ====================== CONTENT ====================== -->
            <main class="flex-1 relative p-4 sm:p-6">
                <FlashMessages />
                <slot />
            </main>
        </div>

        <!-- Powiadomienie o nowym deployu -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-2"
        >
            <div
                v-if="showDeployNotification"
                class="fixed bottom-4 right-4 z-50 glass-card rounded-lg p-4 max-w-sm flex items-start gap-3"
            >
                <Icons name="refresh" class="w-5 h-5 text-brand-primary shrink-0 mt-0.5" />
                <div class="flex-1">
                    <p class="text-sm font-medium text-foreground">Nowa wersja aplikacji jest dostępna</p>
                    <p class="text-xs text-foreground-muted mt-0.5">Odśwież stronę, żeby załadować zmiany.</p>
                    <div class="mt-3 flex gap-2">
                        <button @click="hardRefresh" class="px-3 py-1.5 rounded-md text-xs font-medium gradient-brand text-white">
                            Odśwież teraz
                        </button>
                        <button @click="showDeployNotification = false" class="px-3 py-1.5 rounded-md text-xs font-medium text-foreground-muted hover:text-foreground">
                            Później
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <KeyboardShortcutsHelp v-model="showHelp" :shortcuts="shortcuts" />
        <FloatingVisitPanel v-model="showFloatingVisitSearch" />
        <SupportTicketModal v-model="showSupportModal" />
    </div>
</template>
