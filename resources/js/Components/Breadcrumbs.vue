<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const page = usePage();

// Mapowanie route → breadcrumb
const routeMap = {
    'dashboard': [
        { label: 'Dashboard' },
    ],
    'calendar.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Kalendarz' },
    ],
    'tasks.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania' },
    ],
    'kanban.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania', route: 'tasks.index' },
        { label: 'Kanban' },
    ],
    'timeline.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania', route: 'tasks.index' },
        { label: 'Timeline' },
    ],
    'tasks.create': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania', route: 'tasks.index' },
        { label: 'Nowe zadanie' },
    ],
    'tasks.show': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania', route: 'tasks.index' },
        { label: ':task.title' },
    ],
    'tasks.edit': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Zadania', route: 'tasks.index' },
        { label: ':task.title', route: 'tasks.show' },
        { label: 'Edycja' },
    ],
    'clients.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Klienci' },
    ],
    'clients.create': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Klienci', route: 'clients.index' },
        { label: 'Nowy klient' },
    ],
    'clients.show': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Klienci', route: 'clients.index' },
        { label: ':client.name' },
    ],
    'clients.edit': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Klienci', route: 'clients.index' },
        { label: ':client.name', route: 'clients.show' },
        { label: 'Edycja' },
    ],
    'reports.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Raporty' },
    ],
    'reports.margin': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Raporty', route: 'reports.index' },
        { label: 'Marżowość' },
    ],
    'users.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Użytkownicy' },
    ],
    'users.create': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Użytkownicy', route: 'users.index' },
        { label: 'Nowy użytkownik' },
    ],
    'users.show': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Użytkownicy', route: 'users.index' },
        { label: ':user.name' },
    ],
    'users.edit': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Użytkownicy', route: 'users.index' },
        { label: ':user.name', route: 'users.show' },
        { label: 'Edycja' },
    ],
    'statuses.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Statusy' },
    ],
    'statuses.create': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Statusy', route: 'statuses.index' },
        { label: 'Nowy status' },
    ],
    'statuses.edit': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Statusy', route: 'statuses.index' },
        { label: 'Edycja' },
    ],
    'admin.daily-report': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Raport dzienny' },
    ],
    'admin.integration-logs': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Logi integracji' },
    ],
    'settings.mail.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Serwer pocztowy' },
    ],
    'admin.settings.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Ustawienia' },
    ],
    'admin.modules.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Moduły' },
    ],
    'admin.modules.show': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Moduły', route: 'admin.modules.index' },
        { label: ':module.display_name' },
    ],
    'admin.email-templates.index': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Szablony Email' },
    ],
    'admin.email-templates.create': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Szablony Email', route: 'admin.email-templates.index' },
        { label: 'Nowy szablon' },
    ],
    'admin.email-templates.edit': [
        { label: 'Dashboard', route: 'dashboard' },
        { label: 'Szablony Email', route: 'admin.email-templates.index' },
        { label: 'Edycja szablonu' },
    ],
};

// Rozwiąż dynamiczną etykietę typu ":task.title" z props strony
function resolveLabel(label) {
    if (!label.startsWith(':')) return label;

    const path = label.substring(1).split('.');
    let value = page.props;
    for (const key of path) {
        value = value?.[key];
    }
    return value || '...';
}

// Rozwiąż URL dla breadcrumba z parametrem (np. tasks.show potrzebuje ID)
function resolveHref(item) {
    if (!item.route) return null;

    try {
        // Próbuj bez parametrów
        return route(item.route);
    } catch {
        // Jeśli route wymaga parametru, spróbuj wyciągnąć z props
        try {
            const props = page.props;
            if (item.route.includes('tasks.')) return route(item.route, props.task?.id);
            if (item.route.includes('clients.')) return route(item.route, props.client?.id);
            if (item.route.includes('users.')) return route(item.route, props.user?.id);
            if (item.route.includes('admin.modules.')) return route(item.route, props.module?.id || props.module?.name);
            if (item.route.includes('admin.email-templates.')) return route(item.route, props.emailTemplate?.id);
        } catch {
            return null;
        }
    }
    return null;
}

// Znajdź aktualną route i zbuduj breadcrumbs
const breadcrumbs = computed(() => {
    // Zależność reaktywna — wymusza przeliczenie przy każdej nawigacji Inertia
    const _currentUrl = page.url;

    // Sprawdź każdą zdefiniowaną route
    for (const [routeName, crumbs] of Object.entries(routeMap)) {
        if (route().current(routeName)) {
            return crumbs.map((crumb, index) => ({
                label: resolveLabel(crumb.label),
                href: index < crumbs.length - 1 ? resolveHref(crumb) : null, // Ostatni element bez linku
                isLast: index === crumbs.length - 1,
            }));
        }
    }

    // Fallback — pokaż tylko Dashboard
    return [{ label: 'Dashboard', href: route('dashboard'), isLast: true }];
});
</script>

<template>
    <nav v-if="breadcrumbs.length > 1" class="mb-4" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm">
            <li v-for="(crumb, index) in breadcrumbs" :key="index" class="flex items-center">
                <!-- Separator -->
                <Icons 
                    v-if="index > 0" 
                    name="chevron-right" 
                    class="h-4 w-4 text-slate-400 dark:text-slate-500 mx-1 flex-shrink-0" 
                />

                <!-- Link lub tekst -->
                <Link
                    v-if="crumb.href && !crumb.isLast"
                    :href="crumb.href"
                    class="text-slate-500 hover:text-amber-600 dark:text-slate-400 dark:hover:text-amber-400 transition-colors"
                >
                    <!-- Ikonka domu dla Dashboard -->
                    <span v-if="index === 0" class="flex items-center gap-1">
                        <Icons name="dashboard" class="h-4 w-4" />
                        <span class="hidden sm:inline">{{ crumb.label }}</span>
                    </span>
                    <span v-else>{{ crumb.label }}</span>
                </Link>
                <span
                    v-else
                    class="font-medium"
                    :class="crumb.isLast ? 'text-slate-800 dark:text-slate-200' : 'text-slate-500 dark:text-slate-400'"
                >
                    <span v-if="index === 0" class="flex items-center gap-1">
                        <Icons name="dashboard" class="h-4 w-4" />
                        <span class="hidden sm:inline">{{ crumb.label }}</span>
                    </span>
                    <span v-else>{{ crumb.label }}</span>
                </span>
            </li>
        </ol>
    </nav>
</template>
