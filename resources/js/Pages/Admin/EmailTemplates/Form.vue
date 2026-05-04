<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    template: Object,
    categories: Object,
    availableVariables: Object,
});

const isEditing = computed(() => !!props.template);

const form = useForm({
    name: props.template?.name || '',
    subject: props.template?.subject || '',
    description: props.template?.description || '',
    html_content: props.template?.html_content || getDefaultTemplate(),
    category: props.template?.category || 'offer',
    is_active: props.template?.is_active ?? true,
});

const preview = ref(null);
const showPreview = ref(false);
const isLoadingPreview = ref(false);

function getDefaultTemplate() {
    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{subject}}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background: #ffffff;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        .footer {
            background: #f3f4f6;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-radius: 0 0 8px 8px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        h1 { margin: 0; font-size: 24px; }
        .btn {
            display: inline-block;
            background: #f59e0b;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{company_name}}</h1>
    </div>
    <div class="content">
        <p>Szanowny/a <strong>{{client_name}}</strong>,</p>
        
        <p>Tutaj wpisz treść wiadomości...</p>
        
        <p>Z poważaniem,<br>
        {{user_name}}<br>
        {{company_name}}</p>
    </div>
    <div class="footer">
        <p>{{company_name}} | {{current_date}}</p>
        <p>Email: {{user_email}}</p>
    </div>
</body>
</html>`;
}

function submit() {
    if (isEditing.value) {
        form.put(route('admin.email-templates.update', props.template.id));
    } else {
        form.post(route('admin.email-templates.store'));
    }
}

async function loadPreview() {
    if (!props.template) {
        // Dla nowych szablonów pokazujemy prosty podgląd
        preview.value = {
            subject: form.subject.replace(/\{\{(\w+)\}\}/g, '[$1]'),
            html: form.html_content.replace(/\{\{(\w+)\}\}/g, '<span style="background:#fef3c7;padding:0 4px;border-radius:3px">[$1]</span>'),
        };
        showPreview.value = true;
        return;
    }

    isLoadingPreview.value = true;
    try {
        const response = await fetch(route('admin.email-templates.preview', props.template.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
        });
        const data = await response.json();
        preview.value = data;
        showPreview.value = true;
    } catch (error) {
        console.error('Błąd podglądu:', error);
    } finally {
        isLoadingPreview.value = false;
    }
}

function insertVariable(variable) {
    // Wstaw zmienną w miejscu kursora w polu html_content
    const textarea = document.getElementById('html_content');
    if (textarea) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = form.html_content;
        form.html_content = text.substring(0, start) + variable + text.substring(end);
        // Przywróć fokus
        setTimeout(() => {
            textarea.focus();
            textarea.setSelectionRange(start + variable.length, start + variable.length);
        }, 0);
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edycja szablonu' : 'Nowy szablon'" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">
                    {{ isEditing ? 'Edycja szablonu' : 'Nowy szablon email' }}
                </h1>
                <p class="text-foreground-muted text-sm mt-1">Utwórz lub edytuj szablon wiadomości email</p>
            </div>
            <Link
                :href="route('admin.email-templates.index')"
                class="text-foreground-muted hover:text-foreground"
            >
                ← Powrót do listy
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Formularz -->
            <div class="lg:col-span-2">
                <form @submit.prevent="submit" class="glass-card p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-foreground mb-1">
                                Nazwa szablonu *
                            </label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                                placeholder="np. Oferta handlowa"
                            />
                            <div v-if="form.errors.name" class="text-red-500 text-sm mt-1">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label for="category" class="block text-sm font-medium text-foreground mb-1">
                                Kategoria *
                            </label>
                            <select
                                id="category"
                                v-model="form.category"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            >
                                <option v-for="(label, key) in categories" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="subject" class="block text-sm font-medium text-foreground mb-1">
                            Temat wiadomości *
                        </label>
                        <input
                            id="subject"
                            v-model="form.subject"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-bright bg-surface-elevated rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            placeholder="np. Oferta dla {{client_name}}"
                        />
                        <p class="text-sm text-foreground-muted mt-1">Możesz użyć zmiennych, np. {{client_name}}</p>
                        <div v-if="form.errors.subject" class="text-red-500 text-sm mt-1">{{ form.errors.subject }}</div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-foreground mb-1">
                            Opis szablonu
                        </label>
                        <input
                            id="description"
                            v-model="form.description"
                            type="text"
                            class="w-full px-4 py-2 border border-border-bright bg-surface-elevated rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            placeholder="Krótki opis kiedy używać tego szablonu"
                        />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="html_content" class="block text-sm font-medium text-foreground">
                                Treść HTML *
                            </label>
                            <button
                                type="button"
                                @click="loadPreview"
                                :disabled="isLoadingPreview"
                                class="text-sm text-amber-600 hover:text-amber-800"
                            >
                                {{ isLoadingPreview ? 'Ładowanie...' : 'Podgląd →' }}
                            </button>
                        </div>
                        <textarea
                            id="html_content"
                            v-model="form.html_content"
                            required
                            rows="20"
                            class="w-full px-4 py-2 border border-border-bright bg-surface-elevated rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary font-mono text-sm"
                            placeholder="Wpisz lub wklej kod HTML szablonu..."
                        ></textarea>
                        <div v-if="form.errors.html_content" class="text-red-500 text-sm mt-1">{{ form.errors.html_content }}</div>
                    </div>

                    <div class="flex items-center">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="w-4 h-4 text-brand-primary border-border-bright rounded focus:ring-brand-primary"
                        />
                        <label for="is_active" class="ml-2 text-sm text-foreground">
                            Szablon aktywny (widoczny do wyboru)
                        </label>
                    </div>

                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-border">
                        <Link
                            :href="route('admin.email-templates.index')"
                            class="px-4 py-2 text-foreground-muted hover:text-foreground"
                        >
                            Anuluj
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-6 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors disabled:opacity-50"
                        >
                            {{ form.processing ? 'Zapisywanie...' : (isEditing ? 'Zapisz zmiany' : 'Utwórz szablon') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Panel boczny ze zmiennymi -->
            <div class="space-y-6">
                <div class="glass-card p-6">
                    <h3 class="text-lg font-semibold text-foreground mb-4">Wstaw zmienną</h3>
                    <p class="text-sm text-foreground-muted mb-4">Kliknij, aby wstawić zmienną w miejsce kursora</p>

                    <div class="space-y-2">
                        <button
                            v-for="(description, variable) in availableVariables"
                            :key="variable"
                            type="button"
                            @click="insertVariable(variable)"
                            class="w-full text-left p-2 hover:bg-amber-50 rounded-lg transition-colors group"
                        >
                            <code class="text-sm font-mono bg-surface-elevated group-hover:bg-amber-100 px-2 py-1 rounded text-amber-700">
                                {{ variable }}
                            </code>
                            <span class="block text-xs text-foreground-muted mt-1 ml-1">{{ description }}</span>
                        </button>
                    </div>
                </div>

                <div class="bg-amber-50 rounded-xl p-6 border border-amber-100">
                    <h4 class="font-semibold text-amber-800 mb-2">
                        <Icons name="info" class="w-5 h-5 inline-block mr-1" />
                        Wskazówki
                    </h4>
                    <ul class="text-sm text-amber-700 space-y-2">
                        <li>• Używaj inline CSS zamiast zewnętrznych arkuszy stylów</li>
                        <li>• Testuj szablon w różnych klientach pocztowych</li>
                        <li>• Zachowaj szerokość max. 600px dla lepszej kompatybilności</li>
                        <li>• Unikaj JavaScript - nie jest obsługiwany w mailach</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Modal podglądu -->
        <div v-if="showPreview" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div class="glass-card shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-surface-2/50">
                    <div>
                        <h3 class="font-semibold text-foreground">Podgląd szablonu</h3>
                        <p v-if="preview?.subject" class="text-sm text-foreground-muted">Temat: {{ preview.subject }}</p>
                    </div>
                    <button @click="showPreview = false" class="p-2 hover:bg-surface-elevated rounded-lg">
                        <Icons name="close" class="w-5 h-5" />
                    </button>
                </div>
                <div class="flex-1 overflow-auto p-6 bg-surface-2">
                    <div class="bg-white rounded-lg shadow max-w-2xl mx-auto">
                        <div v-if="preview?.html" v-html="preview.html"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
