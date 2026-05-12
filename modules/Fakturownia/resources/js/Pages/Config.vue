<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    status: { type: Object, required: true },
});

const form = useForm({
    subdomain: props.status.subdomain || '',
    api_token: '',
});

const testing = ref(false);

function save() {
    form.post(route('fakturownia.credentials'), {
        preserveScroll: true,
        onSuccess: () => { form.api_token = ''; },
    });
}

function testConnection() {
    testing.value = true;
    router.post(route('fakturownia.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
}
</script>

<template>
    <Head title="Fakturownia — konfiguracja" />

    <div class="space-y-6 max-w-3xl">
        <div>
            <h1 class="text-2xl font-bold gradient-brand-text">Fakturownia — konfiguracja</h1>
            <p class="text-sm text-foreground-muted mt-0.5">
                API token z Fakturownia.pl. Po skonfigurowaniu wybierz "Fakturownia" jako provider faktur w
                <a :href="route('admin.settings.index')" class="text-brand-primary hover:underline">Ustawienia → Integracje</a>.
            </p>
        </div>

        <!-- Status -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-foreground">Status</h2>
                <span :class="['inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border',
                              status.configured
                                  ? 'bg-success/15 text-success border-success/30'
                                  : 'bg-warning/15 text-warning border-warning/30']">
                    {{ status.configured ? 'Skonfigurowany' : 'Wymaga konfiguracji' }}
                </span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Subdomena</dt>
                    <dd class="font-mono text-foreground mt-0.5">{{ status.subdomain || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">API token</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.api_token_mask || '—' }}</dd>
                </div>
            </dl>
            <Button variant="outline" @click="testConnection" :loading="testing" :disabled="!status.configured">
                <Icons name="check" class="w-4 h-4" />
                Test połączenia
            </Button>
        </section>

        <!-- Form -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-foreground">Dane dostępowe</h2>
                <p class="text-xs text-foreground-muted mt-0.5">
                    Token znajdziesz w panelu Fakturownia → <strong class="text-foreground">Ustawienia konta → Integracje → Kod autoryzujący API</strong>.
                </p>
            </div>
            <form @submit.prevent="save" class="space-y-3">
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Subdomena</label>
                    <Input v-model="form.subdomain" placeholder="moja-firma (z URL https://moja-firma.fakturownia.pl)" required />
                    <p v-if="form.errors.subdomain" class="text-xs text-destructive">{{ form.errors.subdomain }}</p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">API token</label>
                    <Input v-model="form.api_token" type="password" :placeholder="status.api_token_mask || 'Pozostaw puste aby nie zmieniać'" />
                    <p class="text-xs text-foreground-muted">Aktualnie zapisany: <span class="font-mono">{{ status.api_token_mask || '—' }}</span></p>
                </div>
                <Button type="submit" :loading="form.processing">
                    <Icons name="check" class="w-4 h-4" />
                    Zapisz
                </Button>
            </form>
        </section>

        <div class="rounded-lg p-3 bg-info/10 border border-info/30 flex gap-2 text-xs text-foreground">
            <Icons name="info" class="w-4 h-4 text-info shrink-0 mt-0.5" />
            <p>
                Fakturownia używa prostego API tokenu (bez OAuth) — nie ma potrzeby odświeżania.
                Token jest długoterminowy. Jeśli stracisz go lub zostanie zresetowany w panelu Fakturownia,
                wpisz nowy w polu wyżej.
            </p>
        </div>
    </div>
</template>
