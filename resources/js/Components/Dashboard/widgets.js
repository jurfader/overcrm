import KpiTiles from './Widgets/KpiTiles.vue';
import TasksToday from './Widgets/TasksToday.vue';
import TasksOverdue from './Widgets/TasksOverdue.vue';
import RecentClients from './Widgets/RecentClients.vue';
import VenueBirthdays from './Widgets/VenueBirthdays.vue';
import UpcomingVisits from './Widgets/UpcomingVisits.vue';

/**
 * Frontend widget registry.
 *
 * Mapping: backend Widget.component (string) → faktyczny Vue component.
 *
 * Moduły dorzucają swoje widgety do tego rejestru przez `registerWidget()`
 * w swoim entry pointcie (np. modules/Fakturownia/resources/js/index.js).
 */
const registry = {
    KpiTiles,
    TasksToday,
    TasksOverdue,
    RecentClients,
    VenueBirthdays,
    UpcomingVisits,
};

export function registerWidget(name, component) {
    registry[name] = component;
}

export function getWidget(name) {
    return registry[name] || null;
}

export function listWidgets() {
    return Object.keys(registry);
}
