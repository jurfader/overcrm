<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const props = defineProps({
    setup: { type: Object, required: true },
});

const emit = defineEmits(['next']);

const company = props.setup.company || {};

const form = useForm({
    company_name:         company.company_name || props.setup.brand.company_name || '',
    company_nip:          company.company_nip || '',
    company_regon:        company.company_regon || '',
    company_address:      company.company_address || '',
    company_postal:       company.company_postal || '',
    company_city:         company.company_city || '',
    company_phone:        company.company_phone || '',
    company_email:        company.company_email || '',
    company_bank_account: company.company_bank_account || '',
});

const lookingUp = ref(false);
const lookupError = ref(null);
const lookupOk = ref(false);

/** Pobranie danych z GUS po NIP — uzupełnia formularz bez przeładowania. */
async function lookupNip() {
    lookupError.value = null;
    lookupOk.value = false;

    const nip = (form.company_nip || '').replace(/[^0-9]/g, '');
    if (nip.length !== 10) {
        lookupError.value = 'NIP musi mieć 10 cyfr';
        return;
    }

    lookingUp.value = true;
    try {
        const response = await fetch(`${route('setup.lookup-nip')}?nip=${nip}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            lookupError.value = data.message || 'Nie udało się pobrać danych z GUS';
            return;
        }

        for (const [key, value] of Object.entries(data.company)) {
            if (value) form[key] = value;
        }
        lookupOk.value = true;
    } catch (error) {
        lookupError.value = 'Błąd połączenia z usługą GUS';
    } finally {
        lookingUp.value = false;
    }
}

function submit() {
    form.post(route('setup.company'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

function skip() {
    router.post(route('setup.skip'), { step: 'company' }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('next'),
    });
}

defineExpose({ submit, skip });
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-foreground-muted">
            Dane trafiają na dokumenty (zamówienia, cenniki PDF), do stopek maili i nagłówków raportów.
        </p>

        <!-- NIP + GUS -->
        <div class="surface-elevated rounded-lg p-4 space-y-3">
            <label class="block">
                <span class="text-sm font-medium text-foreground">NIP</span>
                <span class="block text-xs text-foreground-muted mt-0.5">
                    {{ setup.gusAvailable
                        ? 'Wpisz NIP i pobierz resztę danych z bazy GUS.'
                        : 'Klucz GUS_API_KEY nie jest skonfigurowany — pobieranie z GUS niedostępne, uzupełnij dane ręcznie.' }}
                </span>
            </label>

            <div class="flex gap-2 flex-wrap">
                <Input v-model="form.company_nip" placeholder="1234563218" class="max-w-[220px] font-mono" />
                <Button type="button" variant="outline" :loading="lookingUp" :disabled="!setup.gusAvailable" @click="lookupNip">
                    <Icons name="search" class="w-4 h-4" />
                    Pobierz z GUS
                </Button>
            </div>

            <p v-if="lookupError" class="text-xs text-destructive">{{ lookupError }}</p>
            <p v-else-if="lookupOk" class="text-xs text-success flex items-center gap-1">
                <Icons name="check" class="w-3.5 h-3.5" />
                Dane pobrane z GUS — sprawdź i popraw, jeśli trzeba.
            </p>
        </div>

        <!-- Pozostałe pola -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block sm:col-span-2">
                <span class="text-sm font-medium text-foreground">Nazwa firmy</span>
                <Input v-model="form.company_name" placeholder="ACME Sp. z o.o." class="mt-1.5" />
                <span v-if="form.errors.company_name" class="text-xs text-destructive">{{ form.errors.company_name }}</span>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">REGON</span>
                <Input v-model="form.company_regon" class="mt-1.5" />
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">Numer konta bankowego</span>
                <Input v-model="form.company_bank_account" placeholder="PL 12 3456 7890 …" class="mt-1.5" />
            </label>

            <label class="block sm:col-span-2">
                <span class="text-sm font-medium text-foreground">Adres (ulica i numer)</span>
                <Input v-model="form.company_address" placeholder="ul. Prosta 1/10" class="mt-1.5" />
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">Kod pocztowy</span>
                <Input v-model="form.company_postal" placeholder="00-000" class="mt-1.5" />
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">Miasto</span>
                <Input v-model="form.company_city" class="mt-1.5" />
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">Telefon firmowy</span>
                <Input v-model="form.company_phone" class="mt-1.5" />
            </label>

            <label class="block">
                <span class="text-sm font-medium text-foreground">E-mail firmowy</span>
                <Input v-model="form.company_email" type="email" class="mt-1.5" />
                <span v-if="form.errors.company_email" class="text-xs text-destructive">{{ form.errors.company_email }}</span>
            </label>
        </div>

        <Button type="button" :loading="form.processing" @click="submit">
            <Icons name="check" class="w-4 h-4" />
            Zapisz dane firmy
        </Button>
    </div>
</template>
