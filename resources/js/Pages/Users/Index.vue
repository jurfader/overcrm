<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    users: Object,
    filters: Object,
    roles: Object,
    statuses: Object,
});

const search = ref(props.filters.search);
const roleFilter = ref(props.filters.role);
const statusFilter = ref(props.filters.status);

const showDeleteModal = ref(false);
const userToDelete = ref(null);
const deleting = ref(false);

let searchTimeout;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

watch([roleFilter, statusFilter], () => {
    applyFilters();
});

function applyFilters() {
    router.get(route('users.index'), {
        search: search.value || undefined,
        role: roleFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function confirmDelete(user) {
    userToDelete.value = user;
    showDeleteModal.value = true;
}

function deleteUser() {
    deleting.value = true;
    router.delete(route('users.destroy', userToDelete.value.id), {
        onSuccess: () => {
            showDeleteModal.value = false;
            userToDelete.value = null;
        },
        onFinish: () => deleting.value = false,
    });
}

const roleColors = {
    admin: 'purple',
    manager: 'blue',
    user: 'gray',
};

const statusColors = {
    active: 'green',
    inactive: 'gray',
};
</script>

<template>
    <Head title="Użytkownicy" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Użytkownicy</h1>
                <p class="text-gray-600">Zarządzaj kontami użytkowników</p>
            </div>
            <Link :href="route('users.create')">
                <Button>
                    <Icons name="plus" class="w-5 h-5 mr-2" />
                    Dodaj użytkownika
                </Button>
            </Link>
        </div>

        <Card :padding="false">
            <div class="p-4 border-b border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2">
                        <Input v-model="search" placeholder="Szukaj po nazwie, email..." />
                    </div>
                    <Select v-model="roleFilter" :options="roles" placeholder="Wszystkie role" />
                    <Select v-model="statusFilter" :options="statuses" placeholder="Wszystkie statusy" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Użytkownik</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rola</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stanowisko</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zadania</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Akcje</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <img 
                                        v-if="user.avatar_url" 
                                        :src="user.avatar_url" 
                                        :alt="user.name" 
                                        class="w-10 h-10 rounded-full object-cover border border-slate-200 dark:border-slate-600"
                                    />
                                    <div v-else class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center text-brand-primary font-medium">
                                        {{ user.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                                    </div>
                                    <div class="ml-4">
                                        <Link :href="route('users.show', user.id)" class="text-sm font-medium text-gray-900 hover:text-brand-primary">
                                            {{ user.name }}
                                        </Link>
                                        <div class="text-sm text-gray-500">{{ user.email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Badge :color="roleColors[user.role]">
                                    {{ roles[user.role] }}
                                </Badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ user.position || '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="font-medium">{{ user.active_tasks_count }}</span>
                                <span class="text-gray-500"> / {{ user.assigned_tasks_count }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <Badge :color="statusColors[user.status]">
                                    {{ statuses[user.status] }}
                                </Badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-2">
                                    <Link :href="route('users.show', user.id)" class="text-foreground-muted hover:text-foreground">
                                        <Icons name="eye" class="w-5 h-5" />
                                    </Link>
                                    <Link :href="route('users.edit', user.id)" class="text-gray-400 hover:text-brand-primary">
                                        <Icons name="edit" class="w-5 h-5" />
                                    </Link>
                                    <button 
                                        @click="confirmDelete(user)" 
                                        class="text-gray-400 hover:text-red-600"
                                        :disabled="user.id === $page.props.auth.user.id"
                                        :class="{ 'opacity-50 cursor-not-allowed': user.id === $page.props.auth.user.id }"
                                    >
                                        <Icons name="trash" class="w-5 h-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="users.links" />
        </Card>
    </div>

    <ConfirmModal
        :show="showDeleteModal"
        title="Usuń użytkownika"
        :message="`Czy na pewno chcesz usunąć użytkownika '${userToDelete?.name}'?`"
        confirm-text="Tak, usuń"
        :processing="deleting"
        @confirm="deleteUser"
        @cancel="showDeleteModal = false"
    />
</template>
