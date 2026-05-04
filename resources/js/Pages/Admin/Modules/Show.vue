<script setup>
import { ref, reactive, toRaw } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    module: Object,
    settings: Object,
    logs: Array,
});

// Konfiguracja modułu
const configForm = reactive({});

// Inicjalizuj formularz wartościami z ustawień
for (const [group, groupSettings] of Object.entries(props.settings || {})) {
    for (const setting of groupSettings) {
        configForm[setting.key] = setting.value;
    }
}

function saveConfig() {
    router.post(route('admin.modules.config', props.module.id), {
        settings: JSON.parse(JSON.stringify(toRaw(configForm))),
    });
}

function toggleModule() {
    if (props.module.is_active) {
        router.post(route('admin.modules.deactivate', props.module.id));
    } else {
        router.post(route('admin.modules.activate', props.module.id));
    }
}

function uninstallModule() {
    if (confirm('Czy na pewno chcesz odinstalować ten moduł? Wszystkie dane modułu zostaną usunięte.')) {
        router.delete(route('admin.modules.uninstall', props.module.id));
    }
}

const groupLabels = {
    general: 'Ogólne',
    api: 'API',
    integrations: 'Integracje',
    notifications: 'Powiadomienia',
    appearance: 'Wygląd',
    advanced: 'Zaawansowane',
};

const actionColors = {
    installed: 'bg-green-100 text-green-800',
    activated: 'bg-blue-100 text-blue-800',
    deactivated: 'bg-yellow-100 text-yellow-800',
    configured: 'bg-purple-100 text-purple-800',
};
</script>

<template>
    <Head :title="`${module.display_name} - Konfiguracja`" />
    
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <Link :href="route('admin.modules.index')" class="p-2 rounded-lg hover:bg-gray-100">
                    <Icons name="chevron-left" class="w-5 h-5 text-gray-500" />
                </Link>
                <div class="flex items-center gap-4">
                    <div :class="[
                        'p-4 rounded-xl',
                        module.is_active ? 'bg-indigo-100' : 'bg-gray-100'
                    ]">
                        <Icons :name="module.icon || 'puzzle'" :class="[
                            'w-8 h-8',
                            module.is_active ? 'text-indigo-600' : 'text-gray-400'
                        ]" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ module.display_name }}</h1>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-sm text-gray-500">v{{ module.version }}</span>
                            <span class="text-gray-300">·</span>
                            <span class="text-sm text-gray-500">{{ module.author || 'Nieznany autor' }}</span>
                            <span v-if="module.is_core" class="px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                                Systemowy
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button 
                    v-if="!module.is_core"
                    @click="toggleModule"
                    :class="[
                        'px-4 py-2 rounded-lg font-medium transition-colors',
                        module.is_active 
                            ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200' 
                            : 'bg-green-100 text-green-700 hover:bg-green-200'
                    ]"
                >
                    {{ module.is_active ? 'Dezaktywuj' : 'Aktywuj' }}
                </button>
                <button 
                    v-if="!module.is_core"
                    @click="uninstallModule"
                    class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 font-medium"
                >
                    Odinstaluj
                </button>
            </div>
        </div>

        <!-- Description -->
        <div v-if="module.description" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <p class="text-gray-600">{{ module.description }}</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Configuration -->
            <div class="lg:col-span-2 space-y-6">
                <div v-for="(groupSettings, groupName) in settings" :key="groupName" class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            {{ groupLabels[groupName] || groupName }}
                        </h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <div v-for="setting in groupSettings" :key="setting.key" class="space-y-2">
                            <label class="flex items-center justify-between">
                                <div>
                                    <span class="block text-sm font-medium text-gray-700">{{ setting.label }}</span>
                                    <span v-if="setting.description" class="block text-xs text-gray-500">{{ setting.description }}</span>
                                </div>
                                
                                <!-- Boolean toggle -->
                                <button 
                                    v-if="setting.type === 'boolean'"
                                    type="button"
                                    @click="configForm[setting.key] = !configForm[setting.key]"
                                    :class="[
                                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2',
                                        configForm[setting.key] ? 'bg-indigo-600' : 'bg-gray-200'
                                    ]"
                                >
                                    <span :class="[
                                        'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                        configForm[setting.key] ? 'translate-x-5' : 'translate-x-0'
                                    ]" />
                                </button>
                            </label>
                            
                            <!-- Text input -->
                            <input 
                                v-if="setting.type === 'string'"
                                v-model="configForm[setting.key]"
                                type="text"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary dark:bg-slate-700 dark:border-slate-600 dark:text-white"
                            />
                            
                            <!-- Password input -->
                            <input 
                                v-if="setting.type === 'password'"
                                v-model="configForm[setting.key]"
                                type="password"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary dark:bg-slate-700 dark:border-slate-600 dark:text-white"
                            />
                            
                            <!-- Number input -->
                            <input 
                                v-if="setting.type === 'integer'"
                                v-model.number="configForm[setting.key]"
                                type="number"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                            />
                            
                            <!-- Textarea -->
                            <textarea 
                                v-if="setting.type === 'textarea'"
                                v-model="configForm[setting.key]"
                                rows="3"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                            ></textarea>
                            
                            <!-- Select -->
                            <select 
                                v-if="setting.type === 'select'"
                                v-model="configForm[setting.key]"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                            >
                                <option v-for="(label, value) in setting.options" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <button 
                            @click="saveConfig"
                            class="px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90 font-medium"
                        >
                            Zapisz konfigurację
                        </button>
                    </div>
                </div>

                <div v-if="Object.keys(settings).length === 0" class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
                    <Icons name="settings" class="w-12 h-12 text-gray-300 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Brak ustawień</h3>
                    <p class="text-gray-500">Ten moduł nie posiada konfigurowalnych ustawień.</p>
                </div>
            </div>

            <!-- Sidebar - Info & Logs -->
            <div class="space-y-6">
                <!-- Module Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Informacje</h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Status</span>
                            <span :class="[
                                'px-2 py-1 text-xs font-medium rounded-full',
                                module.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                            ]">
                                {{ module.is_active ? 'Aktywny' : 'Nieaktywny' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Wersja</span>
                            <span class="font-medium">{{ module.version }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Autor</span>
                            <span class="font-medium">{{ module.author || '-' }}</span>
                        </div>
                        <div v-if="module.dependencies?.length" class="pt-2 border-t">
                            <span class="text-sm text-gray-500 block mb-2">Zależności</span>
                            <div class="flex flex-wrap gap-1">
                                <span 
                                    v-for="dep in module.dependencies" 
                                    :key="dep"
                                    class="px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded"
                                >
                                    {{ dep }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Log -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Historia zmian</h2>
                    </div>
                    <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                        <div 
                            v-for="log in logs" 
                            :key="log.id"
                            class="px-6 py-4"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <span :class="['px-2 py-0.5 text-xs font-medium rounded-full', actionColors[log.action] || 'bg-gray-100 text-gray-800']">
                                    {{ log.action }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ new Date(log.created_at).toLocaleDateString('pl-PL') }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-600">
                                {{ log.user?.name || 'System' }}
                            </p>
                        </div>
                        <div v-if="logs.length === 0" class="px-6 py-8 text-center text-gray-500">
                            Brak historii zmian
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
