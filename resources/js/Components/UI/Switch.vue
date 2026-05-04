<script setup>
const props = defineProps({
    modelValue: Boolean,
    disabled: Boolean,
    size: { type: String, default: 'md' }, // sm | md
});
const emit = defineEmits(['update:modelValue']);
function toggle() { if (!props.disabled) emit('update:modelValue', !props.modelValue); }
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        @click="toggle"
        :class="[
            'relative inline-flex shrink-0 items-center rounded-full transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
            modelValue ? 'gradient-brand' : 'bg-surface-elevated border border-border-bright',
            size === 'sm' ? 'h-4 w-7' : 'h-5 w-9',
        ]"
    >
        <span
            :class="[
                'inline-block bg-white rounded-full shadow transform transition-transform',
                size === 'sm' ? 'h-3 w-3' : 'h-4 w-4',
                modelValue
                    ? size === 'sm' ? 'translate-x-3.5' : 'translate-x-[18px]'
                    : 'translate-x-0.5',
            ]"
        />
    </button>
</template>
