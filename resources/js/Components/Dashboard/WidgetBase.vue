<script setup>
import Icons from '@/Components/Icons.vue';

defineProps({
    title: String,
    icon: { type: String, default: 'puzzle' },
    editMode: Boolean,
    width: { type: Number, default: 4 },
});

defineEmits(['remove', 'resize']);

const widthOptions = [
    { value: 3, label: '1/4' },
    { value: 4, label: '1/3' },
    { value: 6, label: '1/2' },
    { value: 8, label: '2/3' },
    { value: 12, label: 'Pełna szerokość' },
];
</script>

<template>
    <div :class="[
        'glass-card rounded-lg overflow-hidden flex flex-col',
        editMode && 'ring-2 ring-brand-primary/40 ring-offset-2 ring-offset-background',
    ]">
        <header class="px-4 py-3 border-b border-border flex items-center gap-3">
            <Icons :name="icon" class="w-4 h-4 text-brand-primary shrink-0" />
            <h3 class="text-sm font-semibold text-foreground flex-1 min-w-0 truncate">{{ title }}</h3>

            <template v-if="editMode">
                <select
                    :value="width"
                    @change="$emit('resize', Number($event.target.value))"
                    class="h-7 text-xs rounded border border-border-bright bg-surface-elevated text-foreground px-2"
                    title="Szerokość widgetu"
                >
                    <option v-for="opt in widthOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <button
                    type="button"
                    @click="$emit('remove')"
                    class="p-1 rounded text-foreground-muted hover:text-destructive hover:bg-destructive/10 transition-colors"
                    title="Ukryj widget"
                >
                    <Icons name="close" class="w-4 h-4" />
                </button>
                <span class="widget-drag-handle cursor-grab active:cursor-grabbing text-foreground-muted hover:text-foreground select-none px-1 font-mono leading-none" title="Przeciągnij aby zmienić pozycję">⋮⋮</span>
            </template>
        </header>

        <div class="flex-1 min-h-0">
            <slot />
        </div>
    </div>
</template>
