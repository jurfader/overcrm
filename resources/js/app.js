import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import AppLayout from './Layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'CHICKENKING Planner';

// Strony z głównej aplikacji
const appPages = import.meta.glob('./Pages/**/*.vue');

// Strony z modułów
const modulePages = import.meta.glob('../../modules/*/resources/js/Pages/**/*.vue');

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        let page;
        
        // Sprawdź czy strona istnieje w głównej aplikacji
        const appPath = `./Pages/${name}.vue`;
        if (appPages[appPath]) {
            page = await resolvePageComponent(appPath, appPages);
        } else {
            // Szukaj w modułach - nazwa strony to np. "Reports/Index"
            const [moduleName, ...rest] = name.split('/');
            const componentPath = rest.join('/') || 'Index';
            const modulePath = `../../modules/${moduleName}/resources/js/Pages/${componentPath}.vue`;
            
            if (modulePages[modulePath]) {
                page = await modulePages[modulePath]();
            } else {
                throw new Error(`Page not found: ${name}`);
            }
        }
        
        // Apply AppLayout to all pages except Auth pages and Welcome
        if (!name.startsWith('Auth/') && name !== 'Welcome') {
            page.default.layout = page.default.layout || AppLayout;
        }
        
        return page;
    },
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#dc2626',
    },
});
