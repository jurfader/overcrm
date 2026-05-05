<script setup>
import Modal from './Modal.vue';
import Button from './Button.vue';

defineProps({
    show: Boolean,
    title:       { type: String, default: 'Potwierdź akcję' },
    message:     { type: String, default: 'Czy na pewno chcesz wykonać tę akcję?' },
    confirmText: { type: String, default: 'Tak, wykonaj' },
    cancelText:  { type: String, default: 'Anuluj' },
    variant:     { type: String, default: 'danger' },
    processing:  Boolean,
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('cancel')">
        <div class="p-6">
            <div class="flex items-start">
                <div :class="['mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full',
                              variant === 'danger' ? 'bg-destructive/15' : 'bg-warning/15']">
                    <svg class="h-6 w-6"
                         :class="variant === 'danger' ? 'text-destructive' : 'text-warning'"
                         fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
            </div>
            <div class="mt-3 text-center sm:mt-5">
                <h3 class="text-lg font-semibold leading-6 text-foreground">{{ title }}</h3>
                <div class="mt-2">
                    <p class="text-sm text-foreground-muted">{{ message }}</p>
                </div>
            </div>
        </div>
        <div class="bg-surface-2 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2 border-t border-border">
            <Button :variant="variant === 'danger' ? 'destructive' : variant" :loading="processing" @click="emit('confirm')">
                {{ confirmText }}
            </Button>
            <Button variant="secondary" @click="emit('cancel')" :disabled="processing">
                {{ cancelText }}
            </Button>
        </div>
    </Modal>
</template>
