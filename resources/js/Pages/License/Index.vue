<script setup>
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    license: { type: Object, required: true },
    domain:  { type: String, required: true },
});

const form = useForm({ license_key: '' });
const refreshing = ref(false);

const statusBadge = computed(() => {
    const map = {
        active:  { label: 'Aktywna',     class: 'bg-success/15 text-success border-success/30' },
        grace:   { label: 'Karencja',    class: 'bg-warning/15 text-warning border-warning/30' },
        expired: { label: 'Wygasła',     class: 'bg-destructive/15 text-destructive border-destructive/30' },
        invalid: { label: 'Nieprawidłowa', class: 'bg-destructive/15 text-destructive border-destructive/30' },
        missing: { label: 'Brak klucza',  class: 'bg-foreground-muted/15 text-foreground-muted border-border' },
    };
    return map[props.license.status] || map.missing;
});

function submit() {
    form.post(route('license.activate'), { preserveScroll: true });
}

function refresh() {
    refreshing.value = true;
    router.post(route('license.refresh'), {}, {
        preserveScroll: true,
        onFinish: () => { refreshing.value = false; },
    });
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="Licencja" />

    <div class="min-h-screen flex items-center justify-center p-6 bg-background">
        <div class="w-full max-w-2xl space-y-6">
            <!-- Header -->
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl gradient-brand mb-4">
                    <Icons name="lock" class="w-8 h-8 text-white" />
                </div>
                <h1 class="text-3xl font-bold gradient-brand-text">Licencja OVERCRM</h1>
                <p class="text-foreground-muted text-sm mt-2">
                    Domena: <span class="font-mono text-foreground">{{ domain }}</span>
                </p>
            </div>

            <!-- Status card -->
            <div class="glass-card rounded-xl p-6 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-lg font-semibold text-foreground">Status licencji</h2>
                    <span :class="['inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border', statusBadge.class]">
                        {{ statusBadge.label }}
                    </span>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div class="surface-elevated rounded-md p-3">
                        <dt class="text-xs text-foreground-muted">Klucz</dt>
                        <dd class="font-mono text-foreground mt-0.5">{{ license.key || '—' }}</dd>
                    </div>
                    <div class="surface-elevated rounded-md p-3">
                        <dt class="text-xs text-foreground-muted">Plan</dt>
                        <dd class="text-foreground mt-0.5 capitalize">{{ license.plan || '—' }}</dd>
                    </div>
                    <div class="surface-elevated rounded-md p-3">
                        <dt class="text-xs text-foreground-muted">Wygasa</dt>
                        <dd class="text-foreground mt-0.5">{{ formatDate(license.expires_at) }}</dd>
                    </div>
                    <div class="surface-elevated rounded-md p-3">
                        <dt class="text-xs text-foreground-muted">Ostatnia weryfikacja</dt>
                        <dd class="text-foreground mt-0.5">{{ formatDate(license.last_check_at) }}</dd>
                    </div>
                    <div v-if="license.grace_until" class="surface-elevated rounded-md p-3 sm:col-span-2 border border-warning/30">
                        <dt class="text-xs text-warning">Tryb karencji do</dt>
                        <dd class="text-foreground mt-0.5">{{ formatDate(license.grace_until) }}</dd>
                        <dd class="text-xs text-foreground-muted mt-1">Serwer licencji jest niedostępny — aplikacja działa do tej daty bez połączenia.</dd>
                    </div>
                    <div v-if="license.last_error" class="rounded-md p-3 bg-destructive/10 border border-destructive/30 sm:col-span-2">
                        <dt class="text-xs text-destructive">Ostatni błąd</dt>
                        <dd class="text-sm text-foreground mt-0.5 font-mono">{{ license.last_error }}</dd>
                    </div>
                </dl>

                <div v-if="license.is_valid" class="flex items-center gap-2 text-sm text-success bg-success/10 rounded-lg p-3">
                    <Icons name="check" class="w-4 h-4" />
                    Licencja jest ważna — możesz korzystać z aplikacji.
                </div>
            </div>

            <!-- Activate / Update key -->
            <div class="glass-card rounded-xl p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-foreground">
                        {{ license.key ? 'Zmień klucz licencji' : 'Wpisz klucz licencji' }}
                    </h2>
                    <p class="text-xs text-foreground-muted mt-1">
                        Klucz otrzymujesz po zakupie planu w OVERMEDIA. Po wpisaniu zostanie zwalidowany online.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-3">
                    <Input
                        v-model="form.license_key"
                        placeholder="XXXX-XXXX-XXXX-XXXX"
                        class="font-mono uppercase tracking-wider"
                        autocomplete="off"
                        required
                    />
                    <div v-if="form.errors.license_key" class="text-xs text-destructive">{{ form.errors.license_key }}</div>

                    <div class="flex gap-2 flex-wrap">
                        <Button type="submit" :loading="form.processing" :disabled="!form.license_key.trim()">
                            <Icons name="check" class="w-4 h-4" />
                            Aktywuj licencję
                        </Button>
                        <Button v-if="license.key" type="button" variant="outline" @click="refresh" :loading="refreshing">
                            <Icons name="refresh" class="w-4 h-4" />
                            Odśwież walidację
                        </Button>
                    </div>
                </form>
            </div>

            <!-- Footer info -->
            <div class="text-center text-xs text-foreground-subtle">
                Problem z licencją? Skontaktuj się z
                <a :href="`mailto:support@overmedia.pl?subject=Licencja ${domain}`" class="text-brand-primary hover:underline">support@overmedia.pl</a>
            </div>
        </div>
    </div>
</template>
