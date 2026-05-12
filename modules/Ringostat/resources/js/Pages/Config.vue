<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    status: { type: Object, required: true },
});

const form = useForm({ auth_key: '', project_id: props.status.project_id || '' });
const testing = ref(false);
const syncing = ref(false);

function save() {
    form.post(route('ringostat.credentials'), {
        preserveScroll: true,
        onSuccess: () => { form.auth_key = ''; },
    });
}

function testConnection() {
    testing.value = true;
    router.post(route('ringostat.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
}

function syncAll() {
    if (!confirm('Synchronizować wszystkich klientów do Ringostat Smart Phone? Operacja może potrwać kilka minut.')) return;
    syncing.value = true;
    router.post(route('ringostat.sync-all'), {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}

function copyWebhook() {
    navigator.clipboard?.writeText(props.status.webhook_url);
}
</script>

<template>
    <Head title="Ringostat — konfiguracja" />

    <div class="space-y-6 max-w-3xl">
        <div>
            <h1 class="text-2xl font-bold gradient-brand-text">Ringostat — konfiguracja</h1>
            <p class="text-sm text-foreground-muted mt-0.5">
                Integracja z <a href="https://ringostat.com" target="_blank" class="text-brand-primary hover:underline">ringostat.com</a> (call tracking, webhooks, AI nagrań).
                Alternatywa dla Play Centrali — możesz mieć tylko jeden VoIP gateway aktywny.
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
            <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Auth-key</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.auth_key_mask || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Project ID</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.project_id || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3 sm:col-span-3">
                    <dt class="text-xs text-foreground-muted">Webhook URL (do skopiowania w panelu Ringostat)</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5 flex items-center gap-2">
                        <span class="truncate">{{ status.webhook_url }}</span>
                        <button @click="copyWebhook" class="shrink-0 text-brand-primary hover:underline">Kopiuj</button>
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="testConnection" :loading="testing" :disabled="!status.configured">
                    <Icons name="check" class="w-4 h-4" />
                    Test połączenia
                </Button>
                <Button variant="outline" @click="syncAll" :loading="syncing" :disabled="!status.configured">
                    <Icons name="upload" class="w-4 h-4" />
                    Synchronizuj klientów do Smart Phone
                </Button>
            </div>
        </section>

        <!-- Form -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-foreground">Dane dostępowe</h2>
                <p class="text-xs text-foreground-muted mt-0.5">
                    Znajdziesz w panelu Ringostat → <strong class="text-foreground">Integracje → API i Webhooks</strong>.
                </p>
            </div>
            <form @submit.prevent="save" class="space-y-3">
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Klucz autoryzacyjny (auth-key)</label>
                    <Input v-model="form.auth_key" type="password" :placeholder="status.auth_key_mask || 'Wklej klucz Ringostat'" />
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Project ID</label>
                    <Input v-model="form.project_id" type="number" placeholder="np. 191066" />
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
                Dostępne integracje: click-to-call (callback API), eksport połączeń (calls/list), statusy SIP online/speaking,
                synchronizacja kontaktów + organizacji do Ringostat Smart Phone (minicrm), webhook odbiorczy dla zdarzeń połączeń.
            </p>
        </div>
    </div>
</template>
