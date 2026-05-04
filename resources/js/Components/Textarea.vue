<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
    modelValue: String,
    rows: { type: Number, default: 3 },
    placeholder: String,
    autofocus: Boolean,
    disabled: Boolean,
    invalid: Boolean,
});

const emit = defineEmits(['update:modelValue']);
const textarea = ref(null);

onMounted(() => { if (props.autofocus) textarea.value?.focus(); });
defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <textarea
        ref="textarea"
        :value="modelValue"
        :rows="rows"
        :placeholder="placeholder"
        :disabled="disabled"
        @input="emit('update:modelValue', $event.target.value)"
        :class="[
            'min-h-[80px] w-full rounded-md border px-3 py-2 text-sm transition-colors resize-y',
            'bg-surface text-foreground placeholder:text-foreground-subtle',
            'focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            invalid ? 'border-destructive' : 'border-border-bright',
        ]"
    />
</template>
