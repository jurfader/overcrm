<script setup>
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: 'button' },
    variant: {
        type: String,
        default: 'primary',
        // primary => brand gradient (default), danger => destructive (back-compat aliasy)
        validator: (v) => ['primary', 'default', 'destructive', 'danger', 'outline', 'secondary', 'ghost', 'link', 'success', 'warning'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'default', 'lg', 'icon'].includes(v),
    },
    disabled: Boolean,
    loading: Boolean,
});

const variantClass = computed(() => {
    const v = props.variant === 'primary' ? 'default' : props.variant === 'danger' ? 'destructive' : props.variant;
    return {
        default:     'gradient-brand text-white hover:opacity-90 shadow-sm hover:glow-pink',
        destructive: 'bg-destructive text-white hover:opacity-90',
        success:     'bg-success text-white hover:opacity-90',
        warning:     'bg-warning text-white hover:opacity-90',
        outline:     'border border-border-bright bg-transparent text-foreground hover:bg-surface-elevated',
        secondary:   'bg-surface-elevated text-foreground hover:bg-surface-hover border border-border',
        ghost:       'bg-transparent text-foreground hover:bg-surface-elevated',
        link:        'bg-transparent text-brand-primary hover:underline underline-offset-4 px-0',
    }[v];
});

const sizeClass = computed(() => {
    const s = props.size === 'md' ? 'default' : props.size;
    return {
        sm:      'h-8 px-3 text-xs',
        default: 'h-9 px-4 text-sm',
        lg:      'h-11 px-6 text-base',
        icon:    'h-9 w-9 p-0',
    }[s];
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 font-medium rounded-md',
            'transition-all duration-150 select-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            'disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none',
            variantClass,
            sizeClass,
        ]"
    >
        <svg v-if="loading" class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <slot />
    </button>
</template>
