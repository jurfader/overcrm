<script setup>
defineProps({
    modelValue: [String, Number],
    options: {
        type: [Array, Object],
        required: true,
    },
    placeholder: String,
    disabled: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const isArray = (options) => Array.isArray(options);
</script>

<template>
    <select
        :value="modelValue"
        :disabled="disabled"
        @change="emit('update:modelValue', $event.target.value)"
        class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full"
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <template v-if="isArray(options)">
            <option v-for="option in options" :key="option.id || option.value" :value="option.id || option.value">
                {{ option.name || option.label }}
            </option>
        </template>
        <template v-else>
            <option v-for="(label, value) in options" :key="value" :value="value">
                {{ label }}
            </option>
        </template>
    </select>
</template>
