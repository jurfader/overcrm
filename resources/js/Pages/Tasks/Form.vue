<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    collaboratorIds: { type: Array, default: () => [] },
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
    collaborators: props.collaboratorIds || [],
    due_time: props.task?.due_time ? String(props.task.due_time).slice(0, 5) : '',
    recurrence_type: props.task?.recurrence_type || '',
    recurrence_interval: props.task?.recurrence_interval || 1,
    recurrence_weekdays: props.task?.recurrence_weekdays || [],
    recurrence_until: props.task?.recurrence_until || '',
    reminder_offset_minutes: props.task?.reminder_offset_minutes ?? '',
});

const TYPY_CYKLU = [
    { id: '', name: 'Bez powtarzania' },
    { id: 'daily', name: 'Codziennie' },
    { id: 'weekly', name: 'Co tydzień' },
    { id: 'monthly', name: 'Co miesiąc' },
    { id: 'yearly', name: 'Co rok' },
];

const DNI_TYGODNIA = [
    { id: 1, skrot: 'Pn' }, { id: 2, skrot: 'Wt' }, { id: 3, skrot: 'Śr' },
    { id: 4, skrot: 'Cz' }, { id: 5, skrot: 'Pt' }, { id: 6, skrot: 'So' }, { id: 7, skrot: 'Nd' },
];

// Przypomnienie ma sens tylko wtedy, gdy zadanie ma termin — inaczej nie ma
// od czego odliczać.
const OPCJE_PRZYPOMNIENIA = [
    { id: '', name: 'Bez przypomnienia' },
    { id: 15, name: '15 minut przed' },
    { id: 60, name: 'godzinę przed' },
    { id: 180, name: '3 godziny przed' },
    { id: 1440, name: 'dzień przed' },
    { id: 2880, name: '2 dni przed' },
    { id: 10080, name: 'tydzień przed' },
];

function przelaczDzien(dzien) {
    const i = form.recurrence_weekdays.indexOf(dzien);
    if (i >= 0) form.recurrence_weekdays.splice(i, 1);
    else form.recurrence_weekdays.push(dzien);
}

/** Autor i wykonawca mają dostęp z definicji — nie ma sensu ich tu dublować. */
const wspolpracownicyDoWyboru = computed(() =>
    props.users.filter(u => u.id !== form.assigned_to && u.id !== props.task?.created_by)
);

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
                <h1 class="text-2xl font-bold gradient-brand-text">{{ isEditing ? 'Edytuj zadanie' : 'Nowe zadanie' }}</h1>
                <p class="text-foreground-muted text-sm mt-1">{{ isEditing ? 'Zaktualizuj szczegóły zadania' : 'Dodaj nowe zadanie do systemu' }}</p>
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
                        <label class="block text-sm font-medium text-foreground mb-1">Tytuł *</label>
                        <Input v-model="form.title" placeholder="Np. Spotkanie z klientem" autofocus />
                        <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Opis</label>
                        <Textarea v-model="form.description" :rows="4" placeholder="Szczegółowy opis zadania..." />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Status *</label>
                            <Select v-model="form.status_id" :options="statuses" />
                            <p v-if="form.errors.status_id" class="mt-1 text-sm text-red-600">{{ form.errors.status_id }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Priorytet *</label>
                            <Select v-model="form.priority" :options="priorities" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Klient</label>
                            <Select v-model="form.client_id" :options="clients.map(c => ({ id: c.id, name: c.short_name || c.name }))" placeholder="Wybierz klienta" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Przypisany do</label>
                            <Select v-model="form.assigned_to" :options="users" placeholder="Wybierz osobę" />
                        </div>

                        <!-- Współpracownicy decydują o widoczności zadania: tylko autor,
                             wykonawca i te osoby je zobaczą. -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-foreground mb-1">Współpracownicy</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="u in wspolpracownicyDoWyboru" :key="u.id"
                                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border-bright text-sm cursor-pointer hover:bg-surface-elevated transition-colors">
                                    <input type="checkbox" :value="u.id" v-model="form.collaborators" class="rounded" />
                                    {{ u.name }}
                                </label>
                            </div>
                            <p class="text-xs text-foreground-muted mt-1">
                                Osoby, które mają widzieć to zadanie oprócz Ciebie i wykonawcy.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Data zgłoszenia</label>
                            <Input v-model="form.submit_date" type="date" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Termin realizacji</label>
                            <div class="grid grid-cols-2 gap-2">
                                <Input v-model="form.due_date" type="date" />
                                <Input v-model="form.due_time" type="time" title="Godzina (opcjonalnie)" />
                            </div>
                            <p class="text-xs text-foreground-muted mt-1">
                                Bez godziny zadanie jest całodniowe.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Szacowany czas (godziny)</label>
                            <Input v-model="form.estimated_hours" type="number" min="0" />
                        </div>
                    </div>

                    <!-- Powtarzanie i przypomnienie mają sens tylko z terminem —
                         bez niego nie ma od czego odliczać ani co przesuwać. -->
                    <div v-if="form.due_date" class="pt-5 border-t border-slate-100 dark:border-slate-700 space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Przypomnienie</label>
                                <Select v-model="form.reminder_offset_minutes" :options="OPCJE_PRZYPOMNIENIA" />
                                <p class="text-xs text-foreground-muted mt-1">
                                    Trafi do Ciebie, wykonawcy i współpracowników.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Powtarzaj</label>
                                <Select v-model="form.recurrence_type" :options="TYPY_CYKLU" />
                            </div>
                        </div>

                        <div v-if="form.recurrence_type" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Co ile</label>
                                <Input v-model="form.recurrence_interval" type="number" min="1" max="365" />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Powtarzaj do (opcjonalnie)</label>
                                <Input v-model="form.recurrence_until" type="date" />
                                <p v-if="form.errors.recurrence_until" class="mt-1 text-sm text-red-600">{{ form.errors.recurrence_until }}</p>
                            </div>

                            <div v-if="form.recurrence_type === 'weekly'" class="md:col-span-2">
                                <label class="block text-sm font-medium text-foreground mb-1">W dni</label>
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="d in DNI_TYGODNIA" :key="d.id" type="button"
                                            @click="przelaczDzien(d.id)"
                                            :class="['h-9 w-11 rounded-md border text-sm font-medium transition-colors',
                                                     form.recurrence_weekdays.includes(d.id)
                                                        ? 'gradient-brand text-white border-transparent'
                                                        : 'border-border-bright text-foreground-muted hover:bg-surface-elevated']">
                                        {{ d.skrot }}
                                    </button>
                                </div>
                                <p class="text-xs text-foreground-muted mt-1">
                                    Nic nie zaznaczone — powtarzaj w ten sam dzień tygodnia co termin.
                                </p>
                            </div>
                        </div>

                        <p v-if="form.recurrence_type" class="text-xs text-foreground-muted">
                            Kolejne zadanie powstanie automatycznie w chwili zamknięcia tego.
                        </p>
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
