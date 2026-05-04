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
                    <h1 class="text-2xl font-bold gradient-brand-text">{{ task.title }}</h1>
                    <span v-if="task.status" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" :style="{ backgroundColor: task.status.color + '20', color: task.status.color }">
                        {{ task.status.name }}
                    </span>
                </div>
                <div class="flex items-center gap-4 mt-2 text-sm text-foreground-muted">
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
                    <p v-if="task.description" class="text-foreground whitespace-pre-wrap">{{ task.description }}</p>
                    <p v-else class="text-foreground-muted italic">Brak opisu</p>
                </Card>

                <!-- Notatki -->
                <Card v-if="task.notes" title="Notatki wewnętrzne">
                    <p class="text-foreground whitespace-pre-wrap">{{ task.notes }}</p>
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
                                    class="w-full px-3 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary text-sm resize-none"
                                    placeholder="Dodaj komentarz..."
                                    @keydown.ctrl.enter="submitComment"
                                ></textarea>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-foreground-subtle">Ctrl+Enter aby wysłać</span>
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
                    <div v-if="task.comments && task.comments.length > 0" class="space-y-4 border-t border-border pt-4">
                        <div v-for="comment in task.comments" :key="comment.id" class="flex gap-3">
                            <div class="flex-shrink-0 w-9 h-9 rounded-full surface-elevated flex items-center justify-center text-sm font-bold text-foreground">
                                {{ comment.user?.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-foreground">{{ comment.user?.name }}</span>
                                        <span class="text-xs text-foreground-subtle">{{ timeAgo(comment.created_at) }}</span>
                                    </div>
                                    <button
                                        v-if="comment.user_id === currentUser?.id || currentUser?.role === 'admin'"
                                        @click="deleteComment(comment.id)"
                                        class="text-foreground-muted hover:text-red-500 transition-colors"
                                        title="Usuń komentarz"
                                    >
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                                <p class="text-sm text-foreground mt-1 whitespace-pre-wrap">{{ comment.body }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Brak komentarzy -->
                    <div v-else class="text-center py-4 border-t border-border mt-2 pt-4">
                        <p class="text-sm text-foreground-subtle">Brak komentarzy. Bądź pierwszy!</p>
                    </div>
                </Card>

                <!-- Historia zmian — tylko admin/manager -->
                <Card v-if="activityLogs && activityLogs.length > 0">
                    <template #header>
                        <button 
                            @click="showHistory = !showHistory"
                            class="flex items-center gap-2 w-full text-left"
                        >
                            <Icons name="activity" class="w-5 h-5 text-foreground-subtle" />
                            <span class="text-lg font-semibold text-foreground">Historia zmian</span>
                            <Badge color="gray">{{ activityLogs.length }}</Badge>
                            <Icons 
                                name="chevron-down" 
                                class="w-4 h-4 text-foreground-subtle ml-auto transition-transform" 
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
                            <div v-if="index < activityLogs.length - 1" class="absolute left-[9px] top-5 bottom-0 w-px surface-elevated"></div>

                            <!-- Kropka -->
                            <div 
                                class="absolute left-0 top-1 w-[18px] h-[18px] rounded-full border-2 flex items-center justify-center"
                                :class="{
                                    'bg-green-100 border-green-400': log.action === 'create',
                                    'bg-amber-100 border-amber-400': log.action === 'update',
                                    'bg-red-100 border-red-400': log.action === 'delete',
                                    'bg-purple-100 border-purple-400': log.action === 'restore',
                                    'surface-elevated border-border-bright': !['create','update','delete','restore'].includes(log.action),
                                }"
                            >
                                <div class="w-1.5 h-1.5 rounded-full" :class="{
                                    'bg-green-500': log.action === 'create',
                                    'bg-amber-500': log.action === 'update',
                                    'bg-red-500': log.action === 'delete',
                                    'bg-purple-500': log.action === 'restore',
                                    'bg-foreground-muted': !['create','update','delete','restore'].includes(log.action),
                                }"></div>
                            </div>

                            <!-- Treść -->
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-foreground">{{ log.user_name }}</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded font-medium" :class="{
                                        'bg-green-100 text-green-700': log.action === 'create',
                                        'bg-amber-100 text-amber-700': log.action === 'update',
                                        'bg-red-100 text-red-700': log.action === 'delete',
                                        'bg-purple-100 text-purple-700': log.action === 'restore',
                                        'surface-elevated text-foreground-muted': !['create','update','delete','restore'].includes(log.action),
                                    }">{{ log.action_label }}</span>
                                    <span class="text-xs text-foreground-subtle">
                                        {{ new Date(log.created_at).toLocaleString('pl-PL') }}
                                    </span>
                                </div>

                                <p v-if="log.description" class="text-xs text-foreground-muted mt-0.5">{{ log.description }}</p>

                                <!-- Zmienione pola -->
                                <div v-if="log.action === 'update' && getChangedFields(log).length > 0" class="mt-2 space-y-1">
                                    <div 
                                        v-for="change in getChangedFields(log)" 
                                        :key="change.key"
                                        class="text-xs surface-2 rounded-lg px-3 py-1.5 flex items-start gap-2"
                                    >
                                        <span class="font-medium text-foreground-muted shrink-0 min-w-[80px]">{{ change.field }}:</span>
                                        <span class="text-red-500 line-through">{{ change.old }}</span>
                                        <span class="text-foreground-muted">→</span>
                                        <span class="text-success font-medium">{{ change.new }}</span>
                                    </div>
                                </div>

                                <!-- IP (dla szczegółowości) -->
                                <div v-if="log.ip_address" class="mt-1">
                                    <span class="text-[10px] text-foreground-subtle opacity-50 font-mono">IP: {{ log.ip_address }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="text-center py-2">
                        <p class="text-xs text-foreground-subtle">Kliknij aby rozwinąć historię zmian</p>
                    </div>
                </Card>
            </div>

            <div class="space-y-6">
                <!-- Szczegóły -->
                <Card title="Szczegóły">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-foreground-muted">Priorytet</dt>
                            <dd class="mt-1">
                                <Badge :color="priorityColors[task.priority]">
                                    {{ priorityLabels[task.priority] }}
                                </Badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-foreground-muted">Klient</dt>
                            <dd class="mt-1">
                                <Link v-if="task.client" :href="route('clients.show', task.client.id)" class="text-brand-primary hover:opacity-80">
                                    {{ task.client.short_name || task.client.name }}
                                </Link>
                                <span v-else class="text-foreground-muted">Nie przypisano</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-foreground-muted">Przypisany do</dt>
                            <dd class="mt-1 text-foreground">
                                {{ task.assignee?.name || 'Nie przypisano' }}
                            </dd>
                        </div>
                        <div v-if="task.estimated_hours">
                            <dt class="text-sm font-medium text-foreground-muted">Szacowany czas</dt>
                            <dd class="mt-1 text-foreground">{{ task.estimated_hours }} h</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Terminy -->
                <Card title="Terminy">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-foreground-muted">Data zgłoszenia</dt>
                            <dd class="mt-1 text-foreground">{{ formatDate(task.submit_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-foreground-muted">Termin realizacji</dt>
                            <dd class="mt-1" :class="isOverdue() ? 'text-red-500 font-medium' : 'text-foreground'">
                                {{ formatDate(task.due_date) }}
                                <span v-if="isOverdue()" class="ml-2 text-xs">(Przeterminowane!)</span>
                            </dd>
                        </div>
                        <div v-if="task.completed_at">
                            <dt class="text-sm font-medium text-foreground-muted">Ukończono</dt>
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
