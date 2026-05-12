<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    marketplace: { type: Object, required: true },
});

const tab = ref('installed');
const installing = ref(null); // plugin_id w trakcie pobierania

const installed = computed(() => props.marketplace.installed || []);
const remote = computed(() => props.marketplace.remote || []);

// Remote z odznaczeniem zainstalowanych
const remoteAvailable = computed(() => remote.value.filter(r => !r.installed));
const remoteInstalled = computed(() => remote.value.filter(r => r.installed));

function toggleModule(module) {
    if (module.is_core) return;
    const route_name = module.is_active ? 'admin.modules.deactivate' : 'admin.modules.activate';
    router.post(route(route_name, module.id), {}, { preserveScroll: true });
}

function installRemote(plugin) {
    if (!confirm(`Zainstalować moduł "${plugin.display_name}" (v${plugin.version})?`)) return;
    installing.value = plugin.id;
    router.post(route('admin.marketplace.install'), { plugin_id: plugin.id }, {
        preserveScroll: true,
        onFinish: () => { installing.value = null; },
    });
}

function fmtPrice(price, currency) {
    if (!price || price === 0) return 'Darmowy';
    return ((price ?? 0) / 100).toLocaleString('pl-PL', { style: 'currency', currency: currency || 'PLN' });
}

// Modul moze miec wlasna strone konfiguracji (np. 'infakt.config' z multi-step
// OAuth flow). Inaczej generic admin.modules.show renderuje pola z manifestu.
// Try-catch bo Ziggy rzuca jak route'u nie ma w bundle (modul zainstalowany,
// ale strona JS jeszcze nie zbudowana — fallback graceful do generic).
function configLink(m) {
    if (m.config_route) {
        try { return route(m.config_route); } catch (e) { /* fallthrough */ }
    }
    return route('admin.modules.show', m.id);
}
</script>

<template>
    <Head title="Marketplace modułów" />

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold gradient-brand-text">Moduły</h1>
            <p class="text-foreground-muted text-sm mt-1">
                Przeglądaj, instaluj i konfiguruj moduły rozszerzające funkcjonalność CRM.
            </p>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 border-b border-border">
            <button
                @click="tab = 'installed'"
                :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px transition',
                         tab === 'installed' ? 'border-brand-primary text-foreground' : 'border-transparent text-foreground-muted hover:text-foreground']">
                Zainstalowane ({{ installed.length }})
            </button>
            <button
                @click="tab = 'shop'"
                :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px transition',
                         tab === 'shop' ? 'border-brand-primary text-foreground' : 'border-transparent text-foreground-muted hover:text-foreground']">
                Sklep modułów ({{ remoteAvailable.length }})
            </button>
        </div>

        <!-- Installed -->
        <div v-if="tab === 'installed'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="m in installed" :key="m.id" class="glass-card p-5 flex flex-col gap-3">
                <div class="flex items-start gap-3">
                    <div :class="['p-2.5 rounded-lg shrink-0',
                                  m.is_active ? 'bg-success/15' : 'surface-elevated']">
                        <Icons :name="m.icon || 'puzzle'"
                               :class="['w-5 h-5', m.is_active ? 'text-success' : 'text-foreground-muted']" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-foreground truncate">{{ m.display_name }}</h3>
                        <p class="text-xs text-foreground-muted">v{{ m.version }} · {{ m.author || 'OVERMEDIA' }}</p>
                    </div>
                    <button @click="toggleModule(m)" :disabled="m.is_core"
                            :class="['relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors',
                                     m.is_active ? 'bg-success' : 'bg-surface-3',
                                     m.is_core ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer']">
                        <span :class="['inline-block h-4 w-4 rounded-full bg-white shadow transition',
                                       m.is_active ? 'translate-x-4' : 'translate-x-0']" />
                    </button>
                </div>
                <p class="text-sm text-foreground-muted line-clamp-2 flex-1">{{ m.description || 'Brak opisu' }}</p>
                <div class="flex items-center gap-2 flex-wrap">
                    <span v-if="m.is_core"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-info/15 text-info">
                        Systemowy
                    </span>
                    <span v-if="m.is_active && !m.is_core"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-success/15 text-success">
                        Aktywny
                    </span>
                    <span v-else-if="!m.is_active"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-3 text-foreground-muted">
                        Nieaktywny
                    </span>
                    <span v-if="!m.exists_on_disk"
                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-warning/15 text-warning">
                        Brak na dysku
                    </span>
                    <Link :href="configLink(m)"
                          class="ml-auto text-xs text-brand-primary hover:underline">
                        Konfiguracja →
                    </Link>
                </div>
            </div>
            <div v-if="installed.length === 0"
                 class="col-span-full text-center py-12 text-foreground-muted">
                Brak zainstalowanych modułów. Przejdź do zakładki <button @click="tab = 'shop'" class="text-brand-primary hover:underline">Sklep modułów</button>.
            </div>
        </div>

        <!-- Shop / Remote -->
        <div v-else-if="tab === 'shop'" class="space-y-4">
            <div v-if="remoteAvailable.length === 0 && remoteInstalled.length === 0"
                 class="glass-card p-12 text-center text-foreground-muted">
                <Icons name="info" class="w-8 h-8 mx-auto mb-3 text-foreground-muted" />
                <p class="text-sm">
                    Brak dostępnych modułów w sklepie.
                </p>
                <p class="text-xs mt-2">
                    Sprawdź połączenie z serwerem licencji lub aktywuj licencję w
                    <Link href="/admin/license" class="text-brand-primary hover:underline">Ustawienia → Licencja</Link>.
                </p>
            </div>
            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="r in remoteAvailable" :key="r.id" class="glass-card p-5 flex flex-col gap-3">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-lg bg-brand-primary/15 shrink-0">
                            <Icons :name="r.icon || 'puzzle'" class="w-5 h-5 text-brand-primary" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-foreground truncate">{{ r.display_name }}</h3>
                            <p class="text-xs text-foreground-muted">v{{ r.version }} · {{ r.author }}</p>
                        </div>
                    </div>
                    <p class="text-sm text-foreground-muted line-clamp-3 flex-1">{{ r.description || 'Brak opisu' }}</p>
                    <div class="flex items-center justify-between gap-3 mt-auto">
                        <div class="text-sm">
                            <div class="font-medium text-foreground">{{ fmtPrice(r.price, r.currency) }}</div>
                            <div v-if="r.required_plan" class="text-xs text-foreground-muted">
                                Wymaga: {{ r.required_plan }}
                            </div>
                        </div>
                        <button @click="installRemote(r)"
                                :disabled="installing === r.id"
                                class="px-3 py-1.5 text-sm gradient-brand text-white rounded-md hover:opacity-90 disabled:opacity-50">
                            <span v-if="installing === r.id">Instaluję...</span>
                            <span v-else>Zainstaluj</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Already installed remote -->
            <div v-if="remoteInstalled.length > 0" class="space-y-2">
                <h3 class="text-sm font-semibold text-foreground-muted mt-6 mb-2">
                    Już zainstalowane ({{ remoteInstalled.length }})
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="r in remoteInstalled" :key="r.id"
                         class="glass-card p-4 opacity-60 flex items-center gap-3">
                        <Icons :name="r.icon || 'puzzle'" class="w-5 h-5 text-foreground-muted shrink-0" />
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm text-foreground truncate">{{ r.display_name }}</div>
                            <div class="text-xs text-foreground-muted">v{{ r.version }}</div>
                        </div>
                        <Icons name="check" class="w-4 h-4 text-success" />
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
