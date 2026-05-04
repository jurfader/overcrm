<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    type: { type: String, default: 'text' },
    placeholder: String,
    autofocus: Boolean,
    disabled: Boolean,
    invalid: Boolean,
});

const emit = defineEmits(['update:modelValue']);
const input = ref(null);

onMounted(() => { if (props.autofocus) input.value?.focus(); });
defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        ref="input"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        @input="emit('update:modelValue', $event.target.value)"
        :class="[
            'h-9 w-full rounded-md border px-3 py-2 text-sm transition-colors',
            'bg-surface text-foreground placeholder:text-foreground-subtle',
            'focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            invalid ? 'border-destructive' : 'border-border-bright',
        ]"
    />
</template>
