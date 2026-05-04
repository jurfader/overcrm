<script setup>
import { ref } from 'vue';

defineProps({
    content: { type: String, required: true },
    placement: { type: String, default: 'right' }, // top | right | bottom | left
});

const open = ref(false);
</script>

<template>
    <span class="relative inline-flex" @mouseenter="open = true" @mouseleave="open = false" @focusin="open = true" @focusout="open = false">
        <slot />
        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <span
                v-if="open && content"
                :class="[
                    'glass absolute z-50 px-2.5 py-1.5 rounded-md text-xs whitespace-nowrap text-foreground shadow pointer-events-none',
                    placement === 'right'  && 'left-full top-1/2 -translate-y-1/2 ml-2',
                    placement === 'left'   && 'right-full top-1/2 -translate-y-1/2 mr-2',
                    placement === 'top'    && 'left-1/2 bottom-full -translate-x-1/2 mb-2',
                    placement === 'bottom' && 'left-1/2 top-full -translate-x-1/2 mt-2',
                ]"
            >
                {{ content }}
            </span>
        </Transition>
    </span>
</template>
