<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Switch from '@/Components/UI/Switch.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['next']);

const brand = props.setup.brand || {};
const defaults = props.setup.brandDefaults || {};

const form = useForm({
    name:            brand.name || 'OVERCRM',
    short_name:      brand.short_name || 'OVERCRM',
    primary_color:   brand.primary_color || defaults.primary_color,
    secondary_color: brand.secondary_color || defaults.secondary_color,
    use_gradient:    brand.use_gradient !== false,
    default_theme:   brand.default_theme || 'dark',
    support_email:   brand.support_email || '',
    support_phone:   brand.support_phone || '',
});

const uploading = ref({ logo_url: false, logo_dark_url: false });

const previewBackground = computed(() => form.use_gradient
    ? `linear-gradient(135deg, ${form.primary_color} 0%, ${form.secondary_color} 100%)`
    : form.primary_color);

const initial = computed(() => (form.short_name || form.name || 'O').trim().charAt(0).toUpperCase());

function resetColors() {
    form.primary_color = defaults.primary_color;
    form.secondary_color = defaults.secondary_color;
}

function triggerUpload(asset) {
    document.getElementById(`setup-upload-${asset}`)?.click();
}

function handleUpload(asset, event) {
    const file = event.target.files[0];
    if (!file) return;

    const data = new FormData();
    data.append('asset', asset);
    data.append('file', file);

    uploading.value[asset] = true;
    router.post(route('setup.branding.upload'), data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            uploading.value[asset] = false;
            event.target.value = '';
        },
    });
}

function removeAsset(asset) {
    router.delete(route('setup.branding.remove-asset'), {
        data: { asset },
        preserveScroll: true,
        preserveState: true,
    });
}

function submit() {
    form.post(route('setup.branding'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

function skip() {
    router.post(route('setup.skip'), { step: 'branding' }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

defineExpose({ submit, skip });
</script>

<template>
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_300px] gap-6">
        <div class="space-y-5">
            <!-- Nazwy -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-foreground">Nazwa systemu</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">Widoczna w tytule okna i nagłówkach</span>
                    <Input v-model="form.name" class="mt-1.5" />
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-foreground">Nazwa skrócona</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">Używana gdy brak logo (inicjał w kafelku)</span>
                    <Input v-model="form.short_name" class="mt-1.5" />
                </label>
            </div>

            <!-- Kolory -->
            <div class="surface-elevated rounded-lg p-4 space-y-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <span class="text-sm font-medium text-foreground">Kolory marki</span>
                    <Button type="button" variant="ghost" size="sm" @click="resetColors">
                        <Icons name="refresh" class="w-3.5 h-3.5" />
                        Przywróć domyślne
                    </Button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="text-xs text-foreground-muted">Kolor główny</span>
                        <div class="flex gap-2 mt-1.5">
                            <input v-model="form.primary_color" type="color" class="h-9 w-12 rounded-md border border-border-bright bg-surface cursor-pointer" />
                            <Input v-model="form.primary_color" class="font-mono" />
                        </div>
                    </label>
                    <label class="block">
                        <span class="text-xs text-foreground-muted">Kolor dodatkowy</span>
                        <div class="flex gap-2 mt-1.5">
                            <input v-model="form.secondary_color" type="color" class="h-9 w-12 rounded-md border border-border-bright bg-surface cursor-pointer" />
                            <Input v-model="form.secondary_color" class="font-mono" />
                        </div>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <Switch v-model="form.use_gradient" />
                    <span class="text-sm text-foreground-muted">Gradient zamiast jednolitego koloru</span>
                </div>
            </div>

            <!-- Motyw -->
            <div class="surface-elevated rounded-lg p-4 space-y-3">
                <span class="text-sm font-medium text-foreground">Domyślny motyw</span>
                <div class="flex gap-2">
                    <button
                        v-for="theme in [{ key: 'dark', label: 'Ciemny', icon: 'moon' }, { key: 'light', label: 'Jasny', icon: 'sun' }]"
                        :key="theme.key"
                        type="button"
                        @click="form.default_theme = theme.key"
                        :class="[
                            'flex items-center gap-2 px-4 py-2 rounded-md border text-sm transition-colors',
                            form.default_theme === theme.key
                                ? 'border-brand-primary/50 gradient-subtle text-foreground'
                                : 'border-border text-foreground-muted hover:bg-surface-hover',
                        ]"
                    >
                        <Icons :name="theme.icon" class="w-4 h-4" />
                        {{ theme.label }}
                    </button>
                </div>
            </div>

            <!-- Logo -->
            <div class="surface-elevated rounded-lg p-4 space-y-4">
                <div>
                    <span class="text-sm font-medium text-foreground">Logo</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">
                        PNG/SVG do 2 MB. Bez logo pokazujemy kafelek z inicjałem.
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-for="asset in [{ key: 'logo_url', label: 'Logo (motyw ciemny)' }, { key: 'logo_dark_url', label: 'Logo (motyw jasny)' }]" :key="asset.key" class="space-y-2">
                        <span class="text-xs text-foreground-muted block">{{ asset.label }}</span>

                        <div class="h-16 rounded-md border border-border bg-surface flex items-center justify-center overflow-hidden">
                            <img v-if="setup.brand[asset.key]" :src="setup.brand[asset.key]" alt="" class="max-h-12 max-w-[80%] object-contain" />
                            <span v-else class="text-xs text-foreground-subtle">brak pliku</span>
                        </div>

                        <div class="flex gap-2">
                            <input :id="`setup-upload-${asset.key}`" type="file" accept="image/*" class="hidden" @change="handleUpload(asset.key, $event)" />
                            <Button type="button" variant="outline" size="sm" :loading="uploading[asset.key]" @click="triggerUpload(asset.key)">
                                <Icons name="upload" class="w-3.5 h-3.5" />
                                Wgraj
                            </Button>
                            <Button v-if="setup.brand[asset.key]" type="button" variant="ghost" size="sm" @click="removeAsset(asset.key)">
                                <Icons name="trash" class="w-3.5 h-3.5" />
                                Usuń
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kontakt wsparcia -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-foreground">E-mail wsparcia</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">Pokazywany użytkownikom przy problemach</span>
                    <Input v-model="form.support_email" type="email" class="mt-1.5" />
                    <span v-if="form.errors.support_email" class="text-xs text-destructive">{{ form.errors.support_email }}</span>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-foreground">Telefon wsparcia</span>
                    <Input v-model="form.support_phone" class="mt-1.5" />
                </label>
            </div>

            <Button type="button" :loading="form.processing" @click="submit">
                <Icons name="check" class="w-4 h-4" />
                Zapisz wygląd
            </Button>
        </div>

        <!-- Podgląd -->
        <aside class="space-y-3">
            <span class="text-sm font-medium text-foreground">Podgląd</span>
            <div class="rounded-xl border border-border overflow-hidden">
                <div class="p-4 flex items-center gap-3" :style="{ background: previewBackground }">
                    <div class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center text-white font-bold">
                        {{ initial }}
                    </div>
                    <div class="text-white">
                        <div class="text-sm font-semibold leading-tight">{{ form.name }}</div>
                        <div class="text-[11px] opacity-80">{{ setup.company.company_name || 'Twoja firma' }}</div>
                    </div>
                </div>
                <div class="p-4 space-y-2 bg-surface">
                    <div class="h-2 w-3/4 rounded bg-surface-elevated"></div>
                    <div class="h-2 w-1/2 rounded bg-surface-elevated"></div>
                    <div class="h-8 w-28 rounded-md flex items-center justify-center text-white text-xs font-medium" :style="{ background: previewBackground }">
                        Przycisk
                    </div>
                </div>
            </div>
            <p class="text-xs text-foreground-subtle">
                Kolory zaczną obowiązywać w całej aplikacji po zapisaniu.
            </p>
        </aside>
    </div>
</template>
