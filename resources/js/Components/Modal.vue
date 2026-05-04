<script setup>
import { watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    show: Boolean,
    maxWidth: {
        type: String,
        default: 'md',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

const maxWidthClass = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
};

watch(
    () => props.show,
    (value) => {
        if (value) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    },
    { immediate: true }
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

function handleEscape(e) {
    if (e.key === 'Escape' && props.show) {
        close();
    }
}

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => {
    window.removeEventListener('keydown', handleEscape);
    // Gdy rodzic odmontowuje modal (np. zamknięcie ClientModal po usunięciu wizyty),
    // watcher z show=false może nie zdążyć — body zostaje z overflow:hidden.
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-200">
            <div v-show="show" class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50" scroll-region>
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 transform transition-all" @click="close">
                        <div class="absolute inset-0 bg-gray-500 opacity-75" />
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        :class="['mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full sm:mx-auto', maxWidthClass[maxWidth]]"
                    >
                        <slot v-if="show" />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
