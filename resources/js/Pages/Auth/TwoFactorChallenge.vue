<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Button from '@/Components/Button.vue';
import BrandLogo from '@/Components/UI/BrandLogo.vue';
import ThemeToggle from '@/Components/UI/ThemeToggle.vue';

defineOptions({ layout: null });

const brand = computed(() => usePage().props.brand || {});

const form = useForm({ code: '' });
const useRecovery = ref(false);

function submit() {
    form.post(route('two-factor.verify'), { onFinish: () => form.reset() });
}
</script>

<template>
    <Head :title="`Weryfikacja 2FA — ${brand.name}`" />

    <div class="min-h-screen flex items-center justify-center px-4 relative">
        <div class="absolute top-4 right-4 z-10">
            <ThemeToggle />
        </div>

        <div class="w-full max-w-sm glass-card rounded-2xl p-8 animate-fade-in">
            <div class="flex flex-col items-center mb-6">
                <BrandLogo size="lg" :show-name="false" class="mb-4" />
                <h1 class="text-xl font-bold gradient-brand-text">{{ brand.name }}</h1>
                <p class="text-sm text-foreground-muted mt-1">Weryfikacja dwuskładnikowa</p>
            </div>

            <div class="text-center mb-6">
                <div class="w-14 h-14 mx-auto rounded-full gradient-subtle border border-brand-primary/30 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 text-brand-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <p class="text-sm text-foreground-muted">
                    {{ useRecovery ? 'Wpisz jeden z kodów zapasowych' : 'Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej' }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <input
                        v-model="form.code"
                        type="text"
                        :placeholder="useRecovery ? 'Kod zapasowy' : '000000'"
                        :maxlength="useRecovery ? 10 : 6"
                        autofocus
                        autocomplete="one-time-code"
                        class="w-full text-center text-2xl tracking-[0.5em] font-mono px-4 py-3 rounded-md border border-border-bright bg-surface text-foreground focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary placeholder:text-foreground-subtle placeholder:tracking-normal placeholder:text-base"
                    />
                    <p v-if="form.errors.code" class="mt-2 text-sm text-destructive text-center">{{ form.errors.code }}</p>
                </div>

                <Button type="submit" size="lg" :loading="form.processing" class="w-full">
                    {{ form.processing ? 'Weryfikacja...' : 'Zweryfikuj' }}
                </Button>
            </form>

            <div class="mt-4 text-center">
                <button
                    @click="useRecovery = !useRecovery; form.code = ''"
                    class="text-sm text-brand-primary hover:underline"
                >
                    {{ useRecovery ? 'Użyj kodu z aplikacji' : 'Użyj kodu zapasowego' }}
                </button>
            </div>
        </div>
    </div>
</template>
