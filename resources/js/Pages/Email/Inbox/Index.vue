<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    mailConfigs: Array,
    selectedConfigId: [Number, String],
    emails: Array,
    total: Number,
    error: String,
});

const selectedConfigId = ref(props.selectedConfigId);
const loadingMessage = ref(null);
const messageDetail = ref(null);
const showMessageModal = ref(false);
const showComposeModal = ref(false);
const composeTo = ref('');
const composeToName = ref('');
const composeSubject = ref('');
const composeBody = ref('');
const composeConfigId = ref(props.selectedConfigId || props.mailConfigs?.[0]?.id);
const composeSending = ref(false);
const composeError = ref(null);

const refreshing = ref(false);

watch(selectedConfigId, (id) => {
    router.get(route('email.inbox.index'), { config_id: id || undefined }, { preserveState: true });
});

function refreshInbox() {
    refreshing.value = true;
    router.get(route('email.inbox.index'), { config_id: selectedConfigId.value || undefined, refresh: 1 }, {
        preserveState: true,
        onFinish: () => { refreshing.value = false; },
    });
}

watch(() => props.selectedConfigId, (id) => {
    composeConfigId.value = id || props.mailConfigs?.[0]?.id;
});

function openCompose(replyTo = null) {
    if (replyTo) {
        composeTo.value = replyTo.from || '';
        composeToName.value = replyTo.from_name || '';
        const subj = replyTo.subject || '';
        composeSubject.value = subj.startsWith('Re:') ? subj : 'Re: ' + subj;
        composeBody.value = '';
    } else {
        composeTo.value = '';
        composeToName.value = '';
        composeSubject.value = '';
        composeBody.value = '';
    }
    composeConfigId.value = selectedConfigId.value || props.mailConfigs?.[0]?.id;
    composeError.value = null;
    showComposeModal.value = true;
}

function closeComposeModal() {
    showComposeModal.value = false;
}

async function sendCompose() {
    if (!composeTo.value?.trim() || !composeSubject.value?.trim() || !composeBody.value?.trim()) {
        composeError.value = 'Wypełnij adres odbiorcy, temat i treść.';
        return;
    }
    composeSending.value = true;
    composeError.value = null;
    try {
        const res = await fetch(route('email.inbox.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                to_email: composeTo.value.trim(),
                to_name: composeToName.value.trim() || null,
                subject: composeSubject.value.trim(),
                html_content: composeBody.value.trim().replace(/\n/g, '<br>'),
                mail_config_id: composeConfigId.value,
            }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (data.success) {
            closeComposeModal();
            router.reload();
        } else {
            composeError.value = data.message || 'Błąd wysyłania';
        }
    } catch (e) {
        composeError.value = 'Błąd połączenia. Spróbuj ponownie.';
    } finally {
        composeSending.value = false;
    }
}

function openMessage(configId, uid) {
    loadingMessage.value = uid;
    messageDetail.value = null;
    showMessageModal.value = true;
    fetch(route('email.inbox.message', [configId, uid]), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            messageDetail.value = data;
        })
        .catch(() => {
            messageDetail.value = { error: 'Nie udało się załadować wiadomości' };
        })
        .finally(() => {
            loadingMessage.value = null;
        });
}

function closeMessageModal() {
    showMessageModal.value = false;
    messageDetail.value = null;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    const now = new Date();
    const diff = now - d;
    if (diff < 86400000) return d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
    if (diff < 604800000) return d.toLocaleDateString('pl-PL', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('pl-PL');
}
</script>

<template>
    <div>
        <Head title="Skrzynka odbiorcza" />

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-200">Skrzynka odbiorcza</h1>
                <div v-if="mailConfigs?.length > 0" class="flex items-center gap-2">
                    <button
                        @click="refreshInbox"
                        :disabled="refreshing"
                        class="inline-flex items-center gap-2 px-3 py-2 text-foreground-muted hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg font-medium transition-colors disabled:opacity-50"
                        title="Odśwież (pobierz najnowsze maile)"
                    >
                        <Icons name="refresh" class="w-5 h-5" :class="{ 'animate-spin': refreshing }" />
                        {{ refreshing ? 'Ładowanie…' : 'Odśwież' }}
                    </button>
                    <button
                        @click="openCompose()"
                        class="inline-flex items-center gap-2 px-4 py-2 gradient-brand text-white hover:opacity-90 rounded-lg font-medium transition-colors"
                    >
                        <Icons name="mail" class="w-5 h-5" />
                        Napisz
                    </button>
                </div>
            </div>

            <!-- Wybór konfiguracji -->
            <div v-if="mailConfigs.length > 0" class="flex flex-wrap gap-4 items-center">
                <label class="text-sm font-medium text-foreground-muted">Konto:</label>
                <select
                    v-model="selectedConfigId"
                    class="rounded-lg border-border-bright dark:bg-slate-700 dark:text-slate-200 text-sm"
                >
                    <option v-for="c in mailConfigs" :key="c.id" :value="c.id">
                        {{ c.name }} ({{ c.mail_from_address }})
                    </option>
                </select>
            </div>

            <!-- Brak konfiguracji -->
            <div v-else class="rounded-lg gradient-subtle border border-brand-primary/30 p-4">
                <p class="text-amber-800 dark:text-amber-200">
                    Nie masz zweryfikowanej konfiguracji serwera pocztowego.
                    <Link :href="route('settings.mail.index')" class="underline font-medium">Skonfiguruj SMTP</Link>
                    – te same dane służą do odczytu skrzynki (IMAP).
                </p>
            </div>

            <!-- Błąd IMAP -->
            <div v-if="error" class="rounded-lg bg-destructive/10 border border-destructive/30 p-4">
                <p class="text-red-700 dark:text-red-300">{{ error }}</p>
                <p class="text-sm text-destructive mt-1">
                    Dla Gmaila: imap.gmail.com:993 (SSL). Użyj hasła aplikacji.
                </p>
            </div>

            <!-- Lista wiadomości -->
            <div v-else-if="emails.length > 0" class="surface rounded-xl shadow-sm overflow-hidden">
                <div class="divide-y divide-slate-200 dark:divide-slate-700">
                    <button
                        v-for="email in emails"
                        :key="email.uid"
                        @click="openMessage(selectedConfigId, email.uid)"
                        class="w-full flex items-center gap-4 p-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors"
                        :class="{ 'surface-2/30': !email.is_seen }"
                    >
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <Icons name="mail" class="w-5 h-5 text-brand-primary" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-slate-900 dark:text-slate-200 truncate">
                                    {{ email.from_name || email.from || 'Nieznany' }}
                                </span>
                                <span class="text-xs text-foreground-muted shrink-0">
                                    {{ formatDate(email.date) }}
                                </span>
                            </div>
                            <p class="text-sm text-slate-600 dark:text-slate-300 truncate mt-0.5">
                                {{ email.subject || '(bez tematu)' }}
                            </p>
                        </div>
                        <Icons v-if="email.has_attachments" name="paper-clip" class="w-4 h-4 text-slate-400 shrink-0" />
                    </button>
                </div>
            </div>

            <!-- Pusta skrzynka -->
            <div v-else-if="selectedConfigId && !error" class="text-center py-12 text-foreground-muted">
                <Icons name="mail" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                <p>Brak wiadomości w skrzynce odbiorczej</p>
            </div>
        </div>

        <!-- Modal treści wiadomości -->
        <div
            v-if="showMessageModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @click.self="closeMessageModal"
        >
            <div class="surface rounded-xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                <div class="flex items-center justify-between p-4 border-b border-border">
                    <h3 class="font-semibold text-slate-900 dark:text-slate-200 truncate flex-1 mr-2">
                        {{ messageDetail?.subject || 'Ładowanie...' }}
                    </h3>
                    <div class="flex items-center gap-1 shrink-0">
                        <button
                            v-if="messageDetail && !messageDetail.error"
                            @click="openCompose(messageDetail); closeMessageModal();"
                            class="p-2 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/30 text-brand-primary"
                            title="Odpowiedz"
                        >
                            <Icons name="arrow-uturn-left" class="w-5 h-5" />
                        </button>
                        <button
                            @click="closeMessageModal"
                            class="p-2 rounded-lg hover:bg-surface-elevated text-slate-500"
                        >
                            <Icons name="close" class="w-5 h-5" />
                        </button>
                    </div>
                </div>
                <div v-if="loadingMessage" class="p-8 text-center text-slate-500">
                    Ładowanie wiadomości...
                </div>
                <div v-else-if="messageDetail?.error" class="p-8 text-center text-red-500">
                    {{ messageDetail.error }}
                </div>
                <div v-else-if="messageDetail" class="flex-1 overflow-auto p-4">
                    <div class="text-sm text-foreground-muted mb-4 space-y-1">
                        <p><strong>Od:</strong> {{ messageDetail.from_name || messageDetail.from }}</p>
                        <p><strong>Do:</strong> {{ messageDetail.to }}</p>
                        <p><strong>Data:</strong> {{ messageDetail.date }}</p>
                    </div>
                    <div
                        class="prose dark:prose-invert max-w-none text-foreground"
                        v-html="messageDetail.html"
                    />
                </div>
            </div>
        </div>

        <!-- Modal nowej wiadomości / odpowiedzi -->
        <div
            v-if="showComposeModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @click.self="closeComposeModal"
        >
            <div class="surface rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                <div class="flex items-center justify-between p-4 border-b border-border">
                    <h3 class="font-semibold text-slate-900 dark:text-slate-200">Nowa wiadomość</h3>
                    <button @click="closeComposeModal" class="p-2 rounded-lg hover:bg-surface-elevated text-slate-500">
                        <Icons name="close" class="w-5 h-5" />
                    </button>
                </div>
                <form @submit.prevent="sendCompose" class="flex-1 overflow-auto p-4 space-y-4">
                    <div v-if="composeError" class="p-3 rounded-lg bg-destructive/10 text-red-700 dark:text-red-300 text-sm">
                        {{ composeError }}
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Do *</label>
                        <input
                            v-model="composeTo"
                            type="email"
                            required
                            class="w-full px-4 py-2 border border-border-bright rounded-lg dark:bg-slate-700 dark:text-slate-200"
                            placeholder="adres@example.com"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Nazwa odbiorcy</label>
                        <input
                            v-model="composeToName"
                            type="text"
                            class="w-full px-4 py-2 border border-border-bright rounded-lg dark:bg-slate-700 dark:text-slate-200"
                            placeholder="np. Jan Kowalski"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Temat *</label>
                        <input
                            v-model="composeSubject"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-bright rounded-lg dark:bg-slate-700 dark:text-slate-200"
                            placeholder="Temat wiadomości"
                        />
                    </div>
                    <div v-if="mailConfigs?.length > 1">
                        <label class="block text-sm font-medium text-foreground mb-1">Wyślij z konta</label>
                        <select
                            v-model="composeConfigId"
                            class="w-full px-4 py-2 border border-border-bright rounded-lg dark:bg-slate-700 dark:text-slate-200"
                        >
                            <option v-for="c in mailConfigs" :key="c.id" :value="c.id">
                                {{ c.name }} ({{ c.mail_from_address }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Treść *</label>
                        <textarea
                            v-model="composeBody"
                            rows="8"
                            required
                            class="w-full px-4 py-2 border border-border-bright rounded-lg dark:bg-slate-700 dark:text-slate-200"
                            placeholder="Wpisz treść wiadomości..."
                        />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="closeComposeModal"
                            class="px-4 py-2 text-foreground-muted hover:text-slate-800 dark:hover:text-slate-200"
                        >
                            Anuluj
                        </button>
                        <button
                            type="submit"
                            :disabled="composeSending"
                            class="px-6 py-2 gradient-brand text-white hover:opacity-90 rounded-lg font-medium disabled:opacity-50"
                        >
                            {{ composeSending ? 'Wysyłanie...' : 'Wyślij' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
