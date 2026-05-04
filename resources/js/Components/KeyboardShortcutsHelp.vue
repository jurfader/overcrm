<script setup>
defineProps({
    show: Boolean,
    shortcuts: Array,
});

defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center" @click.self="$emit('close')">
            <div class="fixed inset-0 bg-black/50 dark:bg-black/70" @click="$emit('close')"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Skróty klawiszowe</h3>
                    <button
                        @click="$emit('close')"
                        class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-400 dark:text-slate-500 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-4 space-y-1 max-h-[60vh] overflow-y-auto">
                    <div class="text-xs uppercase font-semibold text-gray-400 dark:text-slate-500 mb-3 tracking-wider">Nawigacja</div>
                    <div
                        v-for="shortcut in shortcuts.filter(s => s.keys.startsWith('g'))"
                        :key="shortcut.keys"
                        class="flex items-center justify-between py-2"
                    >
                        <span class="text-sm text-gray-700 dark:text-slate-300">{{ shortcut.description }}</span>
                        <span class="flex items-center gap-1">
                            <template v-for="(part, i) in shortcut.keys.split(' → ')" :key="i">
                                <kbd class="px-2 py-1 text-xs font-mono font-semibold bg-gray-100 dark:bg-slate-700 text-gray-800 dark:text-slate-200 rounded border border-gray-300 dark:border-slate-600 shadow-sm">
                                    {{ part }}
                                </kbd>
                                <span v-if="i < shortcut.keys.split(' → ').length - 1" class="text-gray-400 dark:text-slate-500 text-xs">+</span>
                            </template>
                        </span>
                    </div>

                    <div class="text-xs uppercase font-semibold text-gray-400 dark:text-slate-500 mb-3 mt-5 tracking-wider">Akcje</div>
                    <div
                        v-for="shortcut in shortcuts.filter(s => !s.keys.startsWith('g'))"
                        :key="shortcut.keys"
                        class="flex items-center justify-between py-2"
                    >
                        <span class="text-sm text-gray-700 dark:text-slate-300">{{ shortcut.description }}</span>
                        <kbd class="px-2 py-1 text-xs font-mono font-semibold bg-gray-100 dark:bg-slate-700 text-gray-800 dark:text-slate-200 rounded border border-gray-300 dark:border-slate-600 shadow-sm">
                            {{ shortcut.keys }}
                        </kbd>
                    </div>
                </div>

                <div class="px-6 py-3 bg-gray-50 dark:bg-slate-900/50 border-t border-gray-200 dark:border-slate-700">
                    <p class="text-xs text-gray-500 dark:text-slate-400 text-center">
                        Naciśnij <kbd class="px-1.5 py-0.5 text-[10px] font-mono bg-gray-200 dark:bg-slate-700 rounded">?</kbd> aby przełączyć tę pomoc
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
