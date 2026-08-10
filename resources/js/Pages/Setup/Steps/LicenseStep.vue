<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['next']);

const form = useForm({ license_key: '' });
const refreshing = ref(false);

const license = computed(() => props.setup.license);

const statusBadge = computed(() => ({
    active:  { label: 'Aktywna',       class: 'bg-success/15 text-success border-success/30' },
    grace:   { label: 'Karencja',      class: 'bg-warning/15 text-warning border-warning/30' },
    expired: { label: 'Wygasła',       class: 'bg-destructive/15 text-destructive border-destructive/30' },
    invalid: { label: 'Nieprawidłowa', class: 'bg-destructive/15 text-destructive border-destructive/30' },
    missing: { label: 'Brak klucza',   class: 'bg-foreground-muted/15 text-foreground-muted border-border' },
}[license.value.status] || { label: 'Brak klucza', class: 'bg-foreground-muted/15 text-foreground-muted border-border' }));

function activate() {
    form.post(route('setup.license'), { preserveScroll: true, preserveState: true });
}

function refresh() {
    refreshing.value = true;
    router.post(route('setup.license.refresh'), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { refreshing.value = false; },
    });
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Krok obowiązkowy — „Dalej" przepuszcza tylko z ważną licencją. */
function submit() {
    if (license.value.is_valid) emit('next');
}

defineExpose({ submit });
</script>

<template>
    <div class="space-y-5">
        <div v-if="license.is_valid" class="flex items-start gap-3 rounded-lg bg-success/10 border border-success/30 p-4">
            <Icons name="check-circle" class="w-5 h-5 text-success shrink-0 mt-0.5" />
            <div class="text-sm">
                <p class="font-medium text-foreground">Licencja jest aktywna — możesz przejść dalej.</p>
                <p class="text-foreground-muted mt-1">
                    Plan: <span class="text-foreground capitalize">{{ license.plan || '—' }}</span> ·
                    ważna do: <span class="text-foreground">{{ formatDate(license.expires_at) }}</span>
                </p>
            </div>
        </div>

        <div v-else class="flex items-start gap-3 rounded-lg bg-warning/10 border border-warning/30 p-4">
            <Icons name="alert" class="w-5 h-5 text-warning shrink-0 mt-0.5" />
            <div class="text-sm">
                <p class="font-medium text-foreground">Wpisz klucz licencyjny otrzymany od OVERMEDIA.</p>
                <p class="text-foreground-muted mt-1">
                    Klucz zostanie przypisany do domeny <span class="font-mono text-foreground">{{ setup.domain }}</span>.
                    Bez ważnej licencji aplikacja pozostanie zablokowana.
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
            <div class="surface-elevated rounded-md p-3">
                <dt class="text-xs text-foreground-muted">Status</dt>
                <dd class="mt-1">
                    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border', statusBadge.class]">
                        {{ statusBadge.label }}
                    </span>
                </dd>
            </div>
            <div class="surface-elevated rounded-md p-3">
                <dt class="text-xs text-foreground-muted">Klucz</dt>
                <dd class="font-mono text-foreground mt-1">{{ license.key || '—' }}</dd>
            </div>
            <div class="surface-elevated rounded-md p-3">
                <dt class="text-xs text-foreground-muted">Ostatnia weryfikacja</dt>
                <dd class="text-foreground mt-1">{{ formatDate(license.last_check_at) }}</dd>
            </div>
        </dl>

        <div v-if="license.last_error" class="rounded-md p-3 bg-destructive/10 border border-destructive/30 text-sm">
            <span class="text-xs text-destructive block">Ostatni błąd</span>
            <span class="font-mono text-foreground">{{ license.last_error }}</span>
        </div>

        <form @submit.prevent="activate" class="space-y-3">
            <label class="block">
                <span class="text-sm font-medium text-foreground">
                    {{ license.key ? 'Zmień klucz licencyjny' : 'Klucz licencyjny' }}
                </span>
            </label>
            <div class="flex gap-2 flex-wrap">
                <Input
                    v-model="form.license_key"
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                    class="font-mono uppercase tracking-wider max-w-sm"
                    autocomplete="off"
                />
                <Button type="submit" :loading="form.processing" :disabled="!form.license_key.trim()">
                    <Icons name="check" class="w-4 h-4" />
                    Aktywuj
                </Button>
                <Button v-if="license.key" type="button" variant="outline" :loading="refreshing" @click="refresh">
                    <Icons name="refresh" class="w-4 h-4" />
                    Sprawdź ponownie
                </Button>
            </div>
            <p v-if="form.errors.license_key" class="text-xs text-destructive">{{ form.errors.license_key }}</p>
        </form>
    </div>
</template>
