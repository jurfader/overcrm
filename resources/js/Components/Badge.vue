<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'success', 'warning', 'destructive', 'info', 'outline', 'secondary'].includes(v),
    },
    color: {
        type: String,
        default: null,
        // Backward-compat — stare wartości mapowane na variant
        validator: (v) => v === null || ['gray', 'red', 'yellow', 'green', 'blue', 'indigo', 'purple', 'pink'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

const colorToVariant = {
    gray: 'secondary', red: 'destructive', yellow: 'warning',
    green: 'success', blue: 'info', indigo: 'default',
    purple: 'default', pink: 'default',
};

const variantClass = (props) => {
    const v = props.color ? colorToVariant[props.color] : props.variant;
    return {
        default:     'gradient-subtle text-brand-primary border border-brand-primary/30',
        success:     'bg-success/15 text-success border border-success/30',
        warning:     'bg-warning/15 text-warning border border-warning/30',
        destructive: 'bg-destructive/15 text-destructive border border-destructive/30',
        info:        'bg-info/15 text-info border border-info/30',
        outline:     'bg-transparent text-foreground border border-border-bright',
        secondary:   'bg-surface-elevated text-foreground border border-border',
    }[v];
};

const sizeClass = {
    sm: 'px-2 py-0.5 text-[10px]',
    md: 'px-2.5 py-0.5 text-xs',
    lg: 'px-3 py-1 text-sm',
};
</script>

<template>
    <span :class="['inline-flex items-center gap-1 font-medium rounded-full', variantClass($props), sizeClass[size]]">
        <slot />
    </span>
</template>
