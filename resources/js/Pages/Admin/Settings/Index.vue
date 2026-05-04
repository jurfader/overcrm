<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Textarea from '@/Components/Textarea.vue';
import Switch from '@/Components/UI/Switch.vue';

const page = usePage();

function hasRoute(name) {
    try { route(name); return true; } catch { return false; }
}

const hasRingostat = computed(() => {
    const modules = page.props.activeModules || [];
    return modules.some(m => m.name === 'ringostat') && hasRoute('ringostat.test-connection');
});
const hasRingostatEmployeeSync = computed(() => hasRoute('ringostat.sync-employees'));

const props = defineProps({ settings: Object, groups: Object });

const activeGroup = ref(Object.keys(props.groups)[0] || 'general');
const saving = ref(false);

const form = reactive({});
for (const [, groupSettings] of Object.entries(props.settings || {})) {
    for (const setting of groupSettings) form[setting.key] = setting.value;
}

function saveSettings() {
    saving.value = true;
    router.post(route('admin.settings.update'), { settings: form }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
}
function getGroupSettings(g) { return props.settings[g] || []; }

// Logo upload
const logoInput = ref(null);
const logoPreview = ref(form.app_logo || null);
function triggerLogoUpload() { logoInput.value?.click(); }
function handleLogoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => { logoPreview.value = e.target.result; };
    reader.readAsDataURL(file);
    const formData = new FormData();
    formData.append('logo', file);
    router.post(route('admin.settings.upload-logo'), formData, { preserveScroll: true });
}
function removeLogo() { logoPreview.value = null; form.app_logo = null; }

// Integration tests
const testingFakturownia = ref(false);
function testFakturownia() {
    testingFakturownia.value = true;
    router.post(route('admin.settings.test-fakturownia'), {}, {
        preserveScroll: true,
        onFinish: () => { testingFakturownia.value = false; },
    });
}
const testingApilo = ref(false);
function testApilo() {
    testingApilo.value = true;
    router.post(route('admin.settings.test-apilo'), {}, {
        preserveScroll: true,
        onFinish: () => { testingApilo.value = false; },
    });
}

// Apilo authorization
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
        onFinish: () => { authorizingApilo.value = false; apiloAuthCode.value = ''; },
    });
}

// Ringostat
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
    .then(data => alert(data.success ? data.message : 'Błąd: ' + data.message))
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
            const map = {};
            for (const u of data.users) {
                if (u.ringostat_employee_id) map[u.ringostat_employee_id] = u.id;
            }
            employeeMappings.value = map;
        } else {
            mappingMessage.value = data.message || 'Błąd pobierania pracowników';
        }
    })
    .catch(err => { mappingMessage.value = 'Błąd: ' + err.message; })
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
    .then(data => { mappingMessage.value = data.message || 'Dopasowano'; setTimeout(() => mappingMessage.value = '', 4000); })
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
    .then(data => { mappingMessage.value = data.message || 'Zapisano'; setTimeout(() => mappingMessage.value = '', 4000); })
    .catch(err => { mappingMessage.value = 'Błąd: ' + err.message; })
    .finally(() => savingMapping.value = false);
}

const groupIcons = {
    general: 'settings',
    company: 'building-office',
    mail: 'mail',
    integrations: 'globe',
    appearance: 'eye',
    branding: 'sparkles',
};
</script>

<template>
    <Head title="Ustawienia systemowe" />

    <div class="p-6 space-y-6 animate-fade-in">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Ustawienia systemowe</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Konfiguracja globalna systemu</p>
            </div>
            <Button @click="saveSettings" :loading="saving">
                <Icons name="check" class="w-4 h-4" />
                {{ saving ? 'Zapisywanie...' : 'Zapisz zmiany' }}
            </Button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            <!-- Sidebar -->
            <aside class="glass-card rounded-lg p-2 self-start sticky top-[calc(var(--topbar-height)+1.5rem)]">
                <nav class="space-y-1">
                    <button v-for="(label, key) in groups" :key="key"
                        @click="activeGroup = key"
                        :class="[
                            'w-full flex items-center gap-3 px-3 py-2.5 rounded-md text-left text-sm transition-colors relative',
                            activeGroup === key
                                ? 'gradient-subtle text-brand-primary font-medium'
                                : 'text-foreground-muted hover:text-foreground hover:bg-surface-elevated'
                        ]">
                        <span v-if="activeGroup === key" class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-5 rounded-r gradient-brand" />
                        <Icons :name="groupIcons[key] || 'settings'" class="w-4 h-4 shrink-0" />
                        <span class="truncate">{{ label }}</span>
                    </button>
                </nav>
            </aside>

            <!-- Content -->
            <div class="glass-card rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-border">
                    <h2 class="text-lg font-semibold text-foreground">{{ groups[activeGroup] }}</h2>
                </div>

                <div class="p-6 space-y-6">
                    <!-- Empty state -->
                    <div v-if="getGroupSettings(activeGroup).length === 0 && activeGroup !== 'integrations'"
                         class="text-center py-12 text-foreground-subtle">
                        <Icons name="settings" class="w-10 h-10 mx-auto mb-3 opacity-50" />
                        <p class="text-sm">Brak ustawień w tej grupie</p>
                    </div>

                    <!-- Settings fields -->
                    <template v-for="setting in getGroupSettings(activeGroup)" :key="setting.key">
                        <div class="space-y-2">
                            <label class="block">
                                <span class="text-sm font-medium text-foreground">{{ setting.label }}</span>
                                <span v-if="setting.description" class="block text-xs text-foreground-muted mt-0.5">
                                    {{ setting.description }}
                                </span>
                            </label>

                            <!-- Boolean toggle -->
                            <div v-if="setting.type === 'boolean'" class="flex items-center gap-3">
                                <Switch v-model="form[setting.key]" />
                                <span class="text-sm text-foreground-muted">
                                    {{ form[setting.key] ? 'Włączone' : 'Wyłączone' }}
                                </span>
                            </div>

                            <!-- Logo upload -->
                            <div v-else-if="setting.key === 'app_logo'" class="space-y-3">
                                <div v-if="logoPreview || form.app_logo" class="flex items-center gap-4">
                                    <img :src="logoPreview || form.app_logo" alt="Logo"
                                        class="h-12 w-auto rounded surface p-2" />
                                    <Button variant="ghost" size="sm" @click="removeLogo" class="text-destructive hover:bg-destructive/10">
                                        Usuń logo
                                    </Button>
                                </div>
                                <div class="flex items-center gap-3 flex-wrap">
                                    <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="handleLogoUpload" />
                                    <Button variant="outline" size="sm" @click="triggerLogoUpload">
                                        <Icons name="upload" class="w-4 h-4" />
                                        Wybierz plik
                                    </Button>
                                    <span class="text-xs text-foreground-muted">lub URL:</span>
                                    <Input v-model="form[setting.key]" placeholder="https://..." class="flex-1 min-w-[200px]" />
                                </div>
                            </div>

                            <!-- Text -->
                            <Input v-else-if="setting.type === 'string'" v-model="form[setting.key]" />

                            <!-- Number -->
                            <Input v-else-if="setting.type === 'integer'" v-model.number="form[setting.key]" type="number" />

                            <!-- Textarea -->
                            <Textarea v-else-if="setting.type === 'textarea'" v-model="form[setting.key]" :rows="4" />

                            <!-- Select -->
                            <select v-else-if="setting.type === 'select'" v-model="form[setting.key]"
                                class="h-9 w-full rounded-md border border-border-bright px-3 py-2 text-sm bg-surface text-foreground focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary">
                                <option v-for="(label, value) in setting.options" :key="value" :value="value">{{ label }}</option>
                            </select>

                            <!-- Default text -->
                            <Input v-else v-model="form[setting.key]" />
                        </div>
                    </template>

                    <!-- Integration extras -->
                    <div v-if="activeGroup === 'integrations'" class="pt-6 border-t border-border space-y-6">
                        <!-- Apilo authorization -->
                        <div class="rounded-lg p-4 bg-warning/10 border border-warning/30 space-y-3">
                            <h3 class="text-sm font-semibold text-warning flex items-center gap-2">
                                <Icons name="lock" class="w-4 h-4" />
                                Autoryzacja Apilo
                            </h3>
                            <p class="text-xs text-foreground-muted">
                                1. Wypełnij Adres + Client ID + Client Secret i kliknij <strong class="text-foreground">Zapisz zmiany</strong><br>
                                2. Skopiuj <strong class="text-foreground">Kod autoryzacji</strong> z panelu Apilo (Administracja → API)<br>
                                3. Wklej poniżej i kliknij <strong class="text-foreground">Autoryzuj</strong> — kod jest jednorazowy!
                            </p>
                            <div class="flex gap-2 flex-wrap">
                                <Input v-model="apiloAuthCode" placeholder="Wklej kod autoryzacji z Apilo..." class="flex-1 min-w-[200px] font-mono" />
                                <Button variant="warning" :loading="authorizingApilo" :disabled="!apiloAuthCode.trim()" @click="authorizeApilo">
                                    <Icons v-if="!authorizingApilo" name="lock" class="w-4 h-4" />
                                    {{ authorizingApilo ? 'Autoryzuję...' : 'Autoryzuj' }}
                                </Button>
                            </div>
                        </div>

                        <!-- Test connections -->
                        <div>
                            <h3 class="text-sm font-semibold text-foreground mb-3">Test połączeń</h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button variant="secondary" :loading="testingFakturownia" @click="testFakturownia">
                                    <Icons v-if="!testingFakturownia" name="globe" class="w-4 h-4" />
                                    {{ testingFakturownia ? 'Testowanie...' : 'Testuj Fakturownia' }}
                                </Button>
                                <Button variant="secondary" :loading="testingApilo" @click="testApilo">
                                    <Icons v-if="!testingApilo" name="shopping-cart" class="w-4 h-4" />
                                    {{ testingApilo ? 'Testowanie...' : 'Testuj Apilo' }}
                                </Button>
                                <Button v-if="hasRingostat" variant="success" :loading="testingRingostat" @click="testRingostat">
                                    <Icons v-if="!testingRingostat" name="phone" class="w-4 h-4" />
                                    {{ testingRingostat ? 'Testowanie...' : 'Testuj Ringostat' }}
                                </Button>
                            </div>
                            <p class="text-xs text-foreground-muted mt-2">Najpierw zapisz ustawienia, potem testuj połączenie.</p>
                        </div>

                        <!-- Ringostat employees -->
                        <div v-if="hasRingostat && hasRingostatEmployeeSync" class="rounded-lg p-4 bg-success/10 border border-success/30 space-y-3">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="text-sm font-semibold text-success flex items-center gap-2">
                                    <Icons name="phone" class="w-4 h-4" />
                                    Ringostat — synchronizacja pracowników
                                </h3>
                                <span v-if="mappingMessage" class="text-xs text-success">{{ mappingMessage }}</span>
                            </div>
                            <p class="text-xs text-foreground-muted">
                                Pobierz listę pracowników z Ringostat i przypisz do użytkowników CRM. Połączenia będą automatycznie powiązane.
                            </p>
                            <Button variant="success" :loading="syncingEmployees" @click="syncRingostatEmployees">
                                <Icons v-if="!syncingEmployees" name="sync" class="w-4 h-4" />
                                {{ syncingEmployees ? 'Pobieranie...' : 'Pobierz pracowników z Ringostat' }}
                            </Button>

                            <div v-if="showEmployeeMapping && ringostatEmployees.length > 0" class="mt-4 space-y-2">
                                <p class="text-xs font-semibold text-foreground">Przypisz pracowników Ringostat do CRM:</p>
                                <div class="max-h-64 overflow-y-auto space-y-2">
                                    <div v-for="emp in ringostatEmployees" :key="emp.staffId"
                                        class="flex items-center gap-3 surface-elevated p-2 rounded-md">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-foreground truncate">{{ emp.fio }}</p>
                                            <p class="text-xs text-foreground-muted truncate">
                                                {{ emp.email || '' }}
                                                <span v-if="emp.departments && emp.departments.length" class="ml-1">
                                                    · {{ emp.departments.map(d => d.name).join(', ') }}
                                                </span>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <span :class="['w-2 h-2 rounded-full', emp.status ? 'bg-success' : 'bg-destructive/70']" />
                                            <span class="text-[10px] text-foreground-subtle">{{ emp.status ? 'Online' : 'Offline' }}</span>
                                        </div>
                                        <select v-model="employeeMappings[emp.staffId]"
                                            class="w-44 h-8 text-sm rounded border border-border-bright bg-surface text-foreground px-2">
                                            <option value="">— brak —</option>
                                            <option v-for="u in plannerUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex gap-2 mt-2 flex-wrap">
                                    <Button variant="success" :loading="savingMapping" @click="saveEmployeeMapping">
                                        {{ savingMapping ? 'Zapisywanie...' : 'Zapisz mapowanie' }}
                                    </Button>
                                    <Button variant="warning" :loading="rematching" @click="rematchCalls"
                                        title="Ponownie dopasuj istniejące połączenia (po zmianie mapowania)">
                                        {{ rematching ? 'Dopasowuję...' : 'Ponownie dopasuj połączenia' }}
                                    </Button>
                                </div>
                            </div>
                            <p v-else-if="showEmployeeMapping" class="text-xs text-success">
                                Nie znaleziono pracowników. Sprawdź konfigurację Auth Key i Project ID.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
