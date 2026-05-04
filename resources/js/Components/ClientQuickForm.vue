<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    initialNip: { type: String, default: '' },
    initialName: { type: String, default: '' },
});

const emit = defineEmits(['created', 'cancel']);

const nipInput = ref(null);
const gusLoading = ref(false);
const saving = ref(false);
const gusMessage = ref('');
const gusMessageType = ref('info'); // info | success | error

const form = reactive({
    type: 'company',
    nip: props.initialNip,
    name: props.initialName,
    short_name: '',
    regon: '',
    email: '',
    phone: '',
    contact_person: '',
    street: '',
    building_number: '',
    apartment_number: '',
    postal_code: '',
    city: '',
});

const errors = reactive({});

const gusFilled = ref(false);

function csrf() {
    const t = document.cookie?.split('; ')?.find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1];
    return t ? decodeURIComponent(t) : '';
}

function clearErrors() {
    Object.keys(errors).forEach(k => delete errors[k]);
}

async function fetchFromGus() {
    const nipNormalized = (form.nip || '').replace(/[^0-9]/g, '');
    if (nipNormalized.length !== 10) {
        gusMessage.value = 'NIP musi mieć 10 cyfr';
        gusMessageType.value = 'error';
        return;
    }

    gusLoading.value = true;
    gusMessage.value = '';
    clearErrors();

    try {
        const res = await fetch(route('clients.lookup-nip') + '?nip=' + nipNormalized, {
            headers: { 'Accept': 'application/json' },
        });
        const result = await res.json();

        if (result.success && result.existing_client) {
            // Klient już istnieje → zwracamy od razu, bez tworzenia
            emit('created', { ...result.existing_client, existing: true });
            return;
        }

        if (result.success && result.data) {
            const d = result.data;
            if (d.name) form.name = d.name;
            if (d.short_name) form.short_name = d.short_name;
            if (d.regon) form.regon = d.regon;
            if (d.street) form.street = d.street;
            if (d.building_number) form.building_number = d.building_number;
            if (d.apartment_number) form.apartment_number = d.apartment_number;
            if (d.postal_code) form.postal_code = d.postal_code;
            if (d.city) form.city = d.city;
            gusFilled.value = true;
            gusMessage.value = 'Dane z GUS pobrane — uzupełnij telefon/email i zapisz';
            gusMessageType.value = 'success';
        } else {
            gusMessage.value = result.message || 'Nie znaleziono firmy w GUS';
            gusMessageType.value = 'error';
        }
    } catch (e) {
        gusMessage.value = 'Błąd pobierania z GUS — spróbuj ponownie';
        gusMessageType.value = 'error';
    } finally {
        gusLoading.value = false;
    }
}

async function save() {
    if (saving.value) return;
    if (!form.name?.trim()) {
        errors.name = ['Nazwa jest wymagana'];
        return;
    }

    saving.value = true;
    clearErrors();

    try {
        const res = await fetch(route('clients.quick-store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(form),
        });

        const data = await res.json();

        if (res.ok && data.success) {
            emit('created', { ...data.client, existing: data.existing || false });
            return;
        }

        if (data.errors) {
            Object.assign(errors, data.errors);
        }
        if (data.message && !data.errors) {
            errors._global = [data.message];
        }
    } catch (e) {
        errors._global = ['Błąd sieci: ' + e.message];
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await nextTick();
    nipInput.value?.focus();
    if (props.initialNip && props.initialNip.replace(/\D/g, '').length === 10) {
        fetchFromGus();
    }
});
</script>

<template>
    <div class="space-y-3">
        <!-- Global error -->
        <div v-if="errors._global" class="p-2 bg-red-50 text-red-700 text-sm rounded border border-red-200">
            {{ errors._global[0] }}
        </div>

        <!-- Typ -->
        <div class="flex gap-2">
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input type="radio" v-model="form.type" value="company" />
                <span>Firma</span>
            </label>
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input type="radio" v-model="form.type" value="person" />
                <span>Osoba prywatna</span>
            </label>
        </div>

        <!-- NIP + GUS -->
        <div v-if="form.type === 'company'">
            <label class="block text-xs font-medium text-slate-600 mb-1">NIP</label>
            <div class="flex gap-2">
                <input
                    ref="nipInput"
                    v-model="form.nip"
                    type="text"
                    placeholder="10 cyfr"
                    maxlength="15"
                    class="flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    :class="{ 'border-red-400': errors.nip }"
                    @keyup.enter="fetchFromGus"
                />
                <button
                    type="button"
                    @click="fetchFromGus"
                    :disabled="gusLoading || !form.nip"
                    class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 flex items-center gap-1.5 transition-colors"
                >
                    <Icons v-if="gusLoading" name="sync" class="w-4 h-4 animate-spin" />
                    <Icons v-else name="search" class="w-4 h-4" />
                    {{ gusLoading ? '...' : 'Pobierz z GUS' }}
                </button>
            </div>
            <p v-if="errors.nip" class="text-red-500 text-xs mt-1">{{ errors.nip[0] }}</p>
            <p v-if="gusMessage" class="text-xs mt-1" :class="{
                'text-green-600': gusMessageType === 'success',
                'text-red-500': gusMessageType === 'error',
                'text-slate-500': gusMessageType === 'info',
            }">{{ gusMessage }}</p>
        </div>

        <!-- Nazwa -->
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">
                Nazwa <span class="text-red-500">*</span>
                <span v-if="gusFilled" class="text-green-600 ml-1">(z GUS)</span>
            </label>
            <input
                v-model="form.name"
                type="text"
                :placeholder="form.type === 'company' ? 'Nazwa firmy' : 'Imię i nazwisko'"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                :class="{ 'border-red-400': errors.name, 'bg-slate-50': gusFilled }"
                :readonly="gusFilled"
            />
            <p v-if="errors.name" class="text-red-500 text-xs mt-1">{{ errors.name[0] }}</p>
        </div>

        <!-- Kontakt: telefon + email (zawsze edytowalne — GUS ich nie daje) -->
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Telefon</label>
                <input
                    v-model="form.phone"
                    type="tel"
                    placeholder="+48 ..."
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    :class="{ 'border-red-400': errors.phone }"
                />
                <p v-if="errors.phone" class="text-red-500 text-xs mt-1">{{ errors.phone[0] }}</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Email</label>
                <input
                    v-model="form.email"
                    type="email"
                    placeholder="kontakt@..."
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    :class="{ 'border-red-400': errors.email }"
                />
                <p v-if="errors.email" class="text-red-500 text-xs mt-1">{{ errors.email[0] }}</p>
            </div>
        </div>

        <!-- Osoba kontaktowa (opcjonalnie) -->
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Osoba kontaktowa</label>
            <input
                v-model="form.contact_person"
                type="text"
                placeholder="Imię i nazwisko (np. właściciel)"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
            />
        </div>

        <!-- Adres (gdy GUS dał) -->
        <div v-if="gusFilled || form.type === 'person'" class="space-y-2">
            <p v-if="gusFilled" class="text-xs text-slate-500">
                <Icons name="check" class="w-3 h-3 inline" /> Adres z GUS:
            </p>
            <div class="grid grid-cols-3 gap-2">
                <div class="col-span-2">
                    <input
                        v-model="form.street"
                        type="text"
                        placeholder="Ulica"
                        class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm"
                        :readonly="gusFilled"
                    />
                </div>
                <div>
                    <input
                        v-model="form.building_number"
                        type="text"
                        placeholder="Nr"
                        class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm"
                        :readonly="gusFilled"
                    />
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <input
                        v-model="form.postal_code"
                        type="text"
                        placeholder="Kod"
                        class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm"
                        :readonly="gusFilled"
                    />
                </div>
                <div class="col-span-2">
                    <input
                        v-model="form.city"
                        type="text"
                        placeholder="Miasto"
                        class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm"
                        :readonly="gusFilled"
                    />
                </div>
            </div>
        </div>

        <!-- Akcje -->
        <div class="flex gap-2 pt-2">
            <button
                type="button"
                @click="save"
                :disabled="saving || !form.name?.trim()"
                class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
            >
                <Icons v-if="saving" name="sync" class="w-4 h-4 animate-spin" />
                <Icons v-else name="check" class="w-4 h-4" />
                {{ saving ? 'Zapisywanie…' : 'Zapisz klienta' }}
            </button>
            <button
                type="button"
                @click="emit('cancel')"
                :disabled="saving"
                class="px-4 py-2.5 text-slate-600 hover:bg-slate-100 rounded-lg text-sm font-medium transition-colors"
            >
                Anuluj
            </button>
        </div>
    </div>
</template>
