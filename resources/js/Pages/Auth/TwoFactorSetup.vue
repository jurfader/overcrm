<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    qrCodeSvg: String,
    secret: String,
    enabled: Boolean,
});

const page = usePage();

const enableForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });

function enable() {
    enableForm.post(route('two-factor.enable'), {
        onFinish: () => enableForm.reset(),
    });
}

function disable() {
    disableForm.post(route('two-factor.disable'), {
        onFinish: () => disableForm.reset(),
    });
}

// Kody zapasowe z flash
const recoveryCodes = page.props.flash?.recovery_codes || null;
</script>

<template>
    <Head title="Uwierzytelnianie dwuskładnikowe" />

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Uwierzytelnianie dwuskładnikowe (2FA)</h1>
                <p class="text-foreground-muted text-sm mt-1">Dodatkowa warstwa bezpieczeństwa dla Twojego konta</p>
            </div>
            <a :href="route('dashboard')" class="inline-flex items-center text-sm text-foreground-muted hover:text-foreground">
                <Icons name="arrow-left" class="w-4 h-4 mr-1" />
                Powrót
            </a>
        </div>

        <!-- Status -->
        <div class="rounded-xl p-4 border" :class="enabled ? 'bg-green-50 dark:bg-green-900/20 border-green-200' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200'">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="enabled ? 'bg-green-100 dark:bg-green-900/40' : 'bg-yellow-100 dark:bg-yellow-900/40'">
                    <Icons :name="enabled ? 'check' : 'info'" class="w-5 h-5" :class="enabled ? 'text-green-600' : 'text-yellow-600'" />
                </div>
                <div>
                    <p class="font-medium" :class="enabled ? 'text-green-800' : 'text-yellow-800'">
                        {{ enabled ? '2FA jest włączone' : '2FA jest wyłączone' }}
                    </p>
                    <p class="text-sm" :class="enabled ? 'text-green-600' : 'text-yellow-600'">
                        {{ enabled ? 'Twoje konto jest chronione dwuskładnikowym uwierzytelnianiem.' : 'Włącz 2FA, aby zabezpieczyć swoje konto.' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Kody zapasowe (po włączeniu) -->
        <Card v-if="recoveryCodes" title="Kody zapasowe">
            <div class="space-y-3">
                <p class="text-sm text-red-500 font-medium">
                    Zapisz te kody w bezpiecznym miejscu. Każdy kod może być użyty tylko raz.
                </p>
                <div class="grid grid-cols-2 gap-2 p-4 bg-surface-2/50 rounded-lg font-mono text-sm">
                    <div v-for="code in recoveryCodes" :key="code" class="text-foreground">
                        {{ code }}
                    </div>
                </div>
                <p class="text-xs text-foreground-muted">
                    Jeśli stracisz dostęp do aplikacji uwierzytelniającej, użyj jednego z tych kodów aby się zalogować.
                </p>
            </div>
        </Card>

        <!-- Konfiguracja (gdy wyłączone) -->
        <Card v-if="!enabled" title="Konfiguracja">
            <div class="space-y-6">
                <div class="text-sm text-foreground-muted space-y-2">
                    <p><strong>1.</strong> Zainstaluj aplikację uwierzytelniającą (np. Google Authenticator, Authy).</p>
                    <p><strong>2.</strong> Zeskanuj poniższy kod QR lub wpisz klucz ręcznie.</p>
                    <p><strong>3.</strong> Wpisz wygenerowany 6-cyfrowy kod aby aktywować 2FA.</p>
                </div>

                <div class="flex flex-col items-center gap-4">
                    <!-- QR Code -->
                    <div class="p-4 bg-white rounded-xl border border-border" v-html="qrCodeSvg"></div>

                    <!-- Klucz ręczny -->
                    <div class="text-center">
                        <p class="text-xs text-foreground-muted mb-1">Lub wpisz klucz ręcznie:</p>
                        <code class="px-3 py-1.5 surface-elevated text-foreground rounded-lg text-sm font-mono tracking-wider select-all">
                            {{ secret }}
                        </code>
                    </div>
                </div>

                <!-- Formularz aktywacji -->
                <form @submit.prevent="enable" class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-foreground mb-1">Kod weryfikacyjny</label>
                        <input
                            v-model="enableForm.code"
                            type="text"
                            maxlength="6"
                            placeholder="000000"
                            autocomplete="one-time-code"
                            class="w-full text-center text-lg tracking-[0.3em] font-mono px-4 py-2 rounded-lg border border-border-bright bg-surface-elevated text-foreground focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                        />
                        <p v-if="enableForm.errors.code" class="mt-1 text-sm text-red-500">{{ enableForm.errors.code }}</p>
                    </div>
                    <Button :loading="enableForm.processing" type="submit">
                        Aktywuj 2FA
                    </Button>
                </form>
            </div>
        </Card>

        <!-- Wyłączanie (gdy włączone) -->
        <Card v-if="enabled" title="Wyłącz 2FA">
            <div class="space-y-4">
                <p class="text-sm text-foreground-muted">
                    Aby wyłączyć uwierzytelnianie dwuskładnikowe, potwierdź swoje hasło.
                </p>
                <form @submit.prevent="disable" class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-foreground mb-1">Hasło</label>
                        <input
                            v-model="disableForm.password"
                            type="password"
                            placeholder="Twoje hasło"
                            class="w-full px-4 py-2 rounded-lg border border-border-bright bg-surface-elevated text-foreground focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                        />
                        <p v-if="disableForm.errors.password" class="mt-1 text-sm text-red-500">{{ disableForm.errors.password }}</p>
                    </div>
                    <Button variant="danger" :loading="disableForm.processing" type="submit">
                        Wyłącz 2FA
                    </Button>
                </form>
            </div>
        </Card>
    </div>
</template>
