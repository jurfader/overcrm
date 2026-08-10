<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';

const props = defineProps({
    setup: { type: Object, required: true },
    marketplace: { type: Object, default: null },
});

const emit = defineEmits(['next']);

const installing = ref(null);
const loading = ref(false);

const data = computed(() => props.marketplace || { installed: [], remote: [] });
const available = computed(() => data.value.remote.filter((plugin) => !plugin.installed));
const installed = computed(() => data.value.installed.filter((module) => !module.is_core));

/** Lista modułów jest propem opcjonalnym — dociągamy ją po wejściu w ten krok. */
function loadMarketplace() {
    loading.value = true;
    router.reload({
        only: ['marketplace'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loading.value = false; },
    });
}

onMounted(() => {
    if (!props.marketplace) loadMarketplace();
});

function install(plugin) {
    installing.value = plugin.id;
    router.post(route('setup.modules.install'), { plugin_id: plugin.id }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            installing.value = null;
            loadMarketplace();
        },
    });
}

function submit() {
    emit('next');
}

function skip() {
    router.post(route('setup.skip'), { step: 'modules' }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

defineExpose({ submit, skip });
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-foreground-muted">
            Rozszerzenia (fakturowanie, telefonia, leady, raporty) instalujesz z marketplace OVERMEDIA.
            Możesz to zrobić teraz albo później w <span class="text-foreground">Panel administracyjny → Marketplace</span>.
        </p>

        <div v-if="loading" class="flex items-center gap-3 text-sm text-foreground-muted surface-elevated rounded-lg p-4">
            <Icons name="spinner" class="w-4 h-4 animate-spin" />
            Pobieranie listy modułów z serwera OVERMEDIA…
        </div>

        <div v-else-if="data.error" class="flex items-start gap-3 rounded-lg bg-warning/10 border border-warning/30 p-4 text-sm">
            <Icons name="alert" class="w-5 h-5 text-warning shrink-0 mt-0.5" />
            <div class="flex-1">
                <p class="font-medium text-foreground">{{ data.error }}</p>
                <p class="text-foreground-muted mt-1">Pomiń ten krok — moduły doinstalujesz, gdy połączenie wróci.</p>
            </div>
            <Button type="button" variant="outline" size="sm" @click="loadMarketplace">
                <Icons name="refresh" class="w-3.5 h-3.5" />
                Ponów
            </Button>
        </div>

        <!-- Zainstalowane -->
        <section v-if="installed.length" class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Zainstalowane</h3>
            <ul class="space-y-2">
                <li v-for="module in installed" :key="module.name"
                    class="surface-elevated rounded-lg p-3 flex items-center gap-3">
                    <Icons :name="module.icon || 'puzzle'" class="w-5 h-5 text-brand-primary shrink-0" />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-foreground truncate">{{ module.display_name }}</div>
                        <div class="text-xs text-foreground-muted truncate">wersja {{ module.version }}</div>
                    </div>
                    <span :class="[
                        'text-xs px-2 py-0.5 rounded-full border',
                        module.is_active
                            ? 'bg-success/15 text-success border-success/30'
                            : 'bg-foreground-muted/15 text-foreground-muted border-border',
                    ]">
                        {{ module.is_active ? 'aktywny' : 'nieaktywny' }}
                    </span>
                </li>
            </ul>
        </section>

        <!-- Dostępne -->
        <section v-if="!loading" class="space-y-2">
            <h3 class="text-sm font-semibold text-foreground">Dostępne w marketplace</h3>

            <div v-if="!available.length" class="text-sm text-foreground-subtle surface-elevated rounded-lg p-4 text-center">
                Brak modułów do zainstalowania dla tej licencji.
            </div>

            <ul v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <li v-for="plugin in available" :key="plugin.id"
                    class="surface-elevated rounded-lg p-4 flex flex-col gap-3">
                    <div class="flex items-start gap-3">
                        <Icons :name="plugin.icon || 'puzzle'" class="w-5 h-5 text-brand-primary shrink-0 mt-0.5" />
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-foreground">{{ plugin.display_name }}</div>
                            <p class="text-xs text-foreground-muted mt-0.5 line-clamp-3">{{ plugin.description }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-2 mt-auto">
                        <span class="text-xs text-foreground-subtle">
                            v{{ plugin.version }}
                            <template v-if="plugin.price"> · {{ plugin.price }} {{ plugin.currency }}</template>
                        </span>
                        <Button type="button" size="sm" variant="outline"
                                :loading="installing === plugin.id"
                                @click="install(plugin)">
                            <Icons name="plus" class="w-3.5 h-3.5" />
                            Zainstaluj
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <p class="text-xs text-foreground-subtle">
            Po instalacji modułu frontend jest przebudowywany w tle (~30 s). Jeśli strona modułu nie działa po odświeżeniu,
            uruchom na serwerze <span class="font-mono">npm run build</span>.
        </p>
    </div>
</template>
