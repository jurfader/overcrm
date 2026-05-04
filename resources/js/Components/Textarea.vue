<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
    modelValue: String,
    rows: {
        type: Number,
        default: 3,
    },
    autofocus: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const textarea = ref(null);

onMounted(() => {
    if (props.autofocus) {
        textarea.value.focus();
    }
});

defineExpose({ focus: () => textarea.value.focus() });
</script>

<template>
    <textarea
        ref="textarea"
        :value="modelValue"
        :rows="rows"
        @input="emit('update:modelValue', $event.target.value)"
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full"
    />
</template>
