<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    task: Object,
    statuses: Array,
    clients: Array,
    users: Array,
    priorities: Object,
    preselectedClientId: [String, Number],
});

const isEditing = !!props.task;

const defaultStatus = props.statuses.find(s => s.is_default)?.id || props.statuses[0]?.id;

const form = useForm({
    title: props.task?.title || '',
    description: props.task?.description || '',
    status_id: props.task?.status_id || defaultStatus,
    client_id: props.task?.client_id || props.preselectedClientId || '',
    assigned_to: props.task?.assigned_to || '',
    submit_date: props.task?.submit_date || new Date().toISOString().split('T')[0],
    due_date: props.task?.due_date || '',
    priority: props.task?.priority || 'medium',
    estimated_hours: props.task?.estimated_hours || '',
    notes: props.task?.notes || '',
});

function submit() {
    if (isEditing) {
        form.put(route('tasks.update', props.task.id));
    } else {
        form.post(route('tasks.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edytuj zadanie' : 'Nowe zadanie'" />

    <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edytuj zadanie' : 'Nowe zadanie' }}</h1>
                <p class="text-gray-600">{{ isEditing ? 'Zaktualizuj szczegóły zadania' : 'Dodaj nowe zadanie do systemu' }}</p>
            </div>
            <Link :href="route('tasks.index')">
                <Button variant="secondary">
                    <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                    Powrót
                </Button>
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <Card title="Szczegóły zadania">
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tytuł *</label>
                        <Input v-model="form.title" placeholder="Np. Spotkanie z klientem" autofocus />
                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Opis</label>
                        <Textarea v-model="form.description" :rows="4" placeholder="Szczegółowy opis zadania..." />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                            <Select v-model="form.status_id" :options="statuses" />
                            <p v-if="form.errors.status_id" class="mt-1 text-sm text-red-600">{{ form.errors.status_id }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priorytet *</label>
                            <Select v-model="form.priority" :options="priorities" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Klient</label>
                            <Select v-model="form.client_id" :options="clients.map(c => ({ id: c.id, name: c.short_name || c.name }))" placeholder="Wybierz klienta" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Przypisany do</label>
                            <Select v-model="form.assigned_to" :options="users" placeholder="Wybierz osobę" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Data zgłoszenia</label>
                            <Input v-model="form.submit_date" type="date" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Termin realizacji</label>
                            <Input v-model="form.due_date" type="date" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Szacowany czas (godziny)</label>
                            <Input v-model="form.estimated_hours" type="number" min="0" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card title="Notatki wewnętrzne">
                <Textarea v-model="form.notes" :rows="3" placeholder="Notatki widoczne tylko dla pracowników..." />
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('tasks.index')">
                    <Button variant="secondary" type="button">Anuluj</Button>
                </Link>
                <Button :loading="form.processing">
                    {{ isEditing ? 'Zapisz zmiany' : 'Utwórz zadanie' }}
                </Button>
            </div>
        </form>
    </div>
</template>
