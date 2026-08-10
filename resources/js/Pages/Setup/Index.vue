<script setup>
import { ref, computed, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import BrandLogo from '@/Components/UI/BrandLogo.vue';
import FlashMessages from '@/Components/FlashMessages.vue';

import LicenseStep from './Steps/LicenseStep.vue';
import CompanyStep from './Steps/CompanyStep.vue';
import BrandingStep from './Steps/BrandingStep.vue';
import BaselineStep from './Steps/BaselineStep.vue';
import PreferencesStep from './Steps/PreferencesStep.vue';
import ModulesStep from './Steps/ModulesStep.vue';
import SummaryStep from './Steps/SummaryStep.vue';

const props = defineProps({
    setup: { type: Object, required: true },
    // Ładowany leniwie (Inertia::optional) dopiero w kroku "Moduły" — zapytanie
    // do license servera nie może opóźniać wejścia w kreator.
    marketplace: { type: Object, default: null },
});

const page = usePage();

const STEP_COMPONENTS = {
    license: LicenseStep,
    company: CompanyStep,
    branding: BrandingStep,
    baseline: BaselineStep,
    preferences: PreferencesStep,
    modules: ModulesStep,
};

// Podsumowanie jest pseudo-krokiem — nie ma go w SetupService::STEPS,
// bo nie ma stanu do zapisania.
const steps = computed(() => [
    ...props.setup.steps,
    { key: 'summary', title: 'Gotowe', description: 'Podsumowanie konfiguracji', icon: 'check-circle', optional: false, status: null },
]);

/** Start na pierwszym nieukończonym kroku — powrót do kreatora nie zaczyna od zera. */
function firstUnfinishedIndex() {
    const index = props.setup.steps.findIndex((s) => !s.status);
    return index === -1 ? props.setup.steps.length : index;
}

const current = ref(firstUnfinishedIndex());
const stepRef = ref(null);

const currentStep = computed(() => steps.value[current.value]);
const isSummary = computed(() => currentStep.value.key === 'summary');
const stepComponent = computed(() => STEP_COMPONENTS[currentStep.value.key] || null);

// Licencja i dane startowe muszą być załatwione, zanim puścimy dalej.
const blocked = computed(() => {
    if (currentStep.value.key === 'license') return !props.setup.license.is_valid;
    if (currentStep.value.key === 'baseline') return props.setup.baseline.counts.statuses === 0;
    return false;
});

function goNext() {
    if (stepRef.value?.submit) {
        stepRef.value.submit();
        return;
    }
    advance();
}

function advance() {
    if (current.value < steps.value.length - 1) {
        current.value++;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function goBack() {
    if (current.value > 0) {
        current.value--;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function goTo(index) {
    current.value = index;
}

function statusIcon(step, index) {
    if (step.status === 'done') return { name: 'check', class: 'text-success' };
    if (step.status === 'skipped') return { name: 'arrow-right', class: 'text-foreground-subtle' };
    if (index === current.value) return { name: step.icon || 'settings', class: 'text-brand-primary' };
    return { name: step.icon || 'settings', class: 'text-foreground-subtle' };
}

// Po zapisie kroku backend odsyła świeże propsy — jeśli krok się „domknął",
// przesuwamy się dalej automatycznie.
watch(() => props.setup.steps.map((s) => s.status).join('|'), () => {
    const step = props.setup.steps[current.value];
    if (step && step.status && !blocked.value) advance();
});
</script>

<template>
    <Head title="Konfiguracja" />

    <div class="min-h-screen bg-background">
        <FlashMessages />

        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-12">
            <!-- Nagłówek -->
            <header class="text-center mb-10">
                <div class="flex justify-center mb-4">
                    <BrandLogo size="lg" />
                </div>
                <h1 class="text-3xl font-bold gradient-brand-text">Konfiguracja systemu</h1>
                <p class="text-sm text-foreground-muted mt-2">
                    Kilka kroków i CRM będzie dopasowany do Twojej firmy.
                    <span class="text-foreground-subtle">Instancja: <span class="font-mono">{{ setup.domain }}</span></span>
                </p>
            </header>

            <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6">
                <!-- Stepper -->
                <aside class="glass-card rounded-xl p-3 self-start lg:sticky lg:top-8">
                    <ol class="space-y-1">
                        <li v-for="(step, index) in steps" :key="step.key">
                            <button
                                type="button"
                                @click="goTo(index)"
                                :class="[
                                    'w-full flex items-start gap-3 px-3 py-3 rounded-lg text-left transition-colors relative',
                                    index === current
                                        ? 'gradient-subtle text-foreground'
                                        : 'text-foreground-muted hover:bg-surface-elevated',
                                ]"
                            >
                                <span v-if="index === current" class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-8 rounded-r gradient-brand" />

                                <span
                                    :class="[
                                        'shrink-0 mt-0.5 w-7 h-7 rounded-full flex items-center justify-center border text-xs font-semibold',
                                        step.status === 'done'
                                            ? 'border-success/40 bg-success/15'
                                            : index === current
                                                ? 'border-brand-primary/40 bg-brand-primary/10'
                                                : 'border-border bg-surface-elevated',
                                    ]"
                                >
                                    <Icons :name="statusIcon(step, index).name" :class="['w-3.5 h-3.5', statusIcon(step, index).class]" />
                                </span>

                                <span class="min-w-0">
                                    <span class="block text-sm font-medium truncate">{{ step.title }}</span>
                                    <span class="block text-xs text-foreground-subtle truncate">{{ step.description }}</span>
                                    <span v-if="step.status === 'skipped'" class="inline-block mt-1 text-[10px] uppercase tracking-wide text-foreground-subtle">
                                        pominięty
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ol>

                    <p class="px-3 py-3 text-xs text-foreground-subtle border-t border-border mt-2">
                        Wszystko poniżej zmienisz później w Ustawieniach — to tylko szybki start.
                    </p>
                </aside>

                <!-- Treść kroku -->
                <div class="space-y-4">
                    <div class="glass-card rounded-xl overflow-hidden">
                        <div class="px-6 py-4 border-b border-border flex items-center gap-3">
                            <Icons :name="currentStep.icon || 'settings'" class="w-5 h-5 text-brand-primary" />
                            <div>
                                <h2 class="text-lg font-semibold text-foreground">
                                    Krok {{ current + 1 }} z {{ steps.length }} — {{ currentStep.title }}
                                </h2>
                                <p class="text-xs text-foreground-muted">{{ currentStep.description }}</p>
                            </div>
                        </div>

                        <div class="p-6">
                            <SummaryStep v-if="isSummary" :setup="setup" @go-to-step="goTo" />
                            <component
                                v-else
                                :is="stepComponent"
                                ref="stepRef"
                                :setup="setup"
                                v-bind="currentStep.key === 'modules' ? { marketplace } : {}"
                                @next="advance"
                            />
                        </div>
                    </div>

                    <!-- Nawigacja -->
                    <div v-if="!isSummary" class="flex items-center justify-between gap-3 flex-wrap">
                        <Button variant="ghost" :disabled="current === 0" @click="goBack">
                            <Icons name="arrow-left" class="w-4 h-4" />
                            Wstecz
                        </Button>

                        <div class="flex items-center gap-2">
                            <Button
                                v-if="currentStep.optional"
                                variant="outline"
                                @click="stepRef?.skip ? stepRef.skip() : advance()"
                            >
                                Pomiń ten krok
                            </Button>
                            <Button :disabled="blocked" @click="goNext">
                                Dalej
                                <Icons name="arrow-right" class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>

                    <p v-if="blocked" class="text-xs text-warning text-right">
                        {{ currentStep.key === 'license'
                            ? 'Aby przejść dalej, aktywuj klucz licencyjny.'
                            : 'Aby przejść dalej, przygotuj dane startowe.' }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
