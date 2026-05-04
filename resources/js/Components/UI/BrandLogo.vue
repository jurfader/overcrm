<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    size: { type: String, default: 'md' }, // sm | md | lg
    showName: { type: Boolean, default: true },
});

const brand = computed(() => usePage().props.brand || {});
const sizeClass = computed(() => ({
    sm: { box: 'w-7 h-7 text-xs', text: 'text-sm' },
    md: { box: 'w-9 h-9 text-base', text: 'text-base' },
    lg: { box: 'w-12 h-12 text-lg', text: 'text-xl' },
}[props.size]));

const initial = computed(() => (brand.value.short_name || brand.value.name || '?').slice(0, 1).toUpperCase());
</script>

<template>
    <div class="inline-flex items-center gap-2.5">
        <span :class="['shrink-0 rounded-lg gradient-brand glow-pink flex items-center justify-center text-white font-bold', sizeClass.box]">
            <img v-if="brand.logo_url" :src="brand.logo_url" :alt="brand.name" class="w-full h-full object-contain p-1" />
            <span v-else>{{ initial }}</span>
        </span>
        <span v-if="showName" :class="['gradient-brand-text font-bold tracking-tight', sizeClass.text]">
            {{ brand.short_name || brand.name }}
        </span>
    </div>
</template>
