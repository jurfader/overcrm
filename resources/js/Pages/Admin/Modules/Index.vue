<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

defineProps({
    modules: Array,
});

const showInstallModal = ref(false);
const showGenerateModal = ref(false);

const installForm = useForm({
    module_file: null,
});

const generateForm = useForm({
    name: '',
    display_name: '',
    description: '',
    icon: 'puzzle',
});

function toggleModule(module) {
    if (module.is_active) {
        router.post(route('admin.modules.deactivate', module.id));
    } else {
        router.post(route('admin.modules.activate', module.id));
    }
}

function uploadModule() {
    installForm.post(route('admin.modules.install'), {
        forceFormData: true,
        onSuccess: () => {
            showInstallModal.value = false;
            installForm.reset();
        },
    });
}

function createModule() {
    generateForm.post(route('admin.modules.generate'), {
        onSuccess: () => {
            showGenerateModal.value = false;
            generateForm.reset();
        },
    });
}

const iconOptions = [
    'puzzle', 'users', 'clients', 'tasks', 'calendar', 'chart-bar', 
    'document-text', 'shopping-cart', 'mail', 'settings', 'globe', 'building-office'
];
</script>

<template>
    <Head title="Zarządzanie modułami" />
    
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Zarządzanie modułami</h1>
                <p class="text-gray-500">Instaluj, konfiguruj i zarządzaj modułami systemu</p>
            </div>
            <div class="flex gap-3">
                <button 
                    @click="showGenerateModal = true"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 font-medium text-gray-700"
                >
                    <Icons name="plus" class="w-5 h-5" />
                    Utwórz moduł
                </button>
                <button 
                    @click="showInstallModal = true"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium"
                >
                    <Icons name="document-arrow-down" class="w-5 h-5" />
                    Zainstaluj z pliku
                </button>
            </div>
        </div>

        <!-- Installed Modules -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div 
                v-for="module in modules" 
                :key="module.id"
                class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden"
            >
                <div class="p-6">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div :class="[
                                'p-3 rounded-xl',
                                module.is_active ? 'bg-indigo-100' : 'bg-gray-100'
                            ]">
                                <Icons :name="module.icon || 'puzzle'" :class="[
                                    'w-6 h-6',
                                    module.is_active ? 'text-indigo-600' : 'text-gray-400'
                                ]" />
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">{{ module.display_name }}</h3>
                                <p class="text-xs text-gray-500">v{{ module.version }}</p>
                            </div>
                        </div>
                        
                        <!-- Toggle Switch -->
                        <button 
                            @click="toggleModule(module)"
                            :disabled="module.is_core"
                            :class="[
                                'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2',
                                module.is_active ? 'bg-indigo-600' : 'bg-gray-200',
                                module.is_core ? 'opacity-50 cursor-not-allowed' : ''
                            ]"
                        >
                            <span :class="[
                                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                module.is_active ? 'translate-x-5' : 'translate-x-0'
                            ]" />
                        </button>
                    </div>
                    
                    <p class="mt-3 text-sm text-gray-600 line-clamp-2">
                        {{ module.description || 'Brak opisu' }}
                    </p>
                    
                    <div class="mt-4 flex items-center gap-2">
                        <span v-if="module.is_core" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            Systemowy
                        </span>
                        <span v-if="module.is_active" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            Aktywny
                        </span>
                        <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                            Nieaktywny
                        </span>
                    </div>
                </div>
                
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                    <span class="text-xs text-gray-500">{{ module.author || 'Nieznany autor' }}</span>
                    <div class="flex gap-2">
                        <Link 
                            v-if="module.has_settings"
                            :href="route('admin.modules.show', module.id)"
                            class="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
                        >
                            Konfiguracja
                        </Link>
                    </div>
                </div>
            </div>

            <div v-if="modules.length === 0" class="col-span-full text-center py-12 text-gray-500">
                Brak zainstalowanych modułów
            </div>
        </div>

    </div>

    <!-- Install Modal -->
    <div v-if="showInstallModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
            <div class="flex items-center justify-between px-6 py-4 border-b">
                <h3 class="text-lg font-semibold">Zainstaluj moduł z pliku</h3>
                <button @click="showInstallModal = false" class="p-1 rounded-lg hover:bg-gray-100">
                    <Icons name="close" class="w-5 h-5 text-gray-500" />
                </button>
            </div>
            
            <form @submit.prevent="uploadModule" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Plik modułu (.zip)</label>
                    <input 
                        type="file" 
                        @change="e => installForm.module_file = e.target.files[0]"
                        accept=".zip"
                        class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                    />
                    <p v-if="installForm.errors.module_file" class="text-red-500 text-sm mt-1">
                        {{ installForm.errors.module_file }}
                    </p>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" @click="showInstallModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Anuluj
                    </button>
                    <button type="submit" :disabled="installForm.processing || !installForm.module_file" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                        {{ installForm.processing ? 'Instalowanie...' : 'Zainstaluj' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Generate Module Modal -->
    <div v-if="showGenerateModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4">
            <div class="flex items-center justify-between px-6 py-4 border-b">
                <h3 class="text-lg font-semibold">Utwórz nowy moduł</h3>
                <button @click="showGenerateModal = false" class="p-1 rounded-lg hover:bg-gray-100">
                    <Icons name="close" class="w-5 h-5 text-gray-500" />
                </button>
            </div>
            
            <form @submit.prevent="createModule" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nazwa techniczna *</label>
                    <input 
                        v-model="generateForm.name"
                        type="text"
                        placeholder="np. crm, invoices, reports"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                        required
                    />
                    <p class="text-xs text-gray-500 mt-1">Tylko małe litery, bez spacji</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nazwa wyświetlana *</label>
                    <input 
                        v-model="generateForm.display_name"
                        type="text"
                        placeholder="np. CRM, Faktury, Raporty"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                        required
                    />
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Opis</label>
                    <textarea 
                        v-model="generateForm.description"
                        rows="2"
                        placeholder="Krótki opis funkcjonalności modułu"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary"
                    ></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Ikona</label>
                    <div class="flex flex-wrap gap-2">
                        <button 
                            v-for="icon in iconOptions"
                            :key="icon"
                            type="button"
                            @click="generateForm.icon = icon"
                            :class="[
                                'p-2 rounded-lg border-2 transition-colors',
                                generateForm.icon === icon 
                                    ? 'border-indigo-500 bg-indigo-50' 
                                    : 'border-gray-200 hover:border-gray-300'
                            ]"
                        >
                            <Icons :name="icon" class="w-5 h-5" />
                        </button>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" @click="showGenerateModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Anuluj
                    </button>
                    <button type="submit" :disabled="generateForm.processing" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                        {{ generateForm.processing ? 'Tworzenie...' : 'Utwórz moduł' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
