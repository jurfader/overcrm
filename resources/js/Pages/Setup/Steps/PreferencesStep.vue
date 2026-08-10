<script setup>
import { router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Textarea from '@/Components/Textarea.vue';
import Switch from '@/Components/UI/Switch.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['next']);

const prefs = props.setup.preferences || {};

const form = useForm({
    app_timezone:          prefs.app_timezone || 'Europe/Warsaw',
    default_calendar_view: prefs.default_calendar_view || 'month',
    items_per_page:        prefs.items_per_page || 25,
    week_starts_monday:    prefs.week_starts_monday !== false && prefs.week_starts_monday !== '0',
    mail_from_address:     prefs.mail_from_address || props.setup.company.company_email || '',
    mail_from_name:        prefs.mail_from_name || props.setup.company.company_name || props.setup.brand.name || '',
    mail_signature:        prefs.mail_signature || '',
});

const TIMEZONES = {
    'Europe/Warsaw': 'Europa/Warszawa (UTC+1)',
    'Europe/London': 'Europa/Londyn (UTC+0)',
    'Europe/Berlin': 'Europa/Berlin (UTC+1)',
    'UTC': 'UTC',
};

const CALENDAR_VIEWS = {
    month: 'Miesiąc',
    week: 'Tydzień',
    day: 'Dzień',
};

const selectClass = 'h-9 w-full rounded-md border border-border-bright bg-surface text-foreground px-3 text-sm '
    + 'focus-visible:outline-none focus-visible:border-brand-primary focus-visible:ring-1 focus-visible:ring-brand-primary';

function submit() {
    form.post(route('setup.preferences'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

function skip() {
    router.post(route('setup.skip'), { step: 'preferences' }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

defineExpose({ submit, skip });
</script>

<template>
    <div class="space-y-6">
        <!-- Regionalne -->
        <section class="space-y-4">
            <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                <Icons name="globe" class="w-4 h-4 text-brand-primary" />
                Ustawienia regionalne
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-foreground">Strefa czasowa</span>
                    <select v-model="form.app_timezone" :class="[selectClass, 'mt-1.5']">
                        <option v-for="(label, value) in TIMEZONES" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-foreground">Domyślny widok kalendarza</span>
                    <select v-model="form.default_calendar_view" :class="[selectClass, 'mt-1.5']">
                        <option v-for="(label, value) in CALENDAR_VIEWS" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-foreground">Wyników na stronę</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">Listy klientów, zadań i zamówień</span>
                    <Input v-model="form.items_per_page" type="number" min="10" max="200" class="mt-1.5 max-w-[140px]" />
                    <span v-if="form.errors.items_per_page" class="text-xs text-destructive">{{ form.errors.items_per_page }}</span>
                </label>

                <div class="flex items-start gap-3 pt-6">
                    <Switch v-model="form.week_starts_monday" />
                    <span class="text-sm text-foreground-muted">Tydzień zaczyna się od poniedziałku</span>
                </div>
            </div>
        </section>

        <!-- Poczta -->
        <section class="space-y-4 pt-2 border-t border-border">
            <h3 class="text-sm font-semibold text-foreground flex items-center gap-2 pt-4">
                <Icons name="mail" class="w-4 h-4 text-brand-primary" />
                Poczta wychodząca
            </h3>

            <p class="text-xs text-foreground-muted">
                Dane nadawcy używane, gdy użytkownik nie ma własnej konfiguracji SMTP
                (Ustawienia → Konfiguracja poczty). Skrzynki osobiste dodacie później.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-sm font-medium text-foreground">Adres nadawcy</span>
                    <Input v-model="form.mail_from_address" type="email" placeholder="crm@twojafirma.pl" class="mt-1.5" />
                    <span v-if="form.errors.mail_from_address" class="text-xs text-destructive">{{ form.errors.mail_from_address }}</span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-foreground">Nazwa nadawcy</span>
                    <Input v-model="form.mail_from_name" class="mt-1.5" />
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-foreground">Stopka maili (HTML)</span>
                    <span class="block text-xs text-foreground-muted mt-0.5">Doklejana do wiadomości wysyłanych z systemu</span>
                    <Textarea v-model="form.mail_signature" :rows="4" class="mt-1.5" placeholder="<p>Pozdrawiam,<br>Zespół …</p>" />
                </label>
            </div>
        </section>

        <Button type="button" :loading="form.processing" @click="submit">
            <Icons name="check" class="w-4 h-4" />
            Zapisz preferencje
        </Button>
    </div>
</template>
