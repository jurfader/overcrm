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
    api_key: '',
    sandbox: props.status.sandbox || false,
});
const testing = ref(false);

function save() {
    form.post(route('infakt.credentials'), {
        preserveScroll: true,
        onSuccess: () => { form.api_key = ''; },
    });
}

function testConnection() {
    testing.value = true;
    router.post(route('infakt.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
}

function copyWebhook() {
    navigator.clipboard?.writeText(props.status.webhook_url);
}
</script>

<template>
    <Head title="inFakt — konfiguracja" />

    <div class="space-y-6 max-w-3xl">
        <div>
            <h1 class="text-2xl font-bold gradient-brand-text">inFakt — konfiguracja</h1>
            <p class="text-sm text-foreground-muted mt-0.5">
                Integracja z <a href="https://infakt.pl" target="_blank" class="text-brand-primary hover:underline">inFakt.pl</a>
                — faktury VAT, klienci, produkty, koszty, KSeF, webhooks.
            </p>
        </div>

        <!-- Status -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-foreground">Status</h2>
                <div class="flex gap-2">
                    <span v-if="status.sandbox" class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border bg-warning/15 text-warning border-warning/30">
                        Sandbox
                    </span>
                    <span :class="['inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border',
                                  status.configured
                                      ? 'bg-success/15 text-success border-success/30'
                                      : 'bg-warning/15 text-warning border-warning/30']">
                        {{ status.configured ? 'Skonfigurowany' : 'Wymaga konfiguracji' }}
                    </span>
                </div>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">API Key (X-inFakt-ApiKey)</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.api_key_mask || '—' }}</dd>
                </div>
                <div v-if="status.ksef" class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">KSeF integracja</dt>
                    <dd class="text-xs text-foreground mt-0.5">
                        <span :class="status.ksef.active ? 'text-success' : 'text-warning'">
                            {{ status.ksef.active ? 'Aktywna' : 'Nieaktywna' }}
                        </span>
                        <span v-if="status.ksef.incomes_last_fetched_at" class="text-foreground-muted ml-2">
                            (przychody pobrane: {{ new Date(status.ksef.incomes_last_fetched_at).toLocaleString('pl-PL') }})
                        </span>
                    </dd>
                </div>
                <div class="surface-elevated rounded-md p-3 sm:col-span-2">
                    <dt class="text-xs text-foreground-muted">Webhook URL (do skopiowania w panelu inFakt → Webhooki)</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5 flex items-center gap-2">
                        <span class="truncate">{{ status.webhook_url }}</span>
                        <button @click="copyWebhook" class="shrink-0 text-brand-primary hover:underline">Kopiuj</button>
                    </dd>
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
                    Klucz API znajdziesz w panelu inFakt → <strong class="text-foreground">Ustawienia konta → API</strong>.
                    Wymagane scope'y: <code class="text-xs">api:invoices:read</code>, <code class="text-xs">api:invoices:write</code>,
                    <code class="text-xs">api:costs:read</code>.
                </p>
            </div>
            <form @submit.prevent="save" class="space-y-3">
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Klucz API (X-inFakt-ApiKey)</label>
                    <Input v-model="form.api_key" type="password" :placeholder="status.api_key_mask || 'Wklej klucz inFakt'" />
                </div>
                <div class="flex items-center gap-2">
                    <input id="sandbox" type="checkbox" v-model="form.sandbox" class="rounded border-border" />
                    <label for="sandbox" class="text-sm text-foreground">
                        Tryb Sandbox (testowy <span class="font-mono text-xs">api.sandbox-infakt.pl</span>)
                    </label>
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
                Dostępne integracje: tworzenie faktur VAT (asynchroniczne API z poll status), pobieranie PDF, oznaczanie zapłaconych,
                listowanie faktur klienta po NIP (sprawdzanie zaległości), wysyłka mailem, integracja z KSeF, katalog produktów,
                klienci, koszty (upload skanów dokumentów), webhooki dla zdarzeń. Ceny w inFakt są w groszach — konwersja automatyczna.
            </p>
        </div>
    </div>
</template>
