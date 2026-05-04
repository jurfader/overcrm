<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    InformationCircleIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const page = usePage();
const messages = ref([]);
let messageId = 0;

const flash = computed(() => page.props.flash);

const icons = {
    success: CheckCircleIcon,
    error: XCircleIcon,
    warning: ExclamationTriangleIcon,
    info: InformationCircleIcon,
};

const colors = {
    success: 'bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 border-green-200 dark:border-green-800',
    error: 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 border-red-200 dark:border-red-800',
    warning: 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 border-yellow-200 dark:border-yellow-800',
    info: 'bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 border-blue-200 dark:border-blue-800',
};

const iconColors = {
    success: 'text-green-500',
    error: 'text-red-500',
    warning: 'text-yellow-500',
    info: 'text-blue-500',
};

const addMessage = (type, text) => {
    const id = ++messageId;
    messages.value.push({ id, type, text });
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        removeMessage(id);
    }, 5000);
};

const removeMessage = (id) => {
    messages.value = messages.value.filter(m => m.id !== id);
};

// Watch for flash messages
watch(flash, (newFlash) => {
    if (newFlash?.success) addMessage('success', newFlash.success);
    if (newFlash?.error) addMessage('error', newFlash.error);
    if (newFlash?.warning) addMessage('warning', newFlash.warning);
    if (newFlash?.info) addMessage('info', newFlash.info);
}, { immediate: true, deep: true });
</script>

<template>
    <div class="fixed top-20 right-4 z-50 space-y-3 max-w-md w-full pointer-events-none">
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform translate-x-full opacity-0"
            enter-to-class="transform translate-x-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-x-0 opacity-100"
            leave-to-class="transform translate-x-full opacity-0"
        >
            <div
                v-for="message in messages"
                :key="message.id"
                class="pointer-events-auto flex items-start gap-3 p-4 rounded-lg border shadow-lg"
                :class="colors[message.type]"
            >
                <component
                    :is="icons[message.type]"
                    class="w-5 h-5 flex-shrink-0"
                    :class="iconColors[message.type]"
                />
                <p class="flex-1 text-sm font-medium">{{ message.text }}</p>
                <button
                    @click="removeMessage(message.id)"
                    class="p-1 rounded hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                >
                    <XMarkIcon class="w-4 h-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
