<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    status: { type: Object, required: true },
    credentials: { type: Object, required: true },
});

const credForm = useForm({
    subdomain:     props.credentials.subdomain || '',
    client_id:     props.credentials.client_id || '',
    client_secret: '',
});

const authForm = useForm({
    authorization_code: '',
});

const refreshing = ref(false);
const testing = ref(false);

function saveCredentials() {
    credForm.post(route('apilo.credentials'), {
        preserveScroll: true,
        onSuccess: () => { credForm.client_secret = ''; },
    });
}

function authorize() {
    authForm.post(route('apilo.authorize'), {
        preserveScroll: true,
        onSuccess: () => { authForm.authorization_code = ''; },
    });
}

function refresh() {
    refreshing.value = true;
    router.post(route('apilo.refresh'), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
}

function testConnection() {
    testing.value = true;
    router.post(route('apilo.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
}

const tokenStatusBadge = computed(() => {
    if (!props.status.has_access_token) return { label: 'Brak tokenu', class: 'bg-foreground-muted/15 text-foreground-muted' };
    const min = props.status.expires_in_minutes;
    if (min === null || min === undefined) return { label: 'Aktywny', class: 'bg-success/15 text-success' };
    if (min < 0) return { label: 'Wygasł', class: 'bg-destructive/15 text-destructive' };
    if (min < 30) return { label: `Wygasa za ${min} min`, class: 'bg-warning/15 text-warning' };
    if (min < 1440) return { label: `Wygasa za ${Math.floor(min / 60)}h`, class: 'bg-success/15 text-success' };
    return { label: `Wygasa za ${Math.floor(min / 1440)} dni`, class: 'bg-success/15 text-success' };
});

function formatDate(d) {
    if (!d) return '—';
    try {
        return new Date(d).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
    } catch { return d; }
}
</script>

<template>
    <Head title="Apilo — konfiguracja" />

    <div class="space-y-6 max-w-3xl">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-bold gradient-brand-text">Apilo — konfiguracja</h1>
            <p class="text-sm text-foreground-muted mt-0.5">
                Dane dostępowe API + autoryzacja OAuth + zarządzanie tokenami.
                Po skonfigurowaniu wybierz "Apilo" jako provider w
                <a :href="route('admin.settings.index')" class="text-brand-primary hover:underline">Ustawienia → Integracje</a>.
            </p>
        </div>

        <!-- Status tokenu -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-base font-semibold text-foreground">Status tokenu</h2>
                <span :class="['inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border', tokenStatusBadge.class]">
                    {{ tokenStatusBadge.label }}
                </span>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Subdomena</dt>
                    <dd class="font-mono text-foreground mt-0.5">{{ status.subdomain || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Skonfigurowany</dt>
                    <dd class="text-foreground mt-0.5">
                        <span v-if="status.configured" class="text-success">✓ Tak</span>
                        <span v-else class="text-warning">✗ Brak Client ID/Secret/subdomain</span>
                    </dd>
                </div>
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Access token</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.access_token_mask || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3">
                    <dt class="text-xs text-foreground-muted">Refresh token</dt>
                    <dd class="font-mono text-xs text-foreground mt-0.5">{{ status.refresh_token_mask || '—' }}</dd>
                </div>
                <div class="surface-elevated rounded-md p-3 sm:col-span-2">
                    <dt class="text-xs text-foreground-muted">Wygasa</dt>
                    <dd class="text-foreground mt-0.5">{{ formatDate(status.expires_at) }}</dd>
                </div>
            </dl>

            <div class="flex gap-2 flex-wrap">
                <Button variant="outline" @click="refresh" :loading="refreshing" :disabled="!status.has_refresh_token">
                    <Icons name="refresh" class="w-4 h-4" />
                    Odśwież token
                </Button>
                <Button variant="outline" @click="testConnection" :loading="testing" :disabled="!status.has_access_token">
                    <Icons name="check" class="w-4 h-4" />
                    Test połączenia
                </Button>
            </div>
        </section>

        <!-- Credentials -->
        <section class="glass-card rounded-xl p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold text-foreground">Dane dostępowe</h2>
                <p class="text-xs text-foreground-muted mt-0.5">Z panelu Apilo: Administracja → API.</p>
            </div>
            <form @submit.prevent="saveCredentials" class="space-y-3">
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Subdomena</label>
                    <Input v-model="credForm.subdomain" placeholder="moja-firma (z URL https://moja-firma.apilo.com)" required />
                    <p v-if="credForm.errors.subdomain" class="text-xs text-destructive">{{ credForm.errors.subdomain }}</p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Client ID</label>
                    <Input v-model="credForm.client_id" required />
                    <p v-if="credForm.errors.client_id" class="text-xs text-destructive">{{ credForm.errors.client_id }}</p>
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-foreground">Client Secret</label>
                    <Input v-model="credForm.client_secret" type="password" :placeholder="credentials.client_secret || 'Pozostaw puste aby nie zmieniać'" />
                    <p class="text-xs text-foreground-muted">Aktualnie zapisany: <span class="font-mono">{{ credentials.client_secret || '—' }}</span></p>
                </div>
                <div>
                    <Button type="submit" :loading="credForm.processing">
                        <Icons name="check" class="w-4 h-4" />
                        Zapisz dane
                    </Button>
                </div>
            </form>
        </section>

        <!-- Authorization -->
        <section class="glass-card rounded-xl p-6 space-y-4 border border-warning/30">
            <div>
                <h2 class="text-base font-semibold text-warning flex items-center gap-2">
                    <Icons name="lock" class="w-4 h-4" />
                    Autoryzacja OAuth (jednorazowo)
                </h2>
                <p class="text-xs text-foreground-muted mt-1 leading-relaxed">
                    1. Najpierw zapisz dane (powyżej).<br>
                    2. W panelu Apilo: <strong class="text-foreground">Administracja → API → Wygeneruj kod autoryzacji</strong>.<br>
                    3. Wklej kod poniżej i kliknij <strong class="text-foreground">Autoryzuj</strong> — kod jest jednorazowy!
                    Po autoryzacji refresh token zostanie zapisany i CRM będzie odświeżał access token automatycznie.
                </p>
            </div>
            <form @submit.prevent="authorize" class="flex gap-2 flex-wrap">
                <Input
                    v-model="authForm.authorization_code"
                    placeholder="Wklej kod autoryzacji z Apilo..."
                    class="flex-1 min-w-[200px] font-mono"
                    required
                />
                <Button type="submit" variant="warning" :loading="authForm.processing" :disabled="!authForm.authorization_code.trim()">
                    <Icons name="lock" class="w-4 h-4" />
                    Autoryzuj
                </Button>
            </form>
            <p v-if="authForm.errors.authorization_code" class="text-xs text-destructive">{{ authForm.errors.authorization_code }}</p>
        </section>

        <!-- Hint -->
        <div class="rounded-lg p-3 bg-info/10 border border-info/30 flex gap-2 text-xs text-foreground">
            <Icons name="info" class="w-4 h-4 text-info shrink-0 mt-0.5" />
            <p>
                Access token Apilo wygasa zwykle po 24h. CRM odświeża go automatycznie przy każdym
                wywołaniu API używając refresh tokenu. Możesz wymusić odświeżenie ręcznie przyciskiem
                „Odśwież token" wyżej. Jeśli refresh token wygaśnie (rzadko, po długim braku aktywności),
                trzeba ponownie pobrać kod autoryzacji z panelu Apilo.
            </p>
        </div>
    </div>
</template>
