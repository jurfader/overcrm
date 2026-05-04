<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    mailConfigs: Array,
    emailHtmlFooter: String,
});

const footerForm = useForm({
    email_html_footer: props.emailHtmlFooter || '',
});

function saveFooter() {
    footerForm.put(route('settings.email-footer.update'), {
        preserveScroll: true,
        onSuccess: () => {},
    });
}

const showForm = ref(false);
const editingConfig = ref(null);
const testingConfig = ref(null);

const form = useForm({
    name: '',
    mail_host: '',
    mail_port: 587,
    mail_username: '',
    mail_password: '',
    mail_encryption: 'tls',
    mail_from_address: '',
    mail_from_name: '',
});

const isEditing = computed(() => !!editingConfig.value);

function openCreateForm() {
    editingConfig.value = null;
    form.reset();
    form.mail_port = 587;
    form.mail_encryption = 'tls';
    showForm.value = true;
}

function openEditForm(config) {
    editingConfig.value = config;
    form.name = config.name;
    form.mail_host = config.mail_host;
    form.mail_port = config.mail_port;
    form.mail_username = config.mail_username;
    form.mail_password = ''; // Nie wyświetlamy hasła
    form.mail_encryption = config.mail_encryption || 'none';
    form.mail_from_address = config.mail_from_address;
    form.mail_from_name = config.mail_from_name;
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editingConfig.value = null;
    form.reset();
}

function submit() {
    if (isEditing.value) {
        form.put(route('settings.mail.update', editingConfig.value.id), {
            onSuccess: () => closeForm(),
        });
    } else {
        form.post(route('settings.mail.store'), {
            onSuccess: () => closeForm(),
        });
    }
}

function deleteConfig(config) {
    if (confirm('Czy na pewno chcesz usunąć tę konfigurację?')) {
        router.delete(route('settings.mail.destroy', config.id));
    }
}

function setDefault(config) {
    router.post(route('settings.mail.default', config.id));
}

function testConnection(config) {
    testingConfig.value = config.id;
    router.post(route('settings.mail.test', config.id), {}, {
        onFinish: () => {
            testingConfig.value = null;
        },
    });
}

// Popularne konfiguracje SMTP
const presets = [
    { name: 'Gmail', host: 'smtp.gmail.com', port: 587, encryption: 'tls' },
    { name: 'Outlook/Office 365', host: 'smtp.office365.com', port: 587, encryption: 'tls' },
    { name: 'Yahoo', host: 'smtp.mail.yahoo.com', port: 587, encryption: 'tls' },
    { name: 'OVH', host: 'ssl0.ovh.net', port: 587, encryption: 'tls' },
    { name: 'Home.pl', host: 'smtp.home.pl', port: 587, encryption: 'tls' },
];

function applyPreset(preset) {
    form.mail_host = preset.host;
    form.mail_port = preset.port;
    form.mail_encryption = preset.encryption;
}
</script>

<template>
    <Head title="Konfiguracja serwera pocztowego" />

    <div class="space-y-6">
        <!-- Stopka wiadomości -->
        <div class="glass-card p-6">
            <h2 class="text-lg font-semibold text-foreground mb-2">Stopka wiadomości</h2>
            <p class="text-sm text-foreground-muted mb-4">
                HTML dodawany na końcu każdej wysyłanej wiadomości (np. dane firmy, podpis). Każdy użytkownik ma własną stopkę.
            </p>
            <textarea
                v-model="footerForm.email_html_footer"
                rows="4"
                class="w-full px-4 py-2 border border-border-bright rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary bg-surface-elevated text-foreground"
                placeholder="np. <p>Z poważaniem,<br>Jan Kowalski</p>"
            />
            <div class="mt-3 flex justify-end">
                <button
                    @click="saveFooter"
                    :disabled="footerForm.processing"
                    class="px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors disabled:opacity-50"
                >
                    {{ footerForm.processing ? 'Zapisywanie...' : 'Zapisz stopkę' }}
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Serwer pocztowy</h1>
                <p class="text-foreground-muted text-sm mt-1">Skonfiguruj serwer SMTP do wysyłania wiadomości email</p>
            </div>
            <button
                @click="openCreateForm"
                class="inline-flex items-center px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors"
            >
                <Icons name="plus" class="w-5 h-5 mr-2" />
                Dodaj konfigurację
            </button>
        </div>

        <!-- Lista konfiguracji -->
        <div class="glass-card overflow-hidden">
            <div v-if="mailConfigs.length === 0" class="p-12 text-center text-foreground-muted">
                <Icons name="mail" class="w-12 h-12 mx-auto mb-4 text-foreground-muted" />
                <p class="text-lg font-medium">Brak konfiguracji</p>
                <p class="mt-1">Dodaj konfigurację serwera SMTP, aby móc wysyłać emaile</p>
                <button
                    @click="openCreateForm"
                    class="mt-4 px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90"
                >
                    Dodaj pierwszą konfigurację
                </button>
            </div>

            <div v-else class="divide-y divide-border">
                <div
                    v-for="config in mailConfigs"
                    :key="config.id"
                    class="p-6 hover:bg-surface-elevated/50 transition-colors"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center"
                                :class="config.is_verified ? 'bg-green-100' : 'bg-amber-100'">
                                <Icons 
                                    :name="config.is_verified ? 'check-circle' : 'mail'" 
                                    class="w-6 h-6"
                                    :class="config.is_verified ? 'text-green-600' : 'text-amber-600'"
                                />
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-foreground">{{ config.name }}</h3>
                                    <span v-if="config.is_default" class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded-full">
                                        Domyślna
                                    </span>
                                    <span v-if="config.is_verified" class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded-full">
                                        Zweryfikowana
                                    </span>
                                    <span v-else class="px-2 py-0.5 bg-red-100 text-red-700 text-xs font-medium rounded-full">
                                        Niezweryfikowana
                                    </span>
                                </div>
                                <div class="mt-1 text-sm text-foreground-muted space-y-1">
                                    <p><strong>Host:</strong> {{ config.mail_host }}:{{ config.mail_port }}</p>
                                    <p><strong>Użytkownik:</strong> {{ config.mail_username }}</p>
                                    <p><strong>Nadawca:</strong> {{ config.mail_from_name }} &lt;{{ config.mail_from_address }}&gt;</p>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                @click="testConnection(config)"
                                :disabled="testingConfig === config.id"
                                class="px-3 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded-lg transition-colors disabled:opacity-50"
                            >
                                {{ testingConfig === config.id ? 'Testowanie...' : 'Testuj połączenie' }}
                            </button>
                            <button
                                v-if="!config.is_default"
                                @click="setDefault(config)"
                                class="px-3 py-1.5 text-sm text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                            >
                                Ustaw domyślną
                            </button>
                            <button
                                @click="openEditForm(config)"
                                class="px-3 py-1.5 text-sm text-foreground-muted hover:bg-surface-elevated rounded-lg transition-colors"
                            >
                                Edytuj
                            </button>
                            <button
                                @click="deleteConfig(config)"
                                class="px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                            >
                                Usuń
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Informacje -->
        <div class="bg-blue-50 rounded-xl p-6 border border-blue-100">
            <h4 class="font-semibold text-blue-800 mb-2">
                <Icons name="info" class="w-5 h-5 inline-block mr-1" />
                Jak to działa?
            </h4>
            <ul class="text-sm text-blue-700 space-y-1">
                <li>• Dodaj konfigurację serwera SMTP swojej skrzynki pocztowej</li>
                <li>• Kliknij "Testuj połączenie", aby wysłać testową wiadomość</li>
                <li>• Po weryfikacji będziesz mógł wysyłać emaile do klientów z szablonów</li>
                <li>• Dla Gmaila pamiętaj o włączeniu "Dostępu mniej bezpiecznych aplikacji" lub użyciu hasła aplikacji</li>
            </ul>
        </div>

        <!-- Modal formularza -->
        <div v-if="showForm" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div class="glass-card shadow-2xl w-full max-w-xl overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border bg-surface-2/50">
                    <h3 class="font-semibold text-foreground">
                        {{ isEditing ? 'Edycja konfiguracji' : 'Nowa konfiguracja SMTP' }}
                    </h3>
                    <button @click="closeForm" class="p-2 hover:bg-surface-elevated rounded-lg">
                        <Icons name="close" class="w-5 h-5 text-foreground-muted" />
                    </button>
                </div>

                <form @submit.prevent="submit" class="p-6 space-y-4">
                    <!-- Presety -->
                    <div v-if="!isEditing" class="mb-6">
                        <label class="block text-sm font-medium text-foreground mb-2">Szybka konfiguracja</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="preset in presets"
                                :key="preset.name"
                                type="button"
                                @click="applyPreset(preset)"
                                class="px-3 py-1.5 text-sm surface-elevated hover:bg-surface-elevated/70 text-foreground rounded-lg transition-colors"
                            >
                                {{ preset.name }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-foreground mb-1">
                            Nazwa konfiguracji *
                        </label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            placeholder="np. Firmowa poczta"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="mail_host" class="block text-sm font-medium text-foreground mb-1">
                                Serwer SMTP *
                            </label>
                            <input
                                id="mail_host"
                                v-model="form.mail_host"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                                placeholder="smtp.example.com"
                            />
                        </div>
                        <div>
                            <label for="mail_port" class="block text-sm font-medium text-foreground mb-1">
                                Port *
                            </label>
                            <input
                                id="mail_port"
                                v-model.number="form.mail_port"
                                type="number"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="mail_encryption" class="block text-sm font-medium text-foreground mb-1">
                            Szyfrowanie *
                        </label>
                        <select
                            id="mail_encryption"
                            v-model="form.mail_encryption"
                            required
                            class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                        >
                            <option value="tls">TLS (port 587)</option>
                            <option value="ssl">SSL (port 465)</option>
                            <option value="none">Brak</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="mail_username" class="block text-sm font-medium text-foreground mb-1">
                                Nazwa użytkownika *
                            </label>
                            <input
                                id="mail_username"
                                v-model="form.mail_username"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                                placeholder="user@example.com"
                            />
                        </div>
                        <div>
                            <label for="mail_password" class="block text-sm font-medium text-foreground mb-1">
                                Hasło {{ isEditing ? '(pozostaw puste aby nie zmieniać)' : '*' }}
                            </label>
                            <input
                                id="mail_password"
                                v-model="form.mail_password"
                                type="password"
                                :required="!isEditing"
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="mail_from_address" class="block text-sm font-medium text-foreground mb-1">
                                Email nadawcy *
                            </label>
                            <input
                                id="mail_from_address"
                                v-model="form.mail_from_address"
                                type="email"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                                placeholder="noreply@example.com"
                            />
                        </div>
                        <div>
                            <label for="mail_from_name" class="block text-sm font-medium text-foreground mb-1">
                                Nazwa nadawcy *
                            </label>
                            <input
                                id="mail_from_name"
                                v-model="form.mail_from_name"
                                type="text"
                                required
                                class="w-full px-4 py-2 border border-border-bright bg-surface-elevated text-foreground rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary"
                                placeholder="Twoja Firma"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-4 pt-4 border-t border-border">
                        <button
                            type="button"
                            @click="closeForm"
                            class="px-4 py-2 text-foreground-muted hover:text-foreground"
                        >
                            Anuluj
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-6 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors disabled:opacity-50"
                        >
                            {{ form.processing ? 'Zapisywanie...' : (isEditing ? 'Zapisz zmiany' : 'Dodaj konfigurację') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
