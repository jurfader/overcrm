import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import fs from 'fs';

// Znajdź wszystkie moduły
function getModulePagePaths() {
    const modulesPath = path.resolve(__dirname, 'modules');
    const modules = [];
    
    if (fs.existsSync(modulesPath)) {
        fs.readdirSync(modulesPath).forEach(moduleName => {
            const pagesPath = path.join(modulesPath, moduleName, 'resources/js/Pages');
            if (fs.existsSync(pagesPath)) {
                modules.push({
                    name: moduleName,
                    path: pagesPath,
                });
            }
        });
    }
    
    return modules;
}

const modulePages = getModulePagePaths();

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: [
                'resources/js/**',
                'modules/*/resources/js/**',
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            // Dynamiczne aliasy dla modułów
            ...modulePages.reduce((acc, mod) => {
                acc[`@modules/${mod.name}`] = mod.path;
                return acc;
            }, {}),
        },
    },
});
