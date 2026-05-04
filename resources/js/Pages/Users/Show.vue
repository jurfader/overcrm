<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    user: Object,
});

const roleColors = {
    admin: 'purple',
    manager: 'blue',
    user: 'gray',
};

const roleLabels = {
    admin: 'Administrator',
    manager: 'Manager',
    user: 'Użytkownik',
};

const statusColors = {
    active: 'green',
    inactive: 'gray',
};

const priorityColors = {
    low: 'gray',
    medium: 'blue',
    high: 'yellow',
    urgent: 'red',
};
</script>

<template>
    <Head :title="user.name" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <img 
                    v-if="user.avatar_url" 
                    :src="user.avatar_url" 
                    :alt="user.name" 
                    class="w-16 h-16 rounded-full object-cover border-2 border-slate-200 dark:border-slate-600"
                />
                <div v-else class="w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center text-amber-600 dark:text-amber-400 text-2xl font-bold">
                    {{ user.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                </div>
                <div class="ml-4">
                    <h1 class="text-2xl font-bold text-gray-900">{{ user.name }}</h1>
                    <div class="flex items-center gap-2 mt-1">
                        <Badge :color="roleColors[user.role]">{{ roleLabels[user.role] }}</Badge>
                        <Badge :color="statusColors[user.status]">{{ user.status === 'active' ? 'Aktywny' : 'Nieaktywny' }}</Badge>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <Link :href="route('users.index')">
                    <Button variant="secondary">
                        <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                        Powrót
                    </Button>
                </Link>
                <Link :href="route('users.edit', user.id)">
                    <Button>
                        <Icons name="edit" class="w-5 h-5 mr-2" />
                        Edytuj
                    </Button>
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- Dane kontaktowe -->
                <Card title="Dane kontaktowe">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Email</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <a :href="'mailto:' + user.email" class="text-indigo-600 hover:text-indigo-800">{{ user.email }}</a>
                            </dd>
                        </div>
                        <div v-if="user.phone">
                            <dt class="text-sm font-medium text-gray-500">Telefon</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <a :href="'tel:' + user.phone" class="text-indigo-600 hover:text-indigo-800">{{ user.phone }}</a>
                            </dd>
                        </div>
                        <div v-if="user.position">
                            <dt class="text-sm font-medium text-gray-500">Stanowisko</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ user.position }}</dd>
                        </div>
                        <div v-if="user.last_login_at">
                            <dt class="text-sm font-medium text-gray-500">Ostatnie logowanie</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ new Date(user.last_login_at).toLocaleString('pl-PL') }}</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Uprawnienia -->
                <Card v-if="user.role !== 'admin' && user.permissions?.length > 0" title="Uprawnienia">
                    <div class="flex flex-wrap gap-2">
                        <Badge v-for="perm in user.permissions" :key="perm.id" color="indigo">
                            {{ perm.name }}
                        </Badge>
                    </div>
                </Card>

                <Card v-else-if="user.role === 'admin'" title="Uprawnienia">
                    <div class="flex items-center p-4 bg-purple-50 rounded-lg">
                        <Icons name="info" class="w-5 h-5 text-purple-600 mr-3" />
                        <p class="text-sm text-purple-700">Administrator ma pełne uprawnienia do wszystkich funkcji systemu.</p>
                    </div>
                </Card>

                <!-- Notatki -->
                <Card v-if="user.notes" title="Notatki">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ user.notes }}</p>
                </Card>
            </div>

            <div class="space-y-6">
                <!-- Aktywne zadania -->
                <Card title="Aktywne zadania">
                    <div v-if="!user.assigned_tasks || user.assigned_tasks.length === 0" class="text-center py-4 text-gray-500">
                        <p class="text-sm">Brak aktywnych zadań</p>
                    </div>
                    <ul v-else class="space-y-3">
                        <li v-for="task in user.assigned_tasks" :key="task.id">
                            <Link :href="route('tasks.show', task.id)" class="block p-3 rounded-lg border border-gray-200 hover:border-indigo-300 transition-colors">
                                <div class="text-sm font-medium text-gray-900">{{ task.title }}</div>
                                <div class="mt-1 flex items-center gap-2">
                                    <Badge v-if="task.status" :color="task.status.type === 'done' ? 'green' : task.status.type === 'in_progress' ? 'yellow' : 'blue'" size="sm">
                                        {{ task.status.name }}
                                    </Badge>
                                    <Badge :color="priorityColors[task.priority]" size="sm">
                                        {{ task.priority === 'low' ? 'Niski' : task.priority === 'medium' ? 'Średni' : task.priority === 'high' ? 'Wysoki' : 'Pilny' }}
                                    </Badge>
                                </div>
                                <div v-if="task.client" class="mt-1 text-xs text-gray-500">
                                    {{ task.client.short_name || task.client.name }}
                                </div>
                            </Link>
                        </li>
                    </ul>
                    <template #footer v-if="user.assigned_tasks && user.assigned_tasks.length > 0">
                        <Link :href="route('tasks.index', { assigned_to: user.id })" class="text-sm text-indigo-600 hover:text-indigo-800">
                            Zobacz wszystkie zadania →
                        </Link>
                    </template>
                </Card>

                <!-- Informacje -->
                <Card title="Informacje">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Utworzono</dt>
                            <dd class="text-gray-900">{{ new Date(user.created_at).toLocaleDateString('pl-PL') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Aktualizacja</dt>
                            <dd class="text-gray-900">{{ new Date(user.updated_at).toLocaleDateString('pl-PL') }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </div>
</template>
