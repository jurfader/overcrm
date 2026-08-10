<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    phone: { type: String, required: true },
    size: { type: String, default: 'sm' }, // sm, md
});

const page = usePage();

/**
 * Komponent pyta o ZDOLNOŚĆ, nie o nazwę modułu.
 *
 * Wcześniej była tu lista `if`-ów po nazwach central (ringostat, playcentrala),
 * więc każda nowa centrala wymagała zmiany w tym pliku. Teraz wystarczy, że
 * moduł zarejestruje się jako dostawca telefonii — endpoint jest wspólny
 * i mieszka w rdzeniu, a on sam deleguje do aktywnego dostawcy.
 *
 * Brak skonfigurowanej centrali = komponent w ogóle się nie renderuje.
 */
const provider = computed(() => {
    const capabilities = page.props.capabilities || [];

    return capabilities.includes('telephony')
        ? { endpoint: route('telephony.call') }
        : null;
});

const showConfirm = ref(false);
const calling = ref(false);
const message = ref('');
const messageType = ref('');

function initiateCall() {
    if (!provider.value) return;
    calling.value = true;
    message.value = '';

    fetch(provider.value.endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ destination: props.phone }),
    })
    .then(r => r.json())
    .then(data => {
        message.value = data.message || (data.success ? 'Połączenie zainicjowane' : 'Nie udało się zadzwonić');
        messageType.value = data.success ? 'success' : 'error';
        if (data.success) {
            showConfirm.value = false;
        }
    })
    .catch(err => {
        message.value = 'Błąd: ' + err.message;
        messageType.value = 'error';
    })
    .finally(() => {
        calling.value = false;
        setTimeout(() => message.value = '', 5000);
    });
}
</script>

<template>
    <div v-if="provider" class="relative inline-flex items-center">
        <button
            @click="showConfirm = !showConfirm"
            :class="[
                'inline-flex items-center justify-center rounded-lg transition',
                size === 'sm' ? 'p-1 hover:bg-success/15' : 'p-1.5 hover:bg-success/15',
            ]"
            :title="`Zadzwoń na ${phone}`"
        >
            <Icons name="phone" :class="[size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4', 'text-success']" />
        </button>

        <div
            v-if="message"
            :class="[
                'absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap z-50 shadow-lg',
                messageType === 'success' ? 'bg-success text-white' : 'bg-destructive text-white'
            ]"
        >
            {{ message }}
        </div>

        <div v-if="showConfirm" class="absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-2">
            <div class="surface-2 rounded-xl shadow-xl border border-border p-4 min-w-[240px]">
                <p class="text-sm text-foreground mb-3">
                    Zadzwonić na<br/>
                    <span class="font-mono font-bold">{{ phone }}</span>
                </p>
                <p class="text-xs text-foreground-muted mb-3">
                    Centrala oddzwoni najpierw na Twój numer wewnętrzny.
                </p>
                <div class="flex gap-2">
                    <button
                        @click="showConfirm = false"
                        class="flex-1 px-3 py-1.5 text-sm text-foreground-muted hover:bg-surface-3 rounded-lg transition"
                    >
                        Anuluj
                    </button>
                    <button
                        @click="initiateCall"
                        :disabled="calling"
                        class="flex-1 px-3 py-1.5 text-sm bg-success hover:bg-success/90 text-white rounded-lg transition font-medium disabled:opacity-50"
                    >
                        {{ calling ? 'Łączenie...' : 'Zadzwoń' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showConfirm" class="fixed inset-0 z-40" @click="showConfirm = false"></div>
    </div>
</template>
