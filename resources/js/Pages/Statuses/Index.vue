<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    statuses: Array,
    types: Object,
    colors: Object,
});

const showDeleteModal = ref(false);
const statusToDelete = ref(null);
const deleting = ref(false);

function confirmDelete(status) {
    if (status.tasks_count > 0) {
        alert('Nie można usunąć statusu, który ma przypisane zadania.');
        return;
    }
    statusToDelete.value = status;
    showDeleteModal.value = true;
}

function deleteStatus() {
    deleting.value = true;
    router.delete(route('statuses.destroy', statusToDelete.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            statusToDelete.value = null;
        },
        onFinish: () => deleting.value = false,
    });
}
</script>

<template>
    <Head title="Statusy" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Statusy</h1>
                <p class="text-foreground-muted text-sm mt-1">Zarządzaj statusami zadań i wizyt</p>
            </div>
            <Link :href="route('statuses.create')">
                <Button>
                    <Icons name="plus" class="w-5 h-5 mr-2" />
                    Dodaj status
                </Button>
            </Link>
        </div>

        <Card no-padding>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-surface-2/50 border-b border-border">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Kolejność</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Nazwa</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Typ</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Kolor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Zadania</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Opcje</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-foreground-muted uppercase tracking-wider">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="status in statuses" :key="status.id" class="hover:bg-surface-elevated/50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground-muted">
                                {{ status.order }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 rounded-full mr-3" :style="{ backgroundColor: status.color }"></div>
                                    <span class="text-sm font-medium text-foreground">{{ status.name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-foreground">{{ types[status.type] }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium font-mono" :style="{ backgroundColor: status.color + '20', color: status.color }">
                                    {{ status.color }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                                {{ status.tasks_count }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <Badge v-if="status.is_default" color="green" size="sm">Domyślny</Badge>
                                    <Badge v-if="status.is_final" color="purple" size="sm">Końcowy</Badge>
                                    <Badge v-if="!status.is_visible" color="gray" size="sm">Ukryty</Badge>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('statuses.edit', status.id)" class="text-foreground-muted hover:text-brand-primary transition-colors">
                                        <Icons name="edit" class="w-5 h-5" />
                                    </Link>
                                    <button @click="confirmDelete(status)" class="text-foreground-muted hover:text-red-500 transition-colors" :disabled="status.tasks_count > 0" :class="{ 'opacity-50 cursor-not-allowed': status.tasks_count > 0 }">
                                        <Icons name="trash" class="w-5 h-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>

    <ConfirmModal
        :show="showDeleteModal"
        title="Usuń status"
        :message="`Czy na pewno chcesz usunąć status '${statusToDelete?.name}'?`"
        confirm-text="Tak, usuń"
        :processing="deleting"
        @confirm="deleteStatus"
        @cancel="showDeleteModal = false"
    />
</template>
