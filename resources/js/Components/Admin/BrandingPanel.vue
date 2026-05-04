<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Switch from '@/Components/UI/Switch.vue';

const props = defineProps({
    brand: { type: Object, required: true },
    defaults: { type: Object, required: true },
});

const form = reactive({
    name: props.brand.name || 'OVERCRM',
    short_name: props.brand.short_name || 'OVERCRM',
    company_name: props.brand.company_name || '',
    primary_color: props.brand.primary_color || props.defaults.primary_color,
    secondary_color: props.brand.secondary_color || props.defaults.secondary_color,
    use_gradient: props.brand.use_gradient !== false,
    support_email: props.brand.support_email || '',
    support_phone: props.brand.support_phone || '',
    default_theme: props.brand.default_theme || 'dark',
});

const saving = ref(false);
const uploading = ref({ logo_url: false, logo_dark_url: false, favicon_url: false });
const logoInputs = {
    logo_url: ref(null),
    logo_dark_url: ref(null),
    favicon_url: ref(null),
};

const previewGradient = computed(() => `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)`);
const previewBrandColor = computed(() => form.use_gradient ? previewGradient.value : form.primary_color);

function save() {
    saving.value = true;
    router.post(route('admin.branding.update'), { ...form }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
}

function resetColors() {
    form.primary_color = props.defaults.primary_color;
    form.secondary_color = props.defaults.secondary_color;
}

function triggerUpload(asset) { logoInputs[asset].value?.click(); }

function handleUpload(asset, event) {
    const file = event.target.files[0];
    if (!file) return;
    const data = new FormData();
    data.append('asset', asset);
    data.append('file', file);
    uploading.value[asset] = true;
    router.post(route('admin.branding.upload'), data, {
        preserveScroll: true,
        onFinish: () => { uploading.value[asset] = false; event.target.value = ''; },
    });
}

function removeAsset(asset) {
    if (!confirm('Usunąć ten plik?')) return;
    router.delete(route('admin.branding.remove-asset'), {
        data: { asset },
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_minmax(320px,380px)] gap-6">
        <!-- FORM -->
        <div class="space-y-6">
            <!-- Nazwy -->
            <section class="surface-elevated rounded-lg p-5 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-foreground">Nazwy</h3>
                    <p class="text-xs text-foreground-muted mt-0.5">Pojawiają się w nawigacji, e-mailach i dokumentach</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">Nazwa aplikacji</label>
                        <Input v-model="form.name" placeholder="OVERCRM" />
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">Krótka nazwa</label>
                        <Input v-model="form.short_name" placeholder="OCRM" />
                        <p class="text-xs text-foreground-muted">Dla wąskich miejsc (top bar mobile)</p>
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Pełna nazwa firmy</label>
                    <Input v-model="form.company_name" placeholder="Twoja Firma Sp. z o.o." />
                </div>
            </section>

            <!-- Kolory -->
            <section class="surface-elevated rounded-lg p-5 space-y-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h3 class="text-base font-semibold text-foreground">Kolory marki</h3>
                        <p class="text-xs text-foreground-muted mt-0.5">Sterują gradientem brandu, akcentami i przyciskami</p>
                    </div>
                    <button type="button" @click="resetColors" class="text-xs text-brand-primary hover:underline">
                        Przywróć domyślne
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">Kolor podstawowy</label>
                        <div class="flex items-center gap-2">
                            <input type="color" v-model="form.primary_color"
                                   class="w-12 h-9 rounded border border-border-bright cursor-pointer" />
                            <Input v-model="form.primary_color" placeholder="#E91E8C" class="flex-1 font-mono" />
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">Kolor drugorzędny</label>
                        <div class="flex items-center gap-2">
                            <input type="color" v-model="form.secondary_color"
                                   class="w-12 h-9 rounded border border-border-bright cursor-pointer" />
                            <Input v-model="form.secondary_color" placeholder="#9B26D9" class="flex-1 font-mono" />
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <Switch v-model="form.use_gradient" />
                    <div>
                        <p class="text-sm font-medium text-foreground">Używaj gradientu</p>
                        <p class="text-xs text-foreground-muted">Po wyłączeniu wszędzie pokazuje się tylko kolor podstawowy</p>
                    </div>
                </div>
            </section>

            <!-- Theme -->
            <section class="surface-elevated rounded-lg p-5 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-foreground">Motyw domyślny</h3>
                    <p class="text-xs text-foreground-muted mt-0.5">Z jakim motywem witasz nowych użytkowników</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" @click="form.default_theme = 'dark'"
                            :class="['rounded-lg border-2 p-4 text-left transition-all',
                                      form.default_theme === 'dark'
                                          ? 'border-brand-primary bg-surface'
                                          : 'border-border hover:border-border-bright']">
                        <div class="flex items-center gap-2 mb-2">
                            <Icons name="moon" class="w-5 h-5 text-foreground" />
                            <span class="font-medium text-foreground">Ciemny</span>
                        </div>
                        <div class="h-12 rounded" style="background: #0B0B14; border: 1px solid #2D2D44;"></div>
                    </button>
                    <button type="button" @click="form.default_theme = 'light'"
                            :class="['rounded-lg border-2 p-4 text-left transition-all',
                                      form.default_theme === 'light'
                                          ? 'border-brand-primary bg-surface'
                                          : 'border-border hover:border-border-bright']">
                        <div class="flex items-center gap-2 mb-2">
                            <Icons name="sun" class="w-5 h-5 text-foreground" />
                            <span class="font-medium text-foreground">Jasny</span>
                        </div>
                        <div class="h-12 rounded" style="background: #FFFFFF; border: 1px solid #E2E5EF;"></div>
                    </button>
                </div>
            </section>

            <!-- Logo + Favicon -->
            <section class="surface-elevated rounded-lg p-5 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-foreground">Logo i favicon</h3>
                    <p class="text-xs text-foreground-muted mt-0.5">PNG/SVG do 2 MB. Wersja jasna i ciemna pojawiają się w odpowiednim motywie.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div v-for="asset in [
                            { key: 'logo_url',      label: 'Logo (motyw jasny)', accept: 'image/png,image/svg+xml,image/webp,image/jpeg', bg: 'bg-white' },
                            { key: 'logo_dark_url', label: 'Logo (motyw ciemny)', accept: 'image/png,image/svg+xml,image/webp,image/jpeg', bg: 'bg-[#0B0B14]' },
                            { key: 'favicon_url',   label: 'Favicon', accept: 'image/x-icon,image/png,image/svg+xml', bg: 'bg-surface' },
                          ]" :key="asset.key" class="space-y-2">
                        <label class="text-sm font-medium text-foreground">{{ asset.label }}</label>
                        <div :class="['h-24 rounded-lg flex items-center justify-center overflow-hidden border border-border', asset.bg]">
                            <img v-if="brand[asset.key]" :src="brand[asset.key]" :alt="asset.label"
                                 class="max-h-full max-w-full object-contain p-2" />
                            <span v-else class="text-foreground-subtle text-xs">Brak pliku</span>
                        </div>
                        <input :ref="el => logoInputs[asset.key].value = el" type="file"
                               :accept="asset.accept" class="hidden"
                               @change="(e) => handleUpload(asset.key, e)" />
                        <div class="flex gap-2">
                            <Button variant="outline" size="sm" :loading="uploading[asset.key]" @click="triggerUpload(asset.key)">
                                <Icons name="upload" class="w-3.5 h-3.5" />
                                {{ brand[asset.key] ? 'Zmień' : 'Wybierz' }}
                            </Button>
                            <Button v-if="brand[asset.key]" variant="ghost" size="sm" @click="removeAsset(asset.key)" class="text-destructive hover:bg-destructive/10">
                                <Icons name="trash" class="w-3.5 h-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Kontakt support -->
            <section class="surface-elevated rounded-lg p-5 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-foreground">Kontakt support</h3>
                    <p class="text-xs text-foreground-muted mt-0.5">Pojawiają się w stopce e-maili i widoku pomocy</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">E-mail</label>
                        <Input v-model="form.support_email" type="email" placeholder="support@twojafirma.pl" />
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-foreground">Telefon</label>
                        <Input v-model="form.support_phone" placeholder="+48 123 456 789" />
                    </div>
                </div>
            </section>

            <!-- Save action -->
            <div class="flex justify-end">
                <Button @click="save" :loading="saving">
                    <Icons name="check" class="w-4 h-4" />
                    {{ saving ? 'Zapisywanie...' : 'Zapisz branding' }}
                </Button>
            </div>
        </div>

        <!-- LIVE PREVIEW -->
        <aside class="self-start xl:sticky xl:top-[calc(var(--topbar-height)+1.5rem)]">
            <div class="surface-elevated rounded-lg overflow-hidden border border-border">
                <div class="px-4 py-3 border-b border-border flex items-center gap-2">
                    <Icons name="eye" class="w-4 h-4 text-foreground-muted" />
                    <h3 class="text-sm font-semibold text-foreground">Podgląd na żywo</h3>
                </div>
                <div class="p-5 space-y-5">
                    <div class="h-2 rounded-full" :style="{ background: previewBrandColor }"></div>
                    <div>
                        <div class="text-3xl font-bold leading-tight"
                             :style="form.use_gradient ? {
                                 background: previewGradient,
                                 '-webkit-background-clip': 'text',
                                 '-webkit-text-fill-color': 'transparent',
                                 'background-clip': 'text',
                                 color: 'transparent',
                             } : { color: form.primary_color }">
                            {{ form.name || 'OVERCRM' }}
                        </div>
                        <p v-if="form.company_name" class="text-sm text-foreground-muted mt-1">{{ form.company_name }}</p>
                    </div>
                    <div class="bg-surface rounded-lg p-4 flex items-center gap-3 border border-border">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-sm shrink-0"
                             :style="{ background: previewBrandColor }">
                            <span>{{ (form.short_name || form.name || 'OC').substring(0, 2).toUpperCase() }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground truncate">Przykładowy klient</p>
                            <p class="text-xs text-foreground-muted">Aktywny · Firma</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs font-semibold text-foreground-muted uppercase tracking-wider">Przyciski</p>
                        <div class="flex gap-2 flex-wrap">
                            <button class="px-4 py-2 rounded-lg text-white text-sm font-medium shadow"
                                    :style="{ background: previewBrandColor }">
                                Akcja główna
                            </button>
                            <button class="px-4 py-2 rounded-lg text-sm font-medium border border-border-bright bg-surface text-foreground">
                                Akcja drugorzędna
                            </button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs font-semibold text-foreground-muted uppercase tracking-wider">Paleta</p>
                        <div class="flex gap-2">
                            <div class="flex-1 h-12 rounded-lg shadow-sm" :style="{ background: form.primary_color }"
                                 :title="form.primary_color"></div>
                            <div class="flex-1 h-12 rounded-lg shadow-sm" :style="{ background: form.secondary_color }"
                                 :title="form.secondary_color"></div>
                        </div>
                        <div class="flex justify-between text-[10px] font-mono text-foreground-muted">
                            <span>{{ form.primary_color }}</span>
                            <span>{{ form.secondary_color }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg p-3 bg-info/10 border border-info/30 flex gap-2">
                        <Icons name="info" class="w-4 h-4 text-info shrink-0 mt-0.5" />
                        <p class="text-xs text-foreground">
                            Aby zobaczyć zmiany w całym systemie, zapisz i odśwież stronę.
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
