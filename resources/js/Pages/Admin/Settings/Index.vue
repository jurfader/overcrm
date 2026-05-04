<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const page = usePage();
const hasRingostat = computed(() => {
    const modules = page.props.activeModules || [];
    return modules.some(m => m.name === 'ringostat');
});

const props = defineProps({
    settings: Object,
    groups: Object,
});

const activeGroup = ref(Object.keys(props.groups)[0] || 'general');
const saving = ref(false);

// Inicjalizuj formularz wartościami
const form = reactive({});

for (const [group, groupSettings] of Object.entries(props.settings || {})) {
    for (const setting of groupSettings) {
        form[setting.key] = setting.value;
    }
}

function saveSettings() {
    saving.value = true;
    router.post(route('admin.settings.update'), {
        settings: form,
    }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
        },
    });
}

function getGroupSettings(groupName) {
    return props.settings[groupName] || [];
}

// Obsługa uploadu logo
const logoInput = ref(null);
const logoPreview = ref(form.app_logo || null);

function triggerLogoUpload() {
    logoInput.value?.click();
}

function handleLogoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Preview
    const reader = new FileReader();
    reader.onload = (e) => {
        logoPreview.value = e.target.result;
    };
    reader.readAsDataURL(file);

    // Upload file
    const formData = new FormData();
    formData.append('logo', file);

    router.post(route('admin.settings.upload-logo'), formData, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.success) {
                // Logo zostało zapisane
            }
        },
    });
}

function removeLogo() {
    logoPreview.value = null;
    form.app_logo = null;
}

// Test integracji Fakturownia
const testingFakturownia = ref(false);

function testFakturownia() {
    testingFakturownia.value = true;
    router.post(route('admin.settings.test-fakturownia'), {}, {
        preserveScroll: true,
        onFinish: () => {
            testingFakturownia.value = false;
        },
    });
}

// Test integracji Apilo
const testingApilo = ref(false);

function testApilo() {
    testingApilo.value = true;
    router.post(route('admin.settings.test-apilo'), {}, {
        preserveScroll: true,
        onFinish: () => {
            testingApilo.value = false;
        },
    });
}

// Autoryzacja Apilo kodem autoryzacyjnym
const authorizingApilo = ref(false);
const apiloAuthCode = ref('');

function authorizeApilo() {
    if (!apiloAuthCode.value.trim()) {
        alert('Wklej kod autoryzacji z Apilo');
        return;
    }
    authorizingApilo.value = true;
    router.post(route('admin.settings.authorize-apilo'), {
        authorization_code: apiloAuthCode.value.trim(),
    }, {
        preserveScroll: true,
        onFinish: () => {
            authorizingApilo.value = false;
            apiloAuthCode.value = '';
        },
    });
}

// Ringostat test i synchronizacja pracowników
const testingRingostat = ref(false);

function testRingostat() {
    testingRingostat.value = true;

    fetch(route('ringostat.test-connection'), {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        alert(data.success ? data.message : 'Błąd: ' + data.message);
    })
    .catch(err => alert('Błąd: ' + err.message))
    .finally(() => testingRingostat.value = false);
}

const syncingEmployees = ref(false);
const ringostatEmployees = ref([]);
const plannerUsers = ref([]);
const showEmployeeMapping = ref(false);
const employeeMappings = ref({});
const savingMapping = ref(false);
const mappingMessage = ref('');

function syncRingostatEmployees() {
    syncingEmployees.value = true;
    mappingMessage.value = '';

    fetch(route('ringostat.sync-employees'), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const emps = data.employees || {};
            ringostatEmployees.value = Array.isArray(emps) ? emps : Object.values(emps);
            plannerUsers.value = data.users || [];
            showEmployeeMapping.value = true;

            // Inicjalizuj mapowania z istniejących
            const map = {};
            for (const u of data.users) {
                if (u.ringostat_employee_id) {
                    map[u.ringostat_employee_id] = u.id;
                }
            }
            employeeMappings.value = map;
        } else {
            mappingMessage.value = data.message || 'Błąd pobierania pracowników';
        }
    })
    .catch(err => {
        mappingMessage.value = 'Błąd: ' + err.message;
    })
    .finally(() => syncingEmployees.value = false);
}

const rematching = ref(false);
function rematchCalls() {
    rematching.value = true;
    mappingMessage.value = '';
    fetch(route('ringostat.rematch-calls'), {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        mappingMessage.value = data.message || 'Dopasowano';
        setTimeout(() => mappingMessage.value = '', 4000);
    })
    .catch(err => { mappingMessage.value = 'Błąd: ' + err.message; })
    .finally(() => rematching.value = false);
}

function saveEmployeeMapping() {
    savingMapping.value = true;
    const mappings = [];
    for (const [empId, userId] of Object.entries(employeeMappings.value)) {
        if (userId) {
            const emp = ringostatEmployees.value.find(e => String(e.staffId) === String(empId));
            mappings.push({
                user_id: userId,
                ringostat_employee_id: empId,
                ringostat_extension: emp?.extensionNumber ?? null,
            });
        }
    }

    fetch(route('ringostat.save-employee-mapping'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ mappings }),
    })
    .then(r => r.json())
    .then(data => {
        mappingMessage.value = data.message || 'Zapisano';
        setTimeout(() => mappingMessage.value = '', 4000);
    })
    .catch(err => {
        mappingMessage.value = 'Błąd: ' + err.message;
    })
    .finally(() => savingMapping.value = false);
}
</script>

<template>
    <Head title="Ustawienia systemowe" />
    
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Ustawienia systemowe</h1>
                <p class="text-gray-500">Konfiguracja globalna systemu</p>
            </div>
            <button 
                @click="saveSettings"
                :disabled="saving"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium disabled:opacity-50"
            >
                <Icons :name="saving ? 'spinner' : 'check'" :class="['w-5 h-5', saving && 'animate-spin']" />
                {{ saving ? 'Zapisywanie...' : 'Zapisz zmiany' }}
            </button>
        </div>

        <div class="flex gap-6">
            <!-- Sidebar Navigation -->
            <div class="w-64 flex-shrink-0">
                <nav class="space-y-1">
                    <button 
                        v-for="(label, key) in groups" 
                        :key="key"
                        @click="activeGroup = key"
                        :class="[
                            'w-full flex items-center gap-3 px-4 py-3 rounded-lg text-left transition-colors',
                            activeGroup === key 
                                ? 'bg-indigo-50 text-indigo-700' 
                                : 'text-gray-700 hover:bg-gray-50'
                        ]"
                    >
                        <Icons 
                            :name="key === 'general' ? 'settings' : 
                                   key === 'company' ? 'building-office' : 
                                   key === 'mail' ? 'mail' : 
                                   key === 'integrations' ? 'globe' : 
                                   key === 'appearance' ? 'eye' : 'settings'" 
                            class="w-5 h-5" 
                        />
                        <span class="font-medium">{{ label }}</span>
                    </button>
                </nav>
            </div>

            <!-- Settings Content -->
            <div class="flex-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">{{ groups[activeGroup] }}</h2>
                    </div>
                    
                    <div class="p-6 space-y-6">
                        <template v-for="setting in getGroupSettings(activeGroup)" :key="setting.key">
                            <div class="space-y-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">{{ setting.label }}</span>
                                    <span v-if="setting.description" class="block text-xs text-gray-500 mt-0.5">
                                        {{ setting.description }}
                                    </span>
                                </label>
                                
                                <!-- Boolean toggle -->
                                <div v-if="setting.type === 'boolean'" class="flex items-center">
                                    <button 
                                        type="button"
                                        @click="form[setting.key] = !form[setting.key]"
                                        :class="[
                                            'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                                            form[setting.key] ? 'bg-indigo-600' : 'bg-gray-200'
                                        ]"
                                    >
                                        <span :class="[
                                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                            form[setting.key] ? 'translate-x-5' : 'translate-x-0'
                                        ]" />
                                    </button>
                                    <span class="ml-3 text-sm text-gray-600">
                                        {{ form[setting.key] ? 'Włączone' : 'Wyłączone' }}
                                    </span>
                                </div>
                                
                                <!-- Logo upload (specjalny przypadek) -->
                                <div v-else-if="setting.key === 'app_logo'" class="space-y-3">
                                    <div v-if="logoPreview || form.app_logo" class="flex items-center gap-4">
                                        <img 
                                            :src="logoPreview || form.app_logo" 
                                            alt="Logo" 
                                            class="h-12 w-auto rounded border border-gray-200 p-1"
                                        />
                                        <button 
                                            type="button"
                                            @click="removeLogo"
                                            class="text-sm text-red-600 hover:text-red-700"
                                        >
                                            Usuń logo
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <input
                                            ref="logoInput"
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="handleLogoUpload"
                                        />
                                        <button
                                            type="button"
                                            @click="triggerLogoUpload"
                                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                                        >
                                            <Icons name="upload" class="w-4 h-4 inline mr-2" />
                                            Wybierz plik
                                        </button>
                                        <span class="text-xs text-gray-500">lub wpisz URL:</span>
                                        <input 
                                            v-model="form[setting.key]"
                                            type="text"
                                            placeholder="https://..."
                                            class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                                        />
                                    </div>
                                </div>

                                <!-- Text input -->
                                <input 
                                    v-else-if="setting.type === 'string'"
                                    v-model="form[setting.key]"
                                    type="text"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                />
                                
                                <!-- Number input -->
                                <input 
                                    v-else-if="setting.type === 'integer'"
                                    v-model.number="form[setting.key]"
                                    type="number"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                />
                                
                                <!-- Textarea -->
                                <textarea 
                                    v-else-if="setting.type === 'textarea'"
                                    v-model="form[setting.key]"
                                    rows="4"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                ></textarea>
                                
                                <!-- Select -->
                                <select 
                                    v-else-if="setting.type === 'select'"
                                    v-model="form[setting.key]"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option v-for="(label, value) in setting.options" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                                
                                <!-- Default text input -->
                                <input 
                                    v-else
                                    v-model="form[setting.key]"
                                    type="text"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                />
                            </div>
                        </template>

                        <!-- Autoryzacja i test integracji -->
                        <div v-if="activeGroup === 'integrations'" class="pt-6 border-t border-gray-200 dark:border-slate-700 space-y-6">

                            <!-- Autoryzacja Apilo kodem -->
                            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl space-y-3">
                                <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Autoryzacja Apilo</h3>
                                <p class="text-xs text-amber-700 dark:text-amber-400">
                                    1. Wypełnij pola: Adres, Client ID, Client Secret i kliknij <strong>Zapisz zmiany</strong><br>
                                    2. Skopiuj <strong>Kod autoryzacji</strong> z panelu Apilo (Administracja → API Apilo)<br>
                                    3. Wklej poniżej i kliknij <strong>Autoryzuj</strong> — kod jest jednorazowy i szybko wygasa!
                                </p>
                                <div class="flex gap-2">
                                    <input
                                        v-model="apiloAuthCode"
                                        type="text"
                                        placeholder="Wklej kod autoryzacji z Apilo..."
                                        class="flex-1 px-3 py-2 border border-amber-300 dark:border-amber-600 dark:bg-slate-800 dark:text-slate-200 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-sm font-mono"
                                    />
                                    <button
                                        type="button"
                                        @click="authorizeApilo"
                                        :disabled="authorizingApilo || !apiloAuthCode.trim()"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 font-medium disabled:opacity-50 whitespace-nowrap"
                                    >
                                        <Icons :name="authorizingApilo ? 'spinner' : 'lock'" :class="['w-4 h-4', authorizingApilo && 'animate-spin']" />
                                        {{ authorizingApilo ? 'Autoryzuję...' : 'Autoryzuj' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Testy połączeń -->
                            <div>
                                <h3 class="text-sm font-medium text-gray-900 dark:text-slate-200 mb-3">Test połączeń</h3>
                                <div class="flex flex-wrap items-center gap-4">
                                    <button
                                        type="button"
                                        @click="testFakturownia"
                                        :disabled="testingFakturownia"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium disabled:opacity-50"
                                    >
                                        <Icons :name="testingFakturownia ? 'spinner' : 'globe'" :class="['w-4 h-4', testingFakturownia && 'animate-spin']" />
                                        {{ testingFakturownia ? 'Testowanie...' : 'Testuj Fakturownia' }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="testApilo"
                                        :disabled="testingApilo"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 font-medium disabled:opacity-50"
                                    >
                                        <Icons :name="testingApilo ? 'spinner' : 'shopping-cart'" :class="['w-4 h-4', testingApilo && 'animate-spin']" />
                                        {{ testingApilo ? 'Testowanie...' : 'Testuj Apilo' }}
                                    </button>
                                    <button
                                        v-if="hasRingostat"
                                        type="button"
                                        @click="testRingostat"
                                        :disabled="testingRingostat"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50"
                                    >
                                        <Icons :name="testingRingostat ? 'spinner' : 'phone'" :class="['w-4 h-4', testingRingostat && 'animate-spin']" />
                                        {{ testingRingostat ? 'Testowanie...' : 'Testuj Ringostat' }}
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-slate-400 mt-2">
                                    Najpierw zapisz ustawienia, potem testuj połączenie.
                                </p>
                            </div>

                            <!-- Ringostat - synchronizacja pracowników (tylko gdy moduł aktywny) -->
                            <div v-if="hasRingostat" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-xl space-y-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-green-800 dark:text-green-300">Ringostat - Synchronizacja pracowników</h3>
                                    <span v-if="mappingMessage" class="text-xs text-green-600 dark:text-green-400">{{ mappingMessage }}</span>
                                </div>
                                <p class="text-xs text-green-700 dark:text-green-400">
                                    Pobierz listę pracowników z Ringostat i przypisz ich do użytkowników planera. Dzięki temu połączenia będą automatycznie powiązane z odpowiednimi osobami.
                                </p>
                                <button
                                    type="button"
                                    @click="syncRingostatEmployees"
                                    :disabled="syncingEmployees"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50"
                                >
                                    <Icons :name="syncingEmployees ? 'spinner' : 'sync'" :class="['w-4 h-4', syncingEmployees && 'animate-spin']" />
                                    {{ syncingEmployees ? 'Pobieranie...' : 'Pobierz pracowników z Ringostat' }}
                                </button>

                                <!-- Tabela mapowania -->
                                <div v-if="showEmployeeMapping && ringostatEmployees.length > 0" class="mt-4 space-y-2">
                                    <div class="text-xs font-medium text-green-800 dark:text-green-300 mb-2">
                                        Przypisz pracowników Ringostat do użytkowników planera:
                                    </div>
                                    <div class="max-h-64 overflow-y-auto space-y-2">
                                        <div v-for="emp in ringostatEmployees" :key="emp.staffId"
                                            class="flex items-center gap-3 bg-white dark:bg-slate-800 p-2 rounded-lg border border-green-200 dark:border-green-800"
                                        >
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-800 dark:text-slate-200 truncate">{{ emp.fio }}</p>
                                                <p class="text-xs text-gray-500 dark:text-slate-400">
                                                    {{ emp.email || '' }}
                                                    <span v-if="emp.departments && emp.departments.length" class="ml-1">
                                                        · {{ emp.departments.map(d => d.name).join(', ') }}
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <span :class="['w-2 h-2 rounded-full', emp.status ? 'bg-green-500' : 'bg-red-400']"></span>
                                                <span class="text-[10px] text-gray-400">{{ emp.status ? 'Online' : 'Offline' }}</span>
                                            </div>
                                            <select
                                                v-model="employeeMappings[emp.staffId]"
                                                class="w-48 text-sm border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white rounded-lg"
                                            >
                                                <option value="">— brak —</option>
                                                <option v-for="u in plannerUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 mt-2">
                                        <button
                                            type="button"
                                            @click="saveEmployeeMapping"
                                            :disabled="savingMapping"
                                            class="inline-flex items-center gap-2 px-4 py-2 bg-green-700 text-white rounded-lg hover:bg-green-800 font-medium disabled:opacity-50"
                                        >
                                            {{ savingMapping ? 'Zapisywanie...' : 'Zapisz mapowanie' }}
                                        </button>
                                        <button
                                            type="button"
                                            @click="rematchCalls"
                                            :disabled="rematching"
                                            class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 font-medium disabled:opacity-50"
                                            title="Ponownie dopasuj istniejące połączenia do handlowców (po zmianie mapowania)"
                                        >
                                            {{ rematching ? 'Dopasowuję...' : 'Ponownie dopasuj połączenia' }}
                                        </button>
                                    </div>
                                </div>
                                <p v-else-if="showEmployeeMapping" class="text-xs text-green-600 dark:text-green-400">
                                    Nie znaleziono pracowników. Sprawdź konfigurację Auth Key i Project ID.
                                </p>
                            </div>
                        </div>

                        <div v-if="getGroupSettings(activeGroup).length === 0" class="text-center py-8 text-gray-500">
                            <Icons name="settings" class="w-12 h-12 mx-auto mb-4 text-gray-300" />
                            <p>Brak ustawień w tej grupie</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
