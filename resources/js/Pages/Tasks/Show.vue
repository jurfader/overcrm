<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);

const props = defineProps({
    task: Object,
    activityLogs: Array,
});

// Historia zmian — widoczna tylko gdy dane są przekazane (admin/manager)
const showHistory = ref(false);

const fieldLabels = {
    title: 'Tytuł',
    description: 'Opis',
    status_id: 'Status',
    client_id: 'Klient',
    assigned_to: 'Przypisany do',
    priority: 'Priorytet',
    due_date: 'Termin',
    submit_date: 'Data zgłoszenia',
    estimated_hours: 'Szacowany czas',
    notes: 'Notatki',
    completed_at: 'Data ukończenia',
};

const priorityLabelsMap = {
    low: 'Niski',
    medium: 'Średni',
    high: 'Wysoki',
    urgent: 'Pilny',
};

function getChangedFields(log) {
    if (!log.old_values || !log.new_values) return [];
    const changes = [];
    const skip = ['updated_at', 'created_at', 'created_by', 'deleted_at'];

    for (const key of Object.keys(log.new_values)) {
        if (skip.includes(key)) continue;
        const oldVal = log.old_values[key];
        const newVal = log.new_values[key];
        if (String(oldVal ?? '') !== String(newVal ?? '')) {
            changes.push({
                field: fieldLabels[key] || key,
                key,
                old: formatFieldValue(key, oldVal),
                new: formatFieldValue(key, newVal),
            });
        }
    }
    return changes;
}

function formatFieldValue(key, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (key === 'priority') return priorityLabelsMap[value] || value;
    if (key === 'due_date' || key === 'submit_date' || key === 'completed_at') {
        return value ? new Date(value).toLocaleDateString('pl-PL') : '—';
    }
    if (typeof value === 'string' && value.length > 100) {
        return value.substring(0, 100) + '...';
    }
    return String(value);
}

// Komentarze
const commentForm = useForm({
    body: '',
});

function submitComment() {
    commentForm.post(route('tasks.comments.store', props.task.id), {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
    });
}

function deleteComment(commentId) {
    if (!confirm('Usunąć ten komentarz?')) return;
    router.delete(route('tasks.comments.destroy', [props.task.id, commentId]), {
        preserveScroll: true,
    });
}

function timeAgo(date) {
    const now = new Date();
    const d = new Date(date);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'przed chwilą';
    if (diff < 3600) return `${Math.floor(diff / 60)} min temu`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} godz. temu`;
    if (diff < 604800) return `${Math.floor(diff / 86400)} dni temu`;
    return d.toLocaleDateString('pl-PL');
}

const showDeleteModal = ref(false);
const deleting = ref(false);

const priorityColors = {
    low: 'gray',
    medium: 'blue',
    high: 'yellow',
    urgent: 'red',
};

const priorityLabels = {
    low: 'Niski',
    medium: 'Średni',
    high: 'Wysoki',
    urgent: 'Pilny',
};

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pl-PL');
}

function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('pl-PL');
}

function isOverdue() {
    if (!props.task.due_date || props.task.status?.is_final) return false;
    return new Date(props.task.due_date) < new Date().setHours(0,0,0,0);
}

function deleteTask() {
    deleting.value = true;
    router.delete(route('tasks.destroy', props.task.id), {
        onFinish: () => deleting.value = false,
    });
}
</script>

<template>
    <Head :title="task.title" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-gray-900">{{ task.title }}</h1>
                    <span v-if="task.status" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" :style="{ backgroundColor: task.status.color + '20', color: task.status.color }">
                        {{ task.status.name }}
                    </span>
                </div>
                <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                    <span>Utworzono: {{ formatDateTime(task.created_at) }}</span>
                    <span v-if="task.creator">przez {{ task.creator.name }}</span>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <Link :href="route('tasks.index')">
                    <Button variant="secondary">
                        <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                        Powrót
                    </Button>
                </Link>
                <Link :href="route('tasks.edit', task.id)">
                    <Button>
                        <Icons name="edit" class="w-5 h-5 mr-2" />
                        Edytuj
                    </Button>
                </Link>
                <Button variant="danger" @click="showDeleteModal = true">
                    <Icons name="trash" class="w-5 h-5 mr-2" />
                    Usuń
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- Opis -->
                <Card title="Opis">
                    <p v-if="task.description" class="text-gray-700 whitespace-pre-wrap">{{ task.description }}</p>
                    <p v-else class="text-gray-400 italic">Brak opisu</p>
                </Card>

                <!-- Notatki -->
                <Card v-if="task.notes" title="Notatki wewnętrzne">
                    <p class="text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ task.notes }}</p>
                </Card>

                <!-- Komentarze -->
                <Card title="Komentarze">
                    <!-- Formularz dodawania -->
                    <form @submit.prevent="submitComment" class="mb-6">
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-amber-500 flex items-center justify-center text-sm font-bold text-white">
                                {{ currentUser?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="flex-1">
                                <textarea
                                    v-model="commentForm.body"
                                    rows="2"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 dark:placeholder-slate-400 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500 text-sm resize-none"
                                    placeholder="Dodaj komentarz..."
                                    @keydown.ctrl.enter="submitComment"
                                ></textarea>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-gray-400 dark:text-slate-500">Ctrl+Enter aby wysłać</span>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="!commentForm.body.trim() || commentForm.processing"
                                    >
                                        {{ commentForm.processing ? 'Wysyłanie...' : 'Dodaj komentarz' }}
                                    </Button>
                                </div>
                                <p v-if="commentForm.errors.body" class="text-red-500 text-xs mt-1">{{ commentForm.errors.body }}</p>
                            </div>
                        </div>
                    </form>

                    <!-- Lista komentarzy -->
                    <div v-if="task.comments && task.comments.length > 0" class="space-y-4 border-t border-gray-200 dark:border-slate-700 pt-4">
                        <div v-for="comment in task.comments" :key="comment.id" class="flex gap-3">
                            <div class="flex-shrink-0 w-9 h-9 rounded-full bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-sm font-bold text-slate-600 dark:text-slate-300">
                                {{ comment.user?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-slate-200">{{ comment.user?.name }}</span>
                                        <span class="text-xs text-gray-400 dark:text-slate-500">{{ timeAgo(comment.created_at) }}</span>
                                    </div>
                                    <button
                                        v-if="comment.user_id === currentUser?.id || currentUser?.role === 'admin'"
                                        @click="deleteComment(comment.id)"
                                        class="text-gray-300 hover:text-red-500 dark:text-slate-600 dark:hover:text-red-400 transition-colors"
                                        title="Usuń komentarz"
                                    >
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                                <p class="text-sm text-gray-700 dark:text-slate-300 mt-1 whitespace-pre-wrap">{{ comment.body }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Brak komentarzy -->
                    <div v-else class="text-center py-4 border-t border-gray-200 dark:border-slate-700 mt-2 pt-4">
                        <p class="text-sm text-gray-400 dark:text-slate-500">Brak komentarzy. Bądź pierwszy!</p>
                    </div>
                </Card>

                <!-- Historia zmian — tylko admin/manager -->
                <Card v-if="activityLogs && activityLogs.length > 0">
                    <template #header>
                        <button 
                            @click="showHistory = !showHistory"
                            class="flex items-center gap-2 w-full text-left"
                        >
                            <Icons name="activity" class="w-5 h-5 text-gray-400 dark:text-slate-500" />
                            <span class="text-lg font-semibold text-gray-900 dark:text-slate-200">Historia zmian</span>
                            <Badge color="gray">{{ activityLogs.length }}</Badge>
                            <Icons 
                                name="chevron-down" 
                                class="w-4 h-4 text-gray-400 dark:text-slate-500 ml-auto transition-transform" 
                                :class="{ 'rotate-180': showHistory }"
                            />
                        </button>
                    </template>

                    <div v-if="showHistory" class="space-y-0">
                        <div 
                            v-for="(log, index) in activityLogs" 
                            :key="log.id" 
                            class="relative pl-6 pb-5 last:pb-0"
                        >
                            <!-- Linia osi czasu -->
                            <div v-if="index < activityLogs.length - 1" class="absolute left-[9px] top-5 bottom-0 w-px bg-gray-200 dark:bg-slate-700"></div>

                            <!-- Kropka -->
                            <div 
                                class="absolute left-0 top-1 w-[18px] h-[18px] rounded-full border-2 flex items-center justify-center"
                                :class="{
                                    'bg-green-100 border-green-400 dark:bg-green-900/30 dark:border-green-600': log.action === 'create',
                                    'bg-amber-100 border-amber-400 dark:bg-amber-900/30 dark:border-amber-600': log.action === 'update',
                                    'bg-red-100 border-red-400 dark:bg-red-900/30 dark:border-red-600': log.action === 'delete',
                                    'bg-purple-100 border-purple-400 dark:bg-purple-900/30 dark:border-purple-600': log.action === 'restore',
                                    'bg-gray-100 border-gray-400 dark:bg-gray-700 dark:border-gray-500': !['create','update','delete','restore'].includes(log.action),
                                }"
                            >
                                <div class="w-1.5 h-1.5 rounded-full" :class="{
                                    'bg-green-500': log.action === 'create',
                                    'bg-amber-500': log.action === 'update',
                                    'bg-red-500': log.action === 'delete',
                                    'bg-purple-500': log.action === 'restore',
                                    'bg-gray-400': !['create','update','delete','restore'].includes(log.action),
                                }"></div>
                            </div>

                            <!-- Treść -->
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-900 dark:text-slate-200">{{ log.user_name }}</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded font-medium" :class="{
                                        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': log.action === 'create',
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': log.action === 'update',
                                        'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': log.action === 'delete',
                                        'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400': log.action === 'restore',
                                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': !['create','update','delete','restore'].includes(log.action),
                                    }">{{ log.action_label }}</span>
                                    <span class="text-xs text-gray-400 dark:text-slate-500">
                                        {{ new Date(log.created_at).toLocaleString('pl-PL') }}
                                    </span>
                                </div>

                                <p v-if="log.description" class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ log.description }}</p>

                                <!-- Zmienione pola -->
                                <div v-if="log.action === 'update' && getChangedFields(log).length > 0" class="mt-2 space-y-1">
                                    <div 
                                        v-for="change in getChangedFields(log)" 
                                        :key="change.key"
                                        class="text-xs bg-gray-50 dark:bg-slate-900/50 rounded-lg px-3 py-1.5 flex items-start gap-2"
                                    >
                                        <span class="font-medium text-gray-600 dark:text-slate-400 shrink-0 min-w-[80px]">{{ change.field }}:</span>
                                        <span class="text-red-500 dark:text-red-400 line-through">{{ change.old }}</span>
                                        <span class="text-gray-400 dark:text-slate-600">→</span>
                                        <span class="text-green-600 dark:text-green-400 font-medium">{{ change.new }}</span>
                                    </div>
                                </div>

                                <!-- IP (dla szczegółowości) -->
                                <div v-if="log.ip_address" class="mt-1">
                                    <span class="text-[10px] text-gray-300 dark:text-slate-600 font-mono">IP: {{ log.ip_address }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="text-center py-2">
                        <p class="text-xs text-gray-400 dark:text-slate-500">Kliknij aby rozwinąć historię zmian</p>
                    </div>
                </Card>
            </div>

            <div class="space-y-6">
                <!-- Szczegóły -->
                <Card title="Szczegóły">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Priorytet</dt>
                            <dd class="mt-1">
                                <Badge :color="priorityColors[task.priority]">
                                    {{ priorityLabels[task.priority] }}
                                </Badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Klient</dt>
                            <dd class="mt-1">
                                <Link v-if="task.client" :href="route('clients.show', task.client.id)" class="text-indigo-600 hover:text-indigo-800">
                                    {{ task.client.short_name || task.client.name }}
                                </Link>
                                <span v-else class="text-gray-400">Nie przypisano</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Przypisany do</dt>
                            <dd class="mt-1 text-gray-900">
                                {{ task.assignee?.name || 'Nie przypisano' }}
                            </dd>
                        </div>
                        <div v-if="task.estimated_hours">
                            <dt class="text-sm font-medium text-gray-500">Szacowany czas</dt>
                            <dd class="mt-1 text-gray-900">{{ task.estimated_hours }} h</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Terminy -->
                <Card title="Terminy">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Data zgłoszenia</dt>
                            <dd class="mt-1 text-gray-900">{{ formatDate(task.submit_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Termin realizacji</dt>
                            <dd class="mt-1" :class="isOverdue() ? 'text-red-600 font-medium' : 'text-gray-900'">
                                {{ formatDate(task.due_date) }}
                                <span v-if="isOverdue()" class="ml-2 text-xs">(Przeterminowane!)</span>
                            </dd>
                        </div>
                        <div v-if="task.completed_at">
                            <dt class="text-sm font-medium text-gray-500">Ukończono</dt>
                            <dd class="mt-1 text-green-600">{{ formatDateTime(task.completed_at) }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </div>

    <ConfirmModal
        :show="showDeleteModal"
        title="Usuń zadanie"
        :message="`Czy na pewno chcesz przenieść zadanie '${task.title}' do kosza?`"
        confirm-text="Tak, przenieś do kosza"
        :processing="deleting"
        @confirm="deleteTask"
        @cancel="showDeleteModal = false"
    />
</template>
