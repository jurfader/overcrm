<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    client: Object,
    types: Object,
    statuses: Object,
    clientStatusOptions: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    profileOptions: Object,
});

const isEditing = !!props.client;
const gusLoading = ref(false);
const gusError = ref('');
const gusSuccess = ref('');

const defaultProfile = {
    venue: { city_size: '', location: '', venue_type: '', venue_size: '', kitchen_staff: '', total_staff: '', years_in_business: '', venue_birthday: '' },
    concept: { specialty: '', cuisine: '', price_level: '' },
    sales: { delivery: false, delivery_volume: '', platforms: [], rush_hours: '' },
    customers: { profiles: [] },
    chicken: { serves_chicken: false, serving_form: '', volume: '' },
    kitchen: { own_production: false, uses_semi_finished: false, suppliers: '' },
    organization: { decision_maker: '', ordering_person: '', ordering_frequency: '' },
    mental: { personality: [], approach_notes: '' },
    potential: { promo_activities: '', media_quality: '', current_products: '', menu_changes: false, open_to_tests: false, notes: '' },
};

function mergeProfile(saved) {
    if (!saved) return JSON.parse(JSON.stringify(defaultProfile));
    const merged = JSON.parse(JSON.stringify(defaultProfile));
    for (const section of Object.keys(merged)) {
        if (saved[section]) {
            for (const key of Object.keys(merged[section])) {
                if (saved[section][key] !== undefined) merged[section][key] = saved[section][key];
            }
        }
    }
    return merged;
}

const form = useForm({
    type: props.client?.type || 'company',
    name: props.client?.name || '',
    short_name: props.client?.short_name || '',
    nip: props.client?.nip || '',
    regon: props.client?.regon || '',
    email: props.client?.email || '',
    phone: props.client?.phone || '',
    phone2: props.client?.phone2 || '',
    website: props.client?.website || '',
    street: props.client?.street || '',
    building_number: props.client?.building_number || '',
    apartment_number: props.client?.apartment_number || '',
    postal_code: props.client?.postal_code || '',
    city: props.client?.city || '',
    country: props.client?.country || 'Polska',
    contact_person: props.client?.contact_person || '',
    contact_email: props.client?.contact_email || '',
    contact_phone: props.client?.contact_phone || '',
    status: props.client?.status || 'active',
    client_status: props.client?.client_status || '',
    assigned_to: props.client?.assigned_to?.toString() || '',
    notes: props.client?.notes || '',
    birthday: props.client?.birthday || '',
    profile: mergeProfile(props.client?.profile),
});

// Accordion state
const openSections = ref(new Set(['basic']));

function toggleSection(id) {
    if (openSections.value.has(id)) {
        openSections.value.delete(id);
    } else {
        openSections.value.add(id);
    }
}

function isSectionOpen(id) {
    return openSections.value.has(id);
}

const profileSections = [
    { id: 'venue', label: 'Lokal', icon: 'building-office', fields: ['venue.city_size', 'venue.location', 'venue.venue_type', 'venue.venue_size', 'venue.kitchen_staff', 'venue.total_staff', 'venue.years_in_business', 'venue.venue_birthday'] },
    { id: 'concept', label: 'Profil lokalu', icon: 'document-text', fields: ['concept.specialty', 'concept.cuisine', 'concept.price_level'] },
    { id: 'sales', label: 'Sprzedaż', icon: 'shopping-cart', fields: ['sales.delivery', 'sales.rush_hours'] },
    { id: 'customers', label: 'Klienci', icon: 'users', fields: ['customers.profiles'] },
    { id: 'chicken', label: 'Kurczak', icon: 'check-circle', fields: ['chicken.serves_chicken'] },
    { id: 'kitchen', label: 'Kuchnia', icon: 'dashboard', fields: ['kitchen.own_production', 'kitchen.uses_semi_finished', 'kitchen.suppliers'] },
    { id: 'organization', label: 'Organizacja', icon: 'settings', fields: ['organization.decision_maker', 'organization.ordering_person', 'organization.ordering_frequency'] },
    { id: 'mental', label: 'Mental i Potencjał', icon: 'user', fields: ['mental.personality', 'potential.menu_changes', 'potential.open_to_tests'] },
];

function sectionFillCount(section) {
    let filled = 0;
    let total = section.fields.length;
    for (const f of section.fields) {
        const [group, key] = f.split('.');
        const val = form.profile[group]?.[key];
        if (Array.isArray(val) ? val.length > 0 : (val !== '' && val !== null && val !== undefined)) filled++;
    }
    return { filled, total };
}

function sectionHasErrors(sectionId) {
    if (!form.errors) return false;
    return Object.keys(form.errors).some(k => k.startsWith(`profile.${sectionId}`));
}

function toggleArrayValue(arr, val) {
    const idx = arr.indexOf(val);
    if (idx >= 0) arr.splice(idx, 1);
    else arr.push(val);
}

async function fetchFromGus() {
    if (!form.nip || form.nip.replace(/[^0-9]/g, '').length < 10) {
        gusError.value = 'Wprowadź poprawny NIP (10 cyfr)';
        gusSuccess.value = '';
        return;
    }
    gusLoading.value = true;
    gusError.value = '';
    gusSuccess.value = '';
    try {
        const response = await fetch(route('clients.lookup-nip', { nip: form.nip }));
        const result = await response.json();
        if (result.success && result.data) {
            const data = result.data;
            if (data.name) form.name = data.name;
            // Zachowaj pełną nazwę z GUS — short_name = name (nie skrót typu "SP. Z O.O.")
            if (data.name) {
                form.short_name = data.name.length <= 100 ? data.name : '';
            }
            if (data.regon) form.regon = data.regon;
            if (data.street) form.street = data.street;
            if (data.building_number) form.building_number = data.building_number;
            if (data.apartment_number) form.apartment_number = data.apartment_number;
            if (data.city) form.city = data.city;
            if (data.postal_code) form.postal_code = data.postal_code;
            if (data.address && !data.street) {
                const p = parseAddress(data.address);
                if (p.street) form.street = p.street;
                if (p.building_number) form.building_number = p.building_number;
                if (p.apartment_number) form.apartment_number = p.apartment_number;
                if (p.postal_code) form.postal_code = p.postal_code;
                if (p.city) form.city = p.city;
            }
            gusSuccess.value = 'Dane zostały pobrane z rejestru GUS';
        } else {
            gusError.value = result.message || 'Nie znaleziono firmy';
        }
    } catch (error) {
        gusError.value = 'Błąd podczas pobierania danych.';
    } finally {
        gusLoading.value = false;
    }
}

function parseAddress(address) {
    const result = { street: '', building_number: '', apartment_number: '', postal_code: '', city: '' };
    const postalMatch = address.match(/(\d{2}-\d{3})/);
    if (postalMatch) result.postal_code = postalMatch[1];
    const cityMatch = address.match(/\d{2}-\d{3}\s+([^,]+)/);
    if (cityMatch) result.city = cityMatch[1].trim();
    const streetMatch = address.match(/^([^,]+)/);
    if (streetMatch) {
        const streetPart = streetMatch[1].replace(/\d{2}-\d{3}.*/, '').trim();
        const buildingMatch = streetPart.match(/(.+?)\s+(\d+[a-zA-Z]?)\s*(?:\/\s*(\d+))?$/);
        if (buildingMatch) {
            result.street = buildingMatch[1].trim();
            result.building_number = buildingMatch[2];
            if (buildingMatch[3]) result.apartment_number = buildingMatch[3];
        } else {
            result.street = streetPart;
        }
    }
    return result;
}

function submit() {
    // Open all profile sections with errors on failed validation
    if (isEditing) {
        form.put(route('clients.update', props.client.id), {
            onError: () => {
                for (const s of profileSections) {
                    if (sectionHasErrors(s.id)) openSections.value.add(s.id);
                }
            },
        });
    } else {
        form.post(route('clients.store'), {
            onError: () => {
                for (const s of profileSections) {
                    if (sectionHasErrors(s.id)) openSections.value.add(s.id);
                }
            },
        });
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edytuj klienta' : 'Nowy klient'" />

    <div class="max-w-4xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ isEditing ? 'Edytuj klienta' : 'Nowy klient' }}</h1>
                <p class="text-gray-600 dark:text-slate-400">{{ isEditing ? 'Zaktualizuj dane klienta' : 'Dodaj nowego klienta do systemu' }}</p>
            </div>
            <Link :href="route('clients.index')">
                <Button variant="secondary">
                    <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                    Powrót
                </Button>
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-4">

            <!-- ============ ACCORDION SECTION COMPONENT ============ -->
            <!-- Basic info -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <button type="button" @click="toggleSection('basic')" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                    <div class="flex items-center gap-3">
                        <Icons name="user" class="w-5 h-5 text-amber-500" />
                        <span class="font-semibold text-slate-800 dark:text-white">Podstawowe informacje</span>
                    </div>
                    <Icons :name="isSectionOpen('basic') ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-slate-400" />
                </button>
                <div v-show="isSectionOpen('basic')" class="px-5 pb-5 border-t border-slate-100 dark:border-slate-700">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Typ *</label>
                            <Select v-model="form.type" :options="types" />
                            <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">{{ form.errors.type }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Status *</label>
                            <Select v-model="form.status" :options="statuses" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Status klienta</label>
                            <Select
                                v-model="form.client_status"
                                :options="Object.fromEntries([['', '—']].concat((clientStatusOptions || []).map(s => [String(s), s])))"
                            />
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-slate-400">np. Stripsiak, Test, Allegro</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Opiekun handlowy</label>
                            <Select
                                v-model="form.assigned_to"
                                :options="Object.fromEntries([['', '— Brak przypisania']].concat((users || []).map(u => [u.id.toString(), u.name])))"
                            />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                                {{ form.type === 'company' ? 'Nazwa firmy' : 'Imię i nazwisko' }} *
                            </label>
                            <Input v-model="form.name" />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Skrócona nazwa</label>
                            <Input v-model="form.short_name" />
                        </div>
                        <div v-if="form.type === 'company'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">NIP</label>
                            <div class="flex gap-2">
                                <Input v-model="form.nip" class="flex-1" placeholder="0000000000" />
                                <Button type="button" variant="secondary" @click="fetchFromGus" :loading="gusLoading" :disabled="!form.nip" class="whitespace-nowrap">
                                    <Icons name="search" class="w-4 h-4 mr-1" />GUS
                                </Button>
                            </div>
                            <p v-if="gusError" class="mt-1 text-sm text-red-600">{{ gusError }}</p>
                            <p v-if="gusSuccess" class="mt-1 text-sm text-green-600">{{ gusSuccess }}</p>
                        </div>
                        <div v-if="form.type === 'company'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">REGON</label>
                            <Input v-model="form.regon" />
                        </div>
                        <div v-if="form.type === 'person'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Data urodzenia</label>
                            <Input v-model="form.birthday" type="date" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <button type="button" @click="toggleSection('contact')" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                    <div class="flex items-center gap-3">
                        <Icons name="phone" class="w-5 h-5 text-amber-500" />
                        <span class="font-semibold text-slate-800 dark:text-white">Dane kontaktowe i adres</span>
                    </div>
                    <Icons :name="isSectionOpen('contact') ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-slate-400" />
                </button>
                <div v-show="isSectionOpen('contact')" class="px-5 pb-5 border-t border-slate-100 dark:border-slate-700">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Email</label>
                            <Input v-model="form.email" type="email" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Telefon</label>
                            <Input v-model="form.phone" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Telefon dodatkowy</label>
                            <Input v-model="form.phone2" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Strona WWW</label>
                            <Input v-model="form.website" placeholder="https://" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5 pt-5 border-t border-slate-100 dark:border-slate-700">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Ulica</label>
                            <Input v-model="form.street" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Nr bud.</label>
                                <Input v-model="form.building_number" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Nr lok.</label>
                                <Input v-model="form.apartment_number" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Kod pocztowy</label>
                            <Input v-model="form.postal_code" placeholder="00-000" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Miasto</label>
                            <Input v-model="form.city" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Kraj</label>
                            <Input v-model="form.country" />
                        </div>
                    </div>
                    <div v-if="form.type === 'company'" class="grid grid-cols-1 md:grid-cols-3 gap-5 mt-5 pt-5 border-t border-slate-100 dark:border-slate-700">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Osoba kontaktowa</label>
                            <Input v-model="form.contact_person" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Email kontaktowy</label>
                            <Input v-model="form.contact_email" type="email" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Telefon kontaktowy</label>
                            <Input v-model="form.contact_phone" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ PROFILE SECTIONS SEPARATOR ============ -->
            <div class="flex items-center gap-3 pt-2">
                <div class="h-px flex-1 bg-amber-300 dark:bg-amber-700"></div>
                <span class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Profil lokalu gastronomicznego</span>
                <div class="h-px flex-1 bg-amber-300 dark:bg-amber-700"></div>
            </div>

            <!-- ============ PROFILE ACCORDION SECTIONS ============ -->

            <div v-for="section in profileSections" :key="section.id"
                class="bg-white dark:bg-slate-800 rounded-xl border overflow-hidden transition-colors"
                :class="sectionHasErrors(section.id) ? 'border-red-300 dark:border-red-700' : 'border-slate-200 dark:border-slate-700'"
            >
                <button type="button" @click="toggleSection(section.id)" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                    <div class="flex items-center gap-3">
                        <Icons :name="section.icon" class="w-5 h-5 text-amber-500" />
                        <span class="font-semibold text-slate-800 dark:text-white">{{ section.label }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded-full"
                            :class="sectionFillCount(section).filled === sectionFillCount(section).total
                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400'"
                        >
                            {{ sectionFillCount(section).filled }}/{{ sectionFillCount(section).total }}
                        </span>
                        <Icons :name="isSectionOpen(section.id) ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-slate-400" />
                    </div>
                </button>

                <div v-show="isSectionOpen(section.id)" class="px-5 pb-5 border-t border-slate-100 dark:border-slate-700">

                    <!-- VENUE -->
                    <div v-if="section.id === 'venue'" class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Wielkość miejscowości</label>
                            <select v-model="form.profile.venue.city_size" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm">
                                <option value="">— wybierz —</option>
                                <option v-for="(label, val) in profileOptions.city_sizes" :key="val" :value="val">{{ label }}</option>
                            </select>
                            <p v-if="form.errors['profile.venue.city_size']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.city_size'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Położenie</label>
                            <select v-model="form.profile.venue.location" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm">
                                <option value="">— wybierz —</option>
                                <option v-for="(label, val) in profileOptions.locations" :key="val" :value="val">{{ label }}</option>
                            </select>
                            <p v-if="form.errors['profile.venue.location']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.location'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Typ lokalu</label>
                            <select v-model="form.profile.venue.venue_type" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm">
                                <option value="">— wybierz —</option>
                                <option v-for="(label, val) in profileOptions.venue_types" :key="val" :value="val">{{ label }}</option>
                            </select>
                            <p v-if="form.errors['profile.venue.venue_type']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.venue_type'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Wielkość lokalu</label>
                            <Input v-model="form.profile.venue.venue_size" placeholder="np. 80m2" />
                            <p v-if="form.errors['profile.venue.venue_size']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.venue_size'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Pracownicy kuchni</label>
                            <Input v-model="form.profile.venue.kitchen_staff" type="number" min="0" />
                            <p v-if="form.errors['profile.venue.kitchen_staff']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.kitchen_staff'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Łącznie pracowników</label>
                            <Input v-model="form.profile.venue.total_staff" type="number" min="0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Lata działania</label>
                            <Input v-model="form.profile.venue.years_in_business" type="number" min="0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Data urodzin lokalu</label>
                            <Input v-model="form.profile.venue.venue_birthday" type="date" />
                            <p v-if="form.errors['profile.venue.venue_birthday']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.venue.venue_birthday'] }}</p>
                        </div>
                    </div>

                    <!-- CONCEPT -->
                    <div v-if="section.id === 'concept'" class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Specjalność (z czego znani)</label>
                            <Input v-model="form.profile.concept.specialty" placeholder="np. burgery, pizza, sushi" />
                            <p v-if="form.errors['profile.concept.specialty']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.concept.specialty'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Kuchnia</label>
                            <Input v-model="form.profile.concept.cuisine" placeholder="np. amerykańska, polska, azjatycka" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Poziom cen</label>
                            <select v-model="form.profile.concept.price_level" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm">
                                <option value="">— wybierz —</option>
                                <option v-for="(label, val) in profileOptions.price_levels" :key="val" :value="val">{{ label }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- SALES -->
                    <div v-if="section.id === 'sales'" class="space-y-5 pt-4">
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.profile.sales.delivery" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                                <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Dowozy</span>
                            </label>
                            <div v-if="form.profile.sales.delivery" class="flex-1 max-w-xs">
                                <Input v-model="form.profile.sales.delivery_volume" placeholder="np. 50 dziennie" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Platformy</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="(label, val) in profileOptions.platforms" :key="val"
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border cursor-pointer text-sm transition"
                                    :class="form.profile.sales.platforms.includes(val) ? 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-600' : 'border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:border-slate-300'"
                                >
                                    <input type="checkbox" :value="val" class="hidden" @change="toggleArrayValue(form.profile.sales.platforms, val)" :checked="form.profile.sales.platforms.includes(val)" />
                                    {{ label }}
                                </label>
                            </div>
                        </div>
                        <div class="max-w-md">
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Godziny ruchu</label>
                            <Input v-model="form.profile.sales.rush_hours" placeholder="np. 11-14, 18-21" />
                        </div>
                    </div>

                    <!-- CUSTOMERS -->
                    <div v-if="section.id === 'customers'" class="pt-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Profil klientów</label>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="(label, val) in profileOptions.customer_profiles" :key="val"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border cursor-pointer text-sm transition"
                                :class="form.profile.customers.profiles.includes(val) ? 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-600' : 'border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:border-slate-300'"
                            >
                                <input type="checkbox" :value="val" class="hidden" @change="toggleArrayValue(form.profile.customers.profiles, val)" :checked="form.profile.customers.profiles.includes(val)" />
                                {{ label }}
                            </label>
                        </div>
                        <p v-if="form.errors['profile.customers.profiles']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.customers.profiles'] }}</p>
                    </div>

                    <!-- CHICKEN -->
                    <div v-if="section.id === 'chicken'" class="space-y-5 pt-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="form.profile.chicken.serves_chicken" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                            <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Serwuje kurczaka</span>
                        </label>
                        <div v-if="form.profile.chicken.serves_chicken" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Forma podawania</label>
                                <Input v-model="form.profile.chicken.serving_form" placeholder="np. stripsy, burgery, panierowany" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Ilości sprzedawane</label>
                                <Input v-model="form.profile.chicken.volume" placeholder="np. 30kg/tydzień" />
                            </div>
                        </div>
                    </div>

                    <!-- KITCHEN -->
                    <div v-if="section.id === 'kitchen'" class="space-y-5 pt-4">
                        <div class="flex flex-wrap gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.profile.kitchen.own_production" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                                <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Własna produkcja</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.profile.kitchen.uses_semi_finished" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                                <span class="text-sm font-medium text-gray-700 dark:text-slate-300">Używa półproduktów</span>
                            </label>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Kto dostarcza (dostawcy)</label>
                            <Input v-model="form.profile.kitchen.suppliers" placeholder="np. Hurtownia X, Dystrybutor Y" />
                            <p v-if="form.errors['profile.kitchen.suppliers']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.kitchen.suppliers'] }}</p>
                        </div>
                    </div>

                    <!-- ORGANIZATION -->
                    <div v-if="section.id === 'organization'" class="grid grid-cols-1 md:grid-cols-3 gap-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Kto decyduje</label>
                            <select v-model="form.profile.organization.decision_maker" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm">
                                <option value="">— wybierz —</option>
                                <option v-for="(label, val) in profileOptions.decision_makers" :key="val" :value="val">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Osoba zamawiająca</label>
                            <Input v-model="form.profile.organization.ordering_person" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Częstotliwość zamawiania</label>
                            <Input v-model="form.profile.organization.ordering_frequency" placeholder="np. 2x/tydzień" />
                        </div>
                    </div>

                    <!-- MENTAL + POTENTIAL -->
                    <div v-if="section.id === 'mental'" class="space-y-5 pt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Cechy właściciela/zamawiającego</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="(label, val) in profileOptions.personalities" :key="val"
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border cursor-pointer text-sm transition"
                                    :class="form.profile.mental.personality.includes(val) ? 'border-amber-400 bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-600' : 'border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:border-slate-300'"
                                >
                                    <input type="checkbox" :value="val" class="hidden" @change="toggleArrayValue(form.profile.mental.personality, val)" :checked="form.profile.mental.personality.includes(val)" />
                                    {{ label }}
                                </label>
                            </div>
                            <p v-if="form.errors['profile.mental.personality']" class="mt-1 text-sm text-red-600">{{ form.errors['profile.mental.personality'] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notatki — co na niego działa, jak rozmawiać</label>
                            <Textarea v-model="form.profile.mental.approach_notes" :rows="2" placeholder="np. Lubi dane i liczby, nie trać czasu na small talk" />
                        </div>
                        <div class="border-t border-slate-100 dark:border-slate-700 pt-5 mt-2">
                            <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Potencjał</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Działania promocyjne</label>
                                    <Input v-model="form.profile.potential.promo_activities" placeholder="np. Instagram, lokalne eventy" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Media (jak wyglądają)</label>
                                    <Input v-model="form.profile.potential.media_quality" placeholder="np. dobry Instagram, brak strony" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Co od nas kupują</label>
                                    <Input v-model="form.profile.potential.current_products" placeholder="np. stripsy 3kg, panierka" />
                                </div>
                                <div class="flex flex-wrap gap-6 items-center">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.profile.potential.menu_changes" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                                        <span class="text-sm text-gray-700 dark:text-slate-300">Zmiany menu</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.profile.potential.open_to_tests" class="rounded border-slate-300 text-amber-500 focus:ring-amber-500 dark:border-slate-600 dark:bg-slate-700" />
                                        <span class="text-sm text-gray-700 dark:text-slate-300">Otwartość na testy</span>
                                    </label>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Dodatkowe notatki o potencjale</label>
                                <Textarea v-model="form.profile.potential.notes" :rows="2" />
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Notes -->
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <button type="button" @click="toggleSection('notes')" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                    <div class="flex items-center gap-3">
                        <Icons name="edit" class="w-5 h-5 text-amber-500" />
                        <span class="font-semibold text-slate-800 dark:text-white">Notatki</span>
                    </div>
                    <Icons :name="isSectionOpen('notes') ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-slate-400" />
                </button>
                <div v-show="isSectionOpen('notes')" class="px-5 pb-5 border-t border-slate-100 dark:border-slate-700">
                    <div class="pt-4">
                        <Textarea v-model="form.notes" :rows="4" placeholder="Dodatkowe informacje o kliencie..." />
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <Link :href="route('clients.index')">
                    <Button variant="secondary" type="button">Anuluj</Button>
                </Link>
                <Button :loading="form.processing">
                    {{ isEditing ? 'Zapisz zmiany' : 'Dodaj klienta' }}
                </Button>
            </div>
        </form>
    </div>
</template>
