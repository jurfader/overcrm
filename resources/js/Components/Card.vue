<script setup>
defineProps({
    title: String,
    subtitle: String,
    noPadding: Boolean,
    variant: { type: String, default: 'glass' }, // 'glass' | 'solid'
});
</script>

<template>
    <div :class="[
        'overflow-hidden rounded-lg',
        variant === 'glass' ? 'glass-card' : 'surface',
    ]">
        <div v-if="title || $slots.header" class="px-6 py-4 border-b border-border">
            <slot name="header">
                <h3 class="text-base font-semibold text-foreground">{{ title }}</h3>
                <p v-if="subtitle" class="mt-1 text-sm text-foreground-muted">{{ subtitle }}</p>
            </slot>
        </div>
        <div :class="noPadding ? '' : 'p-6'">
            <slot />
        </div>
        <div v-if="$slots.footer" class="px-6 py-4 border-t border-border bg-surface-2">
            <slot name="footer" />
        </div>
    </div>
</template>
