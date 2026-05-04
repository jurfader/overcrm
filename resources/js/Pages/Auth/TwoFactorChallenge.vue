<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const appSettings = computed(() => usePage().props.appSettings || {});
const appLogo = computed(() => appSettings.value.app_logo);
const companyName = computed(() => appSettings.value.company_name || 'Planner');

const form = useForm({
    code: '',
});

const useRecovery = ref(false);

function submit() {
    form.post(route('two-factor.verify'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Weryfikacja 2FA" />

    <div class="min-h-screen flex items-center justify-center bg-slate-100 dark:bg-slate-900 px-4">
        <div class="w-full max-w-sm">
            <div class="text-center mb-8">
                <img v-if="appLogo" :src="appLogo" :alt="companyName" class="h-12 mx-auto mb-2" />
                <h1 v-else class="text-2xl font-bold text-slate-900 dark:text-slate-100">
                    {{ companyName }}
                </h1>
                <p class="mt-2 text-slate-600 dark:text-slate-400">Weryfikacja dwuskładnikowa</p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-lg p-6 border border-slate-200 dark:border-slate-700">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 mx-auto bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        {{ useRecovery ? 'Wpisz jeden z kodów zapasowych' : 'Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <input
                            v-model="form.code"
                            :type="useRecovery ? 'text' : 'text'"
                            :placeholder="useRecovery ? 'Kod zapasowy' : '000000'"
                            :maxlength="useRecovery ? 10 : 6"
                            autofocus
                            autocomplete="one-time-code"
                            class="w-full text-center text-2xl tracking-[0.5em] font-mono px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-amber-500 focus:border-amber-500 placeholder:text-slate-300 dark:placeholder:text-slate-600 placeholder:tracking-normal placeholder:text-base"
                        />
                        <p v-if="form.errors.code" class="mt-2 text-sm text-red-500 text-center">{{ form.errors.code }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-3 px-4 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Weryfikacja...' : 'Zweryfikuj' }}
                    </button>
                </form>

                <div class="mt-4 text-center">
                    <button
                        @click="useRecovery = !useRecovery; form.code = ''"
                        class="text-sm text-amber-600 dark:text-amber-400 hover:underline"
                    >
                        {{ useRecovery ? 'Użyj kodu z aplikacji' : 'Użyj kodu zapasowego' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
