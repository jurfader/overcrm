<script setup>
import { ref } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    lead: Object,
    statuses: Array,
    users: Array,
});

const emit = defineEmits(['close', 'updated']);
const newNote = ref('');
const addingNote = ref(false);
const converting = ref(false);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function changeStatus(statusId) {
    await fetch(route('leads.update-status', props.lead.id), {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        body: JSON.stringify({ status_id: statusId }),
    });
    emit('updated');
}

async function addNote() {
    if (!newNote.value.trim()) return;
    addingNote.value = true;
    try {
        await fetch(route('leads.add-note', props.lead.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: JSON.stringify({ note: newNote.value }),
        });
        newNote.value = '';
        emit('updated');
    } finally {
        addingNote.value = false;
    }
}

async function convertToClient() {
    if (!confirm('Czy na pewno chcesz skonwertować tego leada do klienta?')) return;
    converting.value = true;
    try {
        const res = await fetch(route('leads.convert', props.lead.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            emit('updated');
        } else {
            alert(data.error || 'Błąd konwersji');
        }
    } finally {
        converting.value = false;
    }
}

function formatDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
    catch { return d; }
}

const activityIcons = {
    created: 'plus',
    status_changed: 'refresh',
    email_sent: 'mail',
    email_opened: 'eye',
    call_detected: 'phone',
    note_added: 'edit',
    converted: 'check',
    manual: 'user',
};

const activityColors = {
    created: 'text-slate-400',
    status_changed: 'text-blue-500',
    email_sent: 'text-indigo-500',
    email_opened: 'text-amber-500',
    call_detected: 'text-emerald-500',
    note_added: 'text-slate-500',
    converted: 'text-green-600',
    manual: 'text-slate-400',
};
</script>

<template>
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black/50 z-40" @click="emit('close')" />

    <!-- Slide-over -->
    <div class="fixed inset-y-0 right-0 w-full max-w-lg z-50 surface shadow-2xl overflow-y-auto">
        <!-- Header -->
        <div class="sticky top-0 surface border-b border-border px-6 py-4 flex items-center justify-between z-10">
            <div>
                <h2 class="text-lg font-bold text-foreground">{{ lead.company_name || lead.name }}</h2>
                <p v-if="lead.company_name" class="text-sm text-foreground-muted">{{ lead.name }}</p>
            </div>
            <button @click="emit('close')" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <Icons name="close" class="w-5 h-5" />
            </button>
        </div>

        <div class="px-6 py-4 space-y-6">
            <!-- Status -->
            <div>
                <label class="text-xs font-medium text-foreground-muted uppercase mb-2 block">Status</label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="s in statuses"
                        :key="s.id"
                        :class="lead.status_id === s.id ? 'ring-2 ring-offset-1 ring-slate-900 dark:ring-white' : 'opacity-60 hover:opacity-100'"
                        class="px-3 py-1 rounded-full text-xs font-medium text-white transition-all"
                        :style="{ backgroundColor: s.color }"
                        @click="changeStatus(s.id)"
                    >
                        {{ s.name }}
                    </button>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="grid grid-cols-2 gap-3">
                <div v-if="lead.email">
                    <label class="text-xs text-foreground-muted">Email</label>
                    <a :href="`mailto:${lead.email}`" class="block text-sm text-blue-600 dark:text-blue-400 hover:underline truncate">{{ lead.email }}</a>
                </div>
                <div v-if="lead.phone">
                    <label class="text-xs text-foreground-muted">Telefon</label>
                    <a :href="`tel:${lead.phone}`" class="block text-sm text-blue-600 dark:text-blue-400 hover:underline">{{ lead.phone }}</a>
                </div>
                <div v-if="lead.nip">
                    <label class="text-xs text-foreground-muted">NIP</label>
                    <p class="text-sm text-foreground">{{ lead.nip }}</p>
                </div>
                <div v-if="lead.city">
                    <label class="text-xs text-foreground-muted">Miasto</label>
                    <p class="text-sm text-foreground">{{ lead.city }}</p>
                </div>
                <div v-if="lead.website" class="col-span-2">
                    <label class="text-xs text-foreground-muted">Strona www</label>
                    <a :href="lead.website" target="_blank" class="block text-sm text-blue-600 dark:text-blue-400 hover:underline truncate">{{ lead.website }}</a>
                </div>
                <div v-if="lead.address" class="col-span-2">
                    <label class="text-xs text-foreground-muted">Adres</label>
                    <p class="text-sm text-foreground">{{ lead.address }}</p>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="text-xs font-medium text-foreground-muted uppercase mb-2 block">Notatki</label>
                <p v-if="lead.notes" class="text-sm text-foreground whitespace-pre-wrap mb-3">{{ lead.notes }}</p>
                <div class="flex gap-2">
                    <input
                        v-model="newNote"
                        type="text"
                        placeholder="Dodaj notatkę..."
                        class="flex-1 rounded-lg border border-border-bright surface text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                        @keyup.enter="addNote"
                    />
                    <button
                        @click="addNote"
                        :disabled="addingNote || !newNote.trim()"
                        class="px-3 py-2 bg-amber-500 text-white rounded-lg text-sm hover:bg-amber-600 disabled:opacity-50 transition-colors"
                    >
                        {{ addingNote ? '...' : 'Dodaj' }}
                    </button>
                </div>
            </div>

            <!-- Convert Button -->
            <div v-if="!lead.converted_to_client_id">
                <button
                    @click="convertToClient"
                    :disabled="converting"
                    class="w-full py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
                >
                    <Icons name="check" class="w-4 h-4" />
                    {{ converting ? 'Konwertowanie...' : 'Konwertuj do klienta' }}
                </button>
            </div>
            <div v-else class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 p-3 text-sm text-emerald-700 dark:text-emerald-400">
                Skonwertowano {{ formatDate(lead.converted_at) }}
            </div>

            <!-- Activity Timeline -->
            <div>
                <label class="text-xs font-medium text-foreground-muted uppercase mb-3 block">Historia aktywności</label>
                <div v-if="lead.activities?.length" class="space-y-3">
                    <div v-for="a in lead.activities" :key="a.id" class="flex gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <Icons :name="activityIcons[a.type] || 'document-text'" :class="activityColors[a.type] || 'text-slate-400'" class="w-4 h-4" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-foreground">{{ a.description }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ a.user?.name || 'System' }} · {{ formatDate(a.created_at) }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-400">Brak aktywności</p>
            </div>
        </div>
    </div>
</template>
