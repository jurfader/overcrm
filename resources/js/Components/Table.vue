<script setup>
defineProps({
    columns: { type: Array, required: true },
    data: { type: Array, required: true },
    loading: { type: Boolean, default: false },
});

defineEmits(['row-click']);
</script>

<template>
    <div class="overflow-hidden bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="[
                                'px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider',
                                column.class || ''
                            ]"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="loading">
                        <td :colspan="columns.length" class="px-6 py-12 text-center">
                            <div class="flex items-center justify-center gap-2 text-gray-500">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Ładowanie...</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-else-if="data.length === 0">
                        <td :colspan="columns.length" class="px-6 py-12 text-center text-gray-500">
                            Brak danych do wyświetlenia
                        </td>
                    </tr>
                    <tr
                        v-else
                        v-for="(row, index) in data"
                        :key="row.id || index"
                        class="hover:bg-gray-50 transition-colors cursor-pointer"
                        @click="$emit('row-click', row)"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                        >
                            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                                {{ row[column.key] }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
