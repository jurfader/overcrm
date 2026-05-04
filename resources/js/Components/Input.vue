<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    type: {
        type: String,
        default: 'text',
    },
    autofocus: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (props.autofocus) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        :type="type"
        :value="modelValue"
        @input="emit('update:modelValue', $event.target.value)"
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full"
    />
</template>
