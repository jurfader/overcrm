<script setup>
import { ref, nextTick, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    memory: String,
    meta: { type: Object, default: () => ({}) },
    messages: { type: Array, default: () => [] },
});

const WELCOME_MSG = {
    role: 'assistant',
    content: 'Cześć! Jestem asystentem do konfiguracji analizy AI rozmów telefonicznych.\n\nMogę pomóc Ci dostosować sposób w jaki AI ocenia rozmowy handlowe. Możesz mi powiedzieć np.:\n- „Nie oceniaj surowo krótkich rozmów potwierdzających zamówienie"\n- „Dodaj punkty jeśli handlowiec wspomni o Pakiecie Startowym"\n- „Klienci z Warszawy to głównie food trucki — traktuj ich jako zaawansowanych"\n\nCo chcesz zmienić?',
};

// Czat — współdzielona historia z DB
const messages = ref(props.messages.length > 0 ? [...props.messages] : [WELCOME_MSG]);
let lastMessageId = ref(messages.value.length ? Math.max(...messages.value.map(m => m.id || 0)) : 0);
const inputMessage = ref('');
const loading = ref(false);
const errorMsg = ref('');
const chatContainer = ref(null);

// Memory
const currentMemory = ref(props.memory || '');
const currentMeta = ref(props.meta || {});
const memoryUpdated = ref(false);
const showMemory = ref(true);

// Polling — inny user mógł edytować pamięć; synchronizuj co 15s
const memoryPollInterval = ref(null);

async function pollMemory() {
    try {
        const res = await fetch(route('admin.ai-training.memory'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const contentType = res.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) return;
        const data = await res.json();

        // Jeśli ktoś inny (nie w tym oknie) zmienił — zaktualizuj widok
        const incomingUpdatedAt = data.meta?.updated_at;
        const currentUpdatedAt = currentMeta.value?.updated_at;
        if (incomingUpdatedAt && incomingUpdatedAt !== currentUpdatedAt) {
            currentMemory.value = data.memory;
            currentMeta.value = data.meta;
        }
    } catch {}
}

const messagesPollInterval = ref(null);

onMounted(() => {
    // Guard przed duplikatem (np. HMR lub remount bez unmount)
    if (memoryPollInterval.value) clearInterval(memoryPollInterval.value);
    if (messagesPollInterval.value) clearInterval(messagesPollInterval.value);
    memoryPollInterval.value = setInterval(pollMemory, 15000);
    messagesPollInterval.value = setInterval(pollMessages, 10000); // polling wiadomości co 10s
    nextTick(() => scrollToBottom());
});
onBeforeUnmount(() => {
    if (memoryPollInterval.value) {
        clearInterval(memoryPollInterval.value);
        memoryPollInterval.value = null;
    }
    if (messagesPollInterval.value) {
        clearInterval(messagesPollInterval.value);
        messagesPollInterval.value = null;
    }
});

function formatMeta(meta) {
    if (!meta?.updated_at) return '';
    const date = new Date(meta.updated_at);
    const when = date.toLocaleString('pl-PL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    return `${meta.updated_by || 'System'} · ${when}`;
}

function formatTime(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        const today = new Date();
        if (d.toDateString() === today.toDateString()) {
            return d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleString('pl-PL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    } catch { return ''; }
}

async function sendMessage() {
    const text = inputMessage.value.trim();
    if (!text || loading.value) return;

    inputMessage.value = '';
    loading.value = true;
    errorMsg.value = '';
    memoryUpdated.value = false;

    try {
        const response = await fetch(route('admin.ai-training.chat'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ content: text }),
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.status === 401 || response.status === 419) {
                throw new Error('Sesja wygasła — odśwież stronę (Ctrl+R) i zaloguj się ponownie.');
            }
            throw new Error(`Serwer zwrócił nieprawidłową odpowiedź (HTTP ${response.status}). Spróbuj odświeżyć stronę.`);
        }

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || 'Błąd serwera');
        }

        // Zaktualizuj historię z DB (zawiera zarówno user message jak i assistant response)
        if (Array.isArray(data.messages)) {
            messages.value = data.messages;
            lastMessageId.value = messages.value.length ? Math.max(...messages.value.map(m => m.id || 0)) : 0;
        }

        if (data.memory_updated && data.memory) {
            currentMemory.value = data.memory;
            if (data.meta) currentMeta.value = data.meta;
            memoryUpdated.value = true;
        }
    } catch (e) {
        errorMsg.value = e.message;
        messages.value.push({
            role: 'assistant',
            content: '❌ ' + e.message,
        });
    } finally {
        loading.value = false;
        await nextTick();
        scrollToBottom();
    }
}

async function pollMessages() {
    try {
        const res = await fetch(route('admin.ai-training.messages'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const contentType = res.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) return;
        const data = await res.json();

        if (Array.isArray(data.messages)) {
            const newestId = data.messages.length ? Math.max(...data.messages.map(m => m.id || 0)) : 0;
            if (newestId !== lastMessageId.value) {
                messages.value = data.messages;
                lastMessageId.value = newestId;
                await nextTick();
                scrollToBottom();
            }
        }
    } catch {}
}

async function resetMemory() {
    if (!confirm('Czy na pewno chcesz wyczyścić całą pamięć AI? Wróci do domyślnych ustawień.')) return;

    const response = await fetch(route('admin.ai-training.reset'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
    });
    const data = await response.json();
    currentMemory.value = data.memory;
    if (data.meta) currentMeta.value = data.meta;
    messages.value.push({
        role: 'assistant',
        content: '✅ Pamięć AI została wyczyszczona. Model wróci do domyślnych instrukcji analizy.',
    });
}

async function clearChat() {
    if (!confirm('Wyczyścić historię czatu dla wszystkich użytkowników? Memory (zasady) nie zostaną zmienione.')) return;

    try {
        const res = await fetch(route('admin.ai-training.messages.clear'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            credentials: 'same-origin',
        });
        if (res.ok) {
            messages.value = [WELCOME_MSG];
            lastMessageId.value = 0;
            errorMsg.value = '';
        }
    } catch (e) {
        errorMsg.value = 'Błąd czyszczenia: ' + e.message;
    }
}

function scrollToBottom() {
    if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    }
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

// Formatowanie wiadomości (zachowaj entery jako <br>)
function formatMessage(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\n/g, '<br>');
}

const memoryIsDefault = computed(() =>
    !currentMemory.value || currentMemory.value.includes('brak dodatkowych zasad') || currentMemory.value.includes('brak dodatkowych instrukcji')
);
</script>

<template>
    <Head title="Uczenie AI" />

    <div class="max-w-7xl mx-auto">
        <!-- Nagłówek -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Uczenie AI</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-0.5">
                    Główny prompt analizy jest <strong class="text-slate-700 dark:text-slate-200">stały</strong> — przez czat dodajesz lub usuwasz tylko <strong class="text-slate-700 dark:text-slate-200">dodatkowe zasady</strong>, które są dołączane do każdej analizy.
                </p>
            </div>
            <button
                @click="showMemory = !showMemory"
                class="flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition"
            >
                <Icons name="document-text" class="w-4 h-4" />
                {{ showMemory ? 'Ukryj pamięć' : 'Pokaż pamięć' }}
            </button>
        </div>

        <div
            class="flex gap-6"
            :class="showMemory ? 'flex-col lg:flex-row' : ''"
            style="height: calc(100vh - 200px);"
        >
            <!-- Czat -->
            <div
                class="flex flex-col bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden min-h-0"
                :class="showMemory ? 'lg:flex-1' : 'w-full'"
            >
                <!-- Header czatu -->
                <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center">
                            <Icons name="sparkles" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white">Asystent AI</p>
                            <p class="text-xs text-slate-400">Gemini 2.5 Flash</p>
                        </div>
                    </div>
                    <button
                        @click="clearChat"
                        class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition"
                        title="Wyczyść historię czatu"
                    >
                        Wyczyść czat
                    </button>
                </div>

                <!-- Wiadomości -->
                <div
                    ref="chatContainer"
                    class="flex-1 min-h-0 overflow-y-auto p-4 space-y-4"
                >
                    <div
                        v-for="(msg, i) in messages"
                        :key="msg.id || ('local-' + i)"
                        class="flex gap-3"
                        :class="msg.role === 'user' ? 'flex-row-reverse' : ''"
                    >
                        <!-- Avatar -->
                        <div
                            class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold"
                            :class="msg.role === 'user'
                                ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'
                                : 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400'"
                            :title="msg.user_name || ''"
                        >
                            <Icons v-if="msg.role === 'assistant'" name="sparkles" class="w-4 h-4" />
                            <span v-else>{{ msg.user_name ? msg.user_name.substring(0, 2).toUpperCase() : 'Ty' }}</span>
                        </div>

                        <!-- Treść + autor + czas -->
                        <div class="max-w-[80%] flex flex-col" :class="msg.role === 'user' ? 'items-end' : 'items-start'">
                            <div v-if="msg.user_name || msg.created_at" class="text-[10px] text-slate-400 dark:text-slate-500 mb-0.5 px-1">
                                <span v-if="msg.role === 'user' && msg.user_name">{{ msg.user_name }}</span>
                                <span v-if="msg.user_name && msg.created_at"> · </span>
                                <span v-if="msg.created_at">{{ formatTime(msg.created_at) }}</span>
                            </div>
                            <div
                                class="rounded-2xl px-4 py-3 text-sm leading-relaxed"
                                :class="msg.role === 'user'
                                    ? 'bg-amber-500 text-white rounded-tr-sm'
                                    : 'bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200 rounded-tl-sm'"
                                v-html="formatMessage(msg.content)"
                            />
                        </div>
                    </div>

                    <!-- Typing indicator -->
                    <div v-if="loading" class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center">
                            <Icons name="sparkles" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div class="bg-slate-100 dark:bg-slate-700 rounded-2xl rounded-tl-sm px-4 py-3 flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:0ms"></span>
                            <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:150ms"></span>
                            <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:300ms"></span>
                        </div>
                    </div>

                    <!-- Memory updated badge -->
                    <div v-if="memoryUpdated" class="flex justify-center">
                        <span class="inline-flex items-center gap-1.5 text-xs bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800 rounded-full px-3 py-1">
                            <Icons name="check" class="w-3.5 h-3.5" />
                            Pamięć AI zaktualizowana
                        </span>
                    </div>
                </div>

                <!-- Input -->
                <div class="border-t border-slate-200 dark:border-slate-700 p-3 shrink-0">
                    <div class="flex gap-2 items-end">
                        <textarea
                            v-model="inputMessage"
                            @keydown="onKeydown"
                            placeholder="Napisz instrukcję dla AI… (Enter = wyślij, Shift+Enter = nowa linia)"
                            rows="2"
                            class="flex-1 resize-none rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-900 text-sm text-slate-800 dark:text-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400 placeholder-slate-400"
                            :disabled="loading"
                        />
                        <button
                            @click="sendMessage"
                            :disabled="loading || !inputMessage.trim()"
                            class="shrink-0 w-10 h-10 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white flex items-center justify-center transition"
                        >
                            <Icons name="paper-airplane" class="w-5 h-5" />
                        </button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5 ml-1">
                        Instrukcje są zapisywane automatycznie gdy AI zaktualizuje pamięć
                    </p>
                </div>
            </div>

            <!-- Panel pamięci -->
            <div
                v-if="showMemory"
                class="flex flex-col bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden min-h-0 lg:w-96"
            >
                <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0 space-y-1">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Icons name="document-text" class="w-4 h-4 text-slate-500" />
                            <span class="text-sm font-medium text-slate-900 dark:text-white">memory.md</span>
                            <span
                                class="text-xs px-1.5 py-0.5 rounded-full"
                                :class="memoryIsDefault
                                    ? 'bg-slate-100 dark:bg-slate-700 text-slate-500'
                                    : 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'"
                            >
                                {{ memoryIsDefault ? 'domyślna' : (currentMeta?.rules_count || 0) + ' zasad' }}
                            </span>
                        </div>
                        <button
                            @click="resetMemory"
                            class="text-xs text-red-400 hover:text-red-600 transition"
                            title="Wyczyść pamięć do domyślnych"
                        >
                            Resetuj
                        </button>
                    </div>
                    <p v-if="currentMeta?.updated_at" class="text-[11px] text-slate-400">
                        Ostatnia edycja: {{ formatMeta(currentMeta) }}
                    </p>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4">
                    <pre class="text-xs text-slate-600 dark:text-slate-400 whitespace-pre-wrap font-mono leading-relaxed">{{ currentMemory }}</pre>
                </div>

                <!-- Info -->
                <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/50 shrink-0">
                    <p class="text-xs text-slate-400 leading-relaxed">
                        Ten plik jest automatycznie dołączany do każdej analizy rozmowy. Modyfikuj go przez czat.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
