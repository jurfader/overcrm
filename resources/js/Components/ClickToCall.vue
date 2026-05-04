<script setup>
import { ref } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    phone: { type: String, required: true },
    size: { type: String, default: 'sm' }, // sm, md
});

const showConfirm = ref(false);
const calling = ref(false);
const message = ref('');
const messageType = ref('');

function initiateCall() {
    calling.value = true;
    message.value = '';

    fetch(route('ringostat.callback'), {
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
        message.value = data.message;
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
    <div class="relative inline-flex items-center">
        <!-- Przycisk telefonu -->
        <button
            @click="showConfirm = !showConfirm"
            :class="[
                'inline-flex items-center justify-center rounded-lg transition',
                size === 'sm' ? 'p-1 hover:bg-green-100 dark:hover:bg-green-900/30' : 'p-1.5 hover:bg-green-100 dark:hover:bg-green-900/30',
            ]"
            :title="'Zadzwoń na ' + phone"
        >
            <Icons name="phone" :class="[size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4', 'text-green-600 dark:text-green-400']" />
        </button>

        <!-- Toast -->
        <div
            v-if="message"
            :class="[
                'absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap z-50 shadow-lg',
                messageType === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
            ]"
        >
            {{ message }}
        </div>

        <!-- Modal potwierdzenia -->
        <div v-if="showConfirm" class="absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-2">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 p-4 min-w-[220px]">
                <p class="text-sm text-slate-700 dark:text-slate-300 mb-3">
                    Zadzwonić na<br/>
                    <span class="font-mono font-bold">{{ phone }}</span>?
                </p>
                <div class="flex gap-2">
                    <button
                        @click="showConfirm = false"
                        class="flex-1 px-3 py-1.5 text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition"
                    >
                        Anuluj
                    </button>
                    <button
                        @click="initiateCall"
                        :disabled="calling"
                        class="flex-1 px-3 py-1.5 text-sm bg-green-500 hover:bg-green-600 text-white rounded-lg transition font-medium disabled:opacity-50"
                    >
                        {{ calling ? 'Łączenie...' : 'Zadzwoń' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Click outside -->
        <div v-if="showConfirm" class="fixed inset-0 z-40" @click="showConfirm = false"></div>
    </div>
</template>
