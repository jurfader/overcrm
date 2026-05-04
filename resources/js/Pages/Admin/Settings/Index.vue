<script setup>
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Textarea from '@/Components/Textarea.vue';
import Switch from '@/Components/UI/Switch.vue';
import BrandingPanel from '@/Components/Admin/BrandingPanel.vue';

const props = defineProps({
    settings: Object,
    groups: Object,
    brand: { type: Object, default: () => ({}) },
    brandDefaults: { type: Object, default: () => ({ primary_color: '#E91E8C', secondary_color: '#9B26D9' }) },
});

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

const groupIcons = {
    general:    'settings',
    company:    'building-office',
    mail:       'mail',
    appearance: 'sparkles',
};
</script>

<template>
    <Head title="Ustawienia systemowe" />

    <div class="p-6 space-y-6 animate-fade-in">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Ustawienia systemowe</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Konfiguracja globalna systemu</p>
            </div>
            <Button v-if="activeGroup !== 'appearance'" @click="saveSettings" :loading="saving">
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
                    <!-- Wygląd: dedykowany BrandingPanel -->
                    <BrandingPanel
                        v-if="activeGroup === 'appearance'"
                        :brand="brand"
                        :defaults="brandDefaults"
                    />

                    <template v-else>
                        <!-- Empty state -->
                        <div v-if="getGroupSettings(activeGroup).length === 0"
                             class="text-center py-12 text-foreground-subtle">
                            <Icons name="settings" class="w-10 h-10 mx-auto mb-3 opacity-50" />
                            <p class="text-sm">Brak ustawień w tej grupie</p>
                            <p class="text-xs mt-1">Konfiguracja modułów znajduje się w zakładce <strong>Moduły</strong> po ich włączeniu.</p>
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

                                <div v-if="setting.type === 'boolean'" class="flex items-center gap-3">
                                    <Switch v-model="form[setting.key]" />
                                    <span class="text-sm text-foreground-muted">
                                        {{ form[setting.key] ? 'Włączone' : 'Wyłączone' }}
                                    </span>
                                </div>

                                <div v-else-if="setting.key === 'app_logo'" class="space-y-3">
                                    <div v-if="logoPreview || form.app_logo" class="flex items-center gap-4">
                                        <img :src="logoPreview || form.app_logo" alt="Logo"
                                            class="h-12 w-auto rounded surface-elevated p-2" />
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

                                <Input v-else-if="setting.type === 'string'" v-model="form[setting.key]" />
                                <Input v-else-if="setting.type === 'integer'" v-model.number="form[setting.key]" type="number" />
                                <Textarea v-else-if="setting.type === 'textarea'" v-model="form[setting.key]" :rows="4" />
                                <select v-else-if="setting.type === 'select'" v-model="form[setting.key]"
                                    class="h-9 w-full rounded-md border border-border-bright px-3 py-2 text-sm bg-surface-elevated text-foreground focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary">
                                    <option v-for="(label, value) in setting.options" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <Input v-else v-model="form[setting.key]" />
                            </div>
                        </template>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
