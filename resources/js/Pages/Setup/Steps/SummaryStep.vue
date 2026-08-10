<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['goToStep']);

const finishing = ref(false);

const rows = computed(() => props.setup.steps.map((step, index) => ({
    ...step,
    index,
    detail: describe(step.key),
})));

function describe(key) {
    const s = props.setup;

    if (key === 'license') {
        return s.license.is_valid
            ? `Plan ${s.license.plan || '—'}, ważna do ${formatDate(s.license.expires_at)}`
            : 'Brak ważnej licencji';
    }
    if (key === 'company') {
        return s.company.company_name || 'Dane firmy nieuzupełnione';
    }
    if (key === 'branding') {
        return `${s.brand.name || 'OVERCRM'} · motyw ${s.brand.default_theme === 'light' ? 'jasny' : 'ciemny'}`;
    }
    if (key === 'baseline') {
        const c = s.baseline.counts;
        return `Statusy: ${c.statuses}, uprawnienia: ${c.permissions}, klienci: ${c.clients}`;
    }
    if (key === 'preferences') {
        return `${s.preferences.app_timezone || 'Europe/Warsaw'} · nadawca: ${s.preferences.mail_from_address || 'nie ustawiony'}`;
    }
    if (key === 'modules') {
        const modules = s.installedModules || [];
        return modules.length ? `Moduły: ${modules.join(', ')}` : 'Brak dodatkowych modułów';
    }
    return '';
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('pl-PL', { dateStyle: 'medium' });
}

const skipped = computed(() => rows.value.filter((row) => row.status === 'skipped'));

function finish() {
    finishing.value = true;
    router.post(route('setup.complete'), {}, {
        onFinish: () => { finishing.value = false; },
    });
}
</script>

<template>
    <div class="space-y-6">
        <div class="text-center py-2">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl gradient-brand mb-3">
                <Icons name="check" class="w-7 h-7 text-white" />
            </div>
            <h3 class="text-xl font-semibold text-foreground">Konfiguracja gotowa</h3>
            <p class="text-sm text-foreground-muted mt-1">
                Sprawdź podsumowanie i przejdź do systemu. Wszystko zmienisz później w Ustawieniach.
            </p>
        </div>

        <ul class="space-y-2">
            <li v-for="row in rows" :key="row.key"
                class="surface-elevated rounded-lg p-3 flex items-center gap-3">
                <span :class="[
                    'w-8 h-8 rounded-full flex items-center justify-center shrink-0 border',
                    row.status === 'done'
                        ? 'bg-success/15 border-success/30'
                        : 'bg-surface border-border',
                ]">
                    <Icons :name="row.status === 'done' ? 'check' : (row.icon || 'settings')"
                           :class="['w-4 h-4', row.status === 'done' ? 'text-success' : 'text-foreground-subtle']" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-foreground">{{ row.title }}</div>
                    <div class="text-xs text-foreground-muted truncate">{{ row.detail }}</div>
                </div>

                <Button variant="ghost" size="sm" @click="emit('goToStep', row.index)">
                    {{ row.status === 'skipped' ? 'Uzupełnij' : 'Zmień' }}
                </Button>
            </li>
        </ul>

        <div v-if="skipped.length" class="rounded-lg bg-warning/10 border border-warning/30 p-4 text-sm">
            <p class="font-medium text-foreground flex items-center gap-2">
                <Icons name="info" class="w-4 h-4 text-warning" />
                Pominięte kroki: {{ skipped.map((s) => s.title).join(', ') }}
            </p>
            <p class="text-foreground-muted mt-1">
                Możesz je uzupełnić w Ustawieniach albo uruchomić kreator ponownie
                (Panel administracyjny → Ustawienia → Ogólne).
            </p>
        </div>

        <div class="rounded-lg surface-elevated p-4 text-sm space-y-2">
            <p class="font-medium text-foreground">Co dalej?</p>
            <ul class="text-foreground-muted space-y-1 text-xs list-disc list-inside">
                <li>Dodaj użytkowników i nadaj im uprawnienia (Użytkownicy → Nowy użytkownik)</li>
                <li>Podłącz skrzynki e-mail handlowców (Ustawienia → Konfiguracja poczty)</li>
                <li>Zaimportuj bazę klientów (Klienci → Import)</li>
            </ul>
        </div>

        <div class="flex justify-end">
            <Button size="lg" :loading="finishing" @click="finish">
                <Icons name="check-circle" class="w-5 h-5" />
                Zakończ konfigurację i przejdź do systemu
            </Button>
        </div>
    </div>
</template>
