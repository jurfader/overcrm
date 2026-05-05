import { defineAsyncComponent } from 'vue';

import KpiTiles from './Widgets/KpiTiles.vue';
import TasksToday from './Widgets/TasksToday.vue';
import TasksOverdue from './Widgets/TasksOverdue.vue';
import RecentClients from './Widgets/RecentClients.vue';
import VenueBirthdays from './Widgets/VenueBirthdays.vue';
import UpcomingVisits from './Widgets/UpcomingVisits.vue';

/**
 * Frontend widget registry.
 *
 * Mapping: backend Widget.component (string) → Vue component.
 *
 * Core widgety = static imports (zawsze obecne).
 * Module widgety = lazy z `modules/* /resources/js/Widgets/*.vue` przez import.meta.glob.
 *   Klucz w mapie = nazwa pliku bez rozszerzenia (np. DailyReportMyActivity).
 *   Match z backend: WidgetRegistry::register(component: 'DailyReportMyActivity').
 *
 * Moduł nie musi wywoływać żadnego API — wystarczy że wrzuci .vue do
 * modules/{ModuleName}/resources/js/Widgets/. Vite zbuduje, glob wykryje.
 */
const coreRegistry = {
    KpiTiles,
    TasksToday,
    TasksOverdue,
    RecentClients,
    VenueBirthdays,
    UpcomingVisits,
};

// Lazy-loaded widgety modułów. import.meta.glob skanuje przy buildzie Vite.
const moduleWidgetGlob = import.meta.glob('../../../../modules/*/resources/js/Widgets/*.vue');
const moduleRegistry = {};
for (const path in moduleWidgetGlob) {
    // path = '../../../../modules/DailyReport/resources/js/Widgets/DailyReportMyActivity.vue'
    const match = path.match(/\/Widgets\/([^/]+)\.vue$/);
    if (match) {
        moduleRegistry[match[1]] = defineAsyncComponent(moduleWidgetGlob[path]);
    }
}

const registry = { ...coreRegistry, ...moduleRegistry };

export function registerWidget(name, component) {
    registry[name] = component;
}

export function getWidget(name) {
    return registry[name] || null;
}

export function listWidgets() {
    return Object.keys(registry);
}
