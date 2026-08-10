<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Switch from '@/Components/UI/Switch.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['next']);

const form = useForm({
    preset: 'sales',
    sample_data: false,
});

const counts = computed(() => props.setup.baseline.counts);
const alreadyPrepared = computed(() => counts.value.statuses > 0 && counts.value.permissions > 0);
const hasClients = computed(() => counts.value.clients > 0);

function apply() {
    form.post(route('setup.baseline'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

/** Krok obowiązkowy — dalej tylko gdy statusy istnieją. */
function submit() {
    if (alreadyPrepared.value) {
        emit('next');
        return;
    }
    apply();
}

defineExpose({ submit });
</script>

<template>
    <div class="space-y-5">
        <div v-if="alreadyPrepared" class="flex items-start gap-3 rounded-lg bg-success/10 border border-success/30 p-4 text-sm">
            <Icons name="check-circle" class="w-5 h-5 text-success shrink-0 mt-0.5" />
            <div>
                <p class="font-medium text-foreground">Dane startowe są już przygotowane.</p>
                <p class="text-foreground-muted mt-1">
                    Statusy: {{ counts.statuses }} · uprawnienia: {{ counts.permissions }} · moduły: {{ counts.modules }}.
                    Możesz uruchomić ponownie — istniejące wpisy nie zostaną nadpisane.
                </p>
            </div>
        </div>

        <p v-else class="text-sm text-foreground-muted">
            Świeża instalacja nie ma jeszcze statusów zadań ani zdefiniowanych uprawnień — bez nich Planner i Kanban
            nie mają czego wyświetlić. Wybierz zestaw statusów pasujący do sposobu pracy firmy.
        </p>

        <!-- Presety statusów -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <button
                v-for="preset in setup.baseline.presets"
                :key="preset.key"
                type="button"
                @click="form.preset = preset.key"
                :class="[
                    'text-left rounded-lg border p-4 transition-colors',
                    form.preset === preset.key
                        ? 'border-brand-primary/50 gradient-subtle'
                        : 'border-border surface-elevated hover:bg-surface-hover',
                ]"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-foreground">{{ preset.label }}</span>
                    <Icons v-if="form.preset === preset.key" name="check" class="w-4 h-4 text-brand-primary" />
                </div>
                <p class="text-xs text-foreground-muted mt-1">{{ preset.description }}</p>

                <ul class="mt-3 space-y-1">
                    <li v-for="status in preset.statuses" :key="status.name" class="flex items-center gap-2 text-xs text-foreground-muted">
                        <span class="w-2 h-2 rounded-full shrink-0" :style="{ backgroundColor: status.color }"></span>
                        {{ status.name }}
                    </li>
                </ul>
            </button>
        </div>

        <!-- Dane przykładowe -->
        <div class="surface-elevated rounded-lg p-4 flex items-start gap-3">
            <Switch v-model="form.sample_data" :disabled="hasClients" class="mt-0.5" />
            <div class="text-sm">
                <span class="font-medium text-foreground">Dodaj dane przykładowe</span>
                <p class="text-xs text-foreground-muted mt-0.5">
                    Kilku przykładowych klientów, zadań i wizyt — żeby zobaczyć system „z zawartością".
                    {{ hasClients
                        ? 'Niedostępne: baza klientów nie jest pusta.'
                        : 'Możesz je później usunąć z listy klientów.' }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <Button type="button" :loading="form.processing" @click="apply">
                <Icons name="check" class="w-4 h-4" />
                {{ alreadyPrepared ? 'Uruchom ponownie' : 'Przygotuj dane startowe' }}
            </Button>

            <span class="text-xs text-foreground-subtle">
                Utworzy statusy zadań, komplet uprawnień i wpisy modułów systemowych. Operacja jest bezpieczna do powtórzenia.
            </span>
        </div>
    </div>
</template>
