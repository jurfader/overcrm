<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';
import Icons from '@/Components/Icons.vue';
import ClickToCall from '@/Components/ClickToCall.vue';

const props = defineProps({
    client: Object,
    clientVisits: { type: Array, default: () => [] },
    assignedHandlowcy: { type: Array, default: () => [] },
    sentEmails: { type: Array, default: () => [] },
    summaries: { type: Array, default: () => [] },
});

const expandedEmailId = ref(null);

function toggleEmail(id) {
    expandedEmailId.value = expandedEmailId.value === id ? null : id;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatVisitTime(t) {
    if (!t) return '';
    const s = String(t);
    if (/^\d{1,2}:\d{2}/.test(s)) return s.slice(0, 5);
    if (s.length >= 16) return s.slice(11, 16);
    return s;
}

const statusColors = { active: 'green', inactive: 'gray', potential: 'yellow' };
const priorityColors = { low: 'gray', medium: 'blue', high: 'yellow', urgent: 'red' };

const p = props.client.profile || {};

const labels = {
    city_sizes: { 'małe': 'Małe (do 20 tys.)', 'średnie': 'Średnie (20-100 tys.)', 'duże': 'Duże (100 tys.+)' },
    locations: { centrum: 'Centrum', osiedle: 'Osiedle', przy_drodze: 'Przy drodze', galeria: 'Galeria handlowa', dworzec: 'Dworzec/lotnisko', inne: 'Inne' },
    venue_types: { stacjonarny: 'Stacjonarny', kontener: 'Kontener', food_truck: 'Food truck', przyczepa: 'Przyczepa', wyspa: 'Wyspa (galeria)', inne: 'Inne' },
    price_levels: { niski: 'Niski', 'średni': 'Średni', wysoki: 'Wysoki', premium: 'Premium' },
    platforms: { pyszne: 'Pyszne.pl', uber_eats: 'Uber Eats', glovo: 'Glovo', wolt: 'Wolt', bolt_food: 'Bolt Food', inne: 'Inne' },
    customer_profiles: { turysci: 'Turyści', mlodziez: 'Młodzież', studenci: 'Studenci', rodziny: 'Rodziny', pracownicy: 'Pracownicy', imprezy: 'Imprezy', koncerty: 'Koncerty', nocni: 'Nocni klienci' },
    decision_makers: { wlasciciel: 'Właściciel', menedzer: 'Menedżer', kucharz: 'Szef kuchni', inny: 'Inna osoba' },
    personalities: { szybki: 'Szybki', spokojny: 'Spokojny', lubi_mowic: 'Lubi mówić', konkretny: 'Konkretny', analityczny: 'Analityczny', emocjonalny: 'Emocjonalny', negocjator: 'Negocjator' },
};

function label(map, val) { return labels[map]?.[val] || val; }
function labelList(map, arr) { return (arr || []).map(v => label(map, v)).join(', '); }

function hasData(obj) {
    if (!obj || typeof obj !== 'object') return false;
    return Object.values(obj).some(v => {
        if (Array.isArray(v)) return v.length > 0;
        if (typeof v === 'boolean') return true;
        return v !== '' && v !== null && v !== undefined && v !== 0;
    });
}

const hasProfile = computed(() => {
    if (!p || typeof p !== 'object') return false;
    return Object.values(p).some(section => hasData(section));
});
</script>

<template>
    <Head :title="client.name" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-2xl font-bold">
                    {{ (client.short_name || client.name).substring(0, 2).toUpperCase() }}
                </div>
                <div class="ml-4">
                    <h1 class="text-2xl font-bold text-foreground">{{ client.name }}</h1>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        <!-- Status klienta – wyraźnie widoczny -->
                        <Badge :color="client.status_color || statusColors[client.status]" size="lg">
                            {{ client.client_status || (client.status === 'active' ? 'Aktywny' : client.status === 'inactive' ? 'Nieaktywny' : 'Potencjalny') }}
                        </Badge>
                        <span class="text-foreground-muted">{{ client.type === 'company' ? 'Firma' : 'Osoba prywatna' }}</span>
                    </div>
                    <!-- Opiekun handlowy – wyraźnie widoczny -->
                    <div v-if="assignedHandlowcy.length" class="mt-2 flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800/50 w-fit">
                        <Icons name="user" class="w-4 h-4 text-brand-primary flex-shrink-0" />
                        <span class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Opiekun handlowy:</span>
                        <template v-for="(h, i) in assignedHandlowcy" :key="h.id">
                            <span v-if="i > 0" class="text-indigo-500 dark:text-indigo-400">, </span>
                            <Link :href="route('users.show', h.id)" class="text-brand-primary hover:underline font-medium">{{ h.name }}</Link>
                        </template>
                    </div>
                    <div v-else class="mt-2 flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-gray-50 dark:bg-slate-800/50 border border-border w-fit">
                        <Icons name="user" class="w-4 h-4 text-gray-400 dark:text-slate-500 flex-shrink-0" />
                        <span class="text-sm text-foreground-muted">Brak przypisanego opiekuna</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <Link :href="route('clients.index')">
                    <Button variant="secondary">
                        <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                        Powrót
                    </Button>
                </Link>
                <Link :href="route('clients.edit', client.id)">
                    <Button>
                        <Icons name="edit" class="w-5 h-5 mr-2" />
                        Edytuj
                    </Button>
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Dane podstawowe -->
            <div class="lg:col-span-2 space-y-6">
                <Card title="Dane podstawowe">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div v-if="client.nip">
                            <dt class="text-sm font-medium text-gray-500">NIP</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.nip }}</dd>
                        </div>
                        <div v-if="client.regon">
                            <dt class="text-sm font-medium text-gray-500">REGON</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.regon }}</dd>
                        </div>
                        <div v-if="client.email">
                            <dt class="text-sm font-medium text-gray-500">Email</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <a :href="'mailto:' + client.email" class="text-indigo-600 hover:text-indigo-800">{{ client.email }}</a>
                            </dd>
                        </div>
                        <div v-if="client.phone">
                            <dt class="text-sm font-medium text-gray-500">Telefon</dt>
                            <dd class="mt-1 text-sm text-gray-900 flex items-center gap-1">
                                <a :href="'tel:' + client.phone" class="text-indigo-600 hover:text-indigo-800">{{ client.phone }}</a>
                                <ClickToCall :phone="client.phone" size="sm" />
                            </dd>
                        </div>
                        <div v-if="client.website">
                            <dt class="text-sm font-medium text-gray-500">Strona WWW</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <a :href="client.website" target="_blank" class="text-indigo-600 hover:text-indigo-800">{{ client.website }}</a>
                            </dd>
                        </div>
                        <div v-if="client.birthday">
                            <dt class="text-sm font-medium text-gray-500">Data urodzenia</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ new Date(client.birthday).toLocaleDateString('pl-PL') }}</dd>
                        </div>
                    </dl>
                </Card>

                <!-- Adres -->
                <Card v-if="client.street || client.city" title="Adres">
                    <address class="not-italic text-sm text-gray-900">
                        <p v-if="client.street">
                            {{ client.street }} {{ client.building_number }}<span v-if="client.apartment_number">/{{ client.apartment_number }}</span>
                        </p>
                        <p v-if="client.postal_code || client.city">
                            {{ client.postal_code }} {{ client.city }}
                        </p>
                        <p v-if="client.country && client.country !== 'Polska'">{{ client.country }}</p>
                    </address>
                </Card>

                <!-- Osoba kontaktowa -->
                <Card v-if="client.contact_person" title="Osoba kontaktowa">
                    <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Imię i nazwisko</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.contact_person }}</dd>
                        </div>
                        <div v-if="client.contact_email">
                            <dt class="text-sm font-medium text-gray-500">Email</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <a :href="'mailto:' + client.contact_email" class="text-indigo-600 hover:text-indigo-800">{{ client.contact_email }}</a>
                            </dd>
                        </div>
                        <div v-if="client.contact_phone">
                            <dt class="text-sm font-medium text-gray-500">Telefon</dt>
                            <dd class="mt-1 text-sm text-gray-900 flex items-center gap-1">
                                {{ client.contact_phone }}
                                <ClickToCall :phone="client.contact_phone" size="sm" />
                            </dd>
                        </div>
                    </dl>
                </Card>

                <!-- Notatki (w tym opisy z spotkań) -->
                <Card v-if="client.notes || (clientVisits && clientVisits.some(v => v.description || v.notes))" title="Notatki">
                    <div class="space-y-4">
                        <p v-if="client.notes" class="text-sm text-foreground whitespace-pre-wrap">{{ client.notes }}</p>
                        <template v-if="clientVisits && clientVisits.some(v => v.description || v.notes)">
                            <div v-if="client.notes" class="border-t border-gray-200 dark:border-slate-600 pt-4"></div>
                            <div>
                                <p class="text-xs font-medium text-foreground-muted mb-2">Z ostatnich spotkań:</p>
                                <div class="space-y-3">
                                    <div v-for="v in clientVisits.filter(x => x.description || x.notes)" :key="v.id" class="text-sm text-foreground">
                                        <span class="text-xs text-foreground-muted">{{ new Date(v.visit_date).toLocaleDateString('pl-PL') }} — </span>
                                        <div class="prose prose-sm max-w-none dark:prose-invert prose-p:my-1 prose-p:first:mt-0" v-html="(v.description || '') + (v.notes ? (v.description ? '<br>' : '') + v.notes : '')"></div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </Card>

                <!-- Podsumowania AI -->
                <Card title="Podsumowania AI">
                    <div v-if="!summaries || summaries.length === 0" class="text-center py-8 text-foreground-muted">
                        <Icons name="sparkles" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                        <p class="text-sm">Brak zapisanych podsumowań</p>
                        <p class="text-xs mt-1">Generuj podsumowania w kalendarzu — otwórz wizytę, zakładka „Karta klienta”, przycisk „Generuj podsumowanie AI”</p>
                    </div>
                    <div v-else class="space-y-4">
                        <div
                            v-for="s in summaries"
                            :key="s.id"
                            class="p-4 rounded-lg border border-border bg-slate-50/50 dark:bg-slate-800/30"
                        >
                            <p class="text-sm text-foreground whitespace-pre-wrap">{{ s.summary }}</p>
                            <p class="mt-2 text-xs text-foreground-muted">
                                Wygenerowano: {{ formatDate(s.generated_at) }}
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- Historia wysłanych maili -->
                <Card title="Historia wysłanych maili">
                    <div v-if="sentEmails.length === 0" class="text-center py-8 text-foreground-muted">
                        <Icons name="mail" class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-slate-600" />
                        <p class="text-sm">Brak wysłanych wiadomości do tego klienta</p>
                        <p class="text-xs mt-1">Emaile wysłane z kalendarza (oferty, szablony) pojawią się tutaj</p>
                    </div>
                    <div v-else class="space-y-2">
                        <div v-for="email in sentEmails" :key="email.id">
                            <button
                                @click="toggleEmail(email.id)"
                                class="w-full text-left p-3 rounded-lg border transition-colors"
                                :class="expandedEmailId === email.id
                                    ? 'border-indigo-300 bg-indigo-50 dark:border-indigo-700 dark:bg-indigo-900/20'
                                    : 'border-gray-200 hover:border-gray-300 dark:border-slate-700 dark:hover:border-slate-600'"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span
                                            class="inline-block w-2 h-2 rounded-full flex-shrink-0"
                                            :class="{
                                                'bg-green-500': email.status === 'sent',
                                                'bg-red-500': email.status === 'failed',
                                                'bg-yellow-500': email.status === 'pending',
                                            }"
                                        ></span>
                                        <span class="text-sm font-medium text-foreground truncate">{{ email.subject }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                                        <Badge
                                            :color="email.status === 'sent' ? 'green' : email.status === 'failed' ? 'red' : 'yellow'"
                                            size="sm"
                                        >
                                            {{ email.status === 'sent' ? 'Wysłano' : email.status === 'failed' ? 'Błąd' : 'Oczekuje' }}
                                        </Badge>
                                        <Icons :name="expandedEmailId === email.id ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-gray-400" />
                                    </div>
                                </div>
                                <div class="mt-1 flex items-center gap-3 text-xs text-foreground-muted">
                                    <span>{{ formatDate(email.sent_at || email.created_at) }}</span>
                                    <span v-if="email.user">{{ email.user.name }}</span>
                                    <span v-if="email.template" class="text-indigo-500 dark:text-indigo-400">{{ email.template.name }}</span>
                                </div>
                            </button>
                            <div
                                v-if="expandedEmailId === email.id"
                                class="mt-1 mx-1 p-4 border border-border rounded-lg surface"
                            >
                                <div class="text-xs text-foreground-muted mb-3 space-y-1">
                                    <div><span class="font-medium">Do:</span> {{ email.to_email }}</div>
                                    <div><span class="font-medium">Temat:</span> {{ email.subject }}</div>
                                    <div v-if="email.error_message" class="text-red-600 dark:text-red-400"><span class="font-medium">Błąd:</span> {{ email.error_message }}</div>
                                </div>
                                <div class="border-t dark:border-slate-700 pt-3">
                                    <div class="prose prose-sm max-w-none dark:prose-invert text-sm" v-html="email.html_content"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Profil lokalu -->
                <template v-if="hasProfile">
                    <div class="flex items-center gap-3 pt-2">
                        <div class="h-px flex-1 bg-amber-300 dark:bg-amber-700"></div>
                        <span class="text-xs font-bold uppercase tracking-wider text-brand-primary">Profil lokalu gastronomicznego</span>
                        <div class="h-px flex-1 bg-amber-300 dark:bg-amber-700"></div>
                    </div>

                    <Card v-if="hasData(p.venue)" title="Lokal">
                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div v-if="p.venue.city_size"><dt class="text-foreground-muted">Miejscowość</dt><dd class="mt-0.5 text-foreground font-medium">{{ label('city_sizes', p.venue.city_size) }}</dd></div>
                            <div v-if="p.venue.location"><dt class="text-foreground-muted">Położenie</dt><dd class="mt-0.5 text-foreground font-medium">{{ label('locations', p.venue.location) }}</dd></div>
                            <div v-if="p.venue.venue_type"><dt class="text-foreground-muted">Typ</dt><dd class="mt-0.5 text-foreground font-medium">{{ label('venue_types', p.venue.venue_type) }}</dd></div>
                            <div v-if="p.venue.venue_size"><dt class="text-foreground-muted">Wielkość</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.venue.venue_size }}</dd></div>
                            <div v-if="p.venue.kitchen_staff"><dt class="text-foreground-muted">Pracownicy kuchni</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.venue.kitchen_staff }}</dd></div>
                            <div v-if="p.venue.total_staff"><dt class="text-foreground-muted">Łącznie pracowników</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.venue.total_staff }}</dd></div>
                            <div v-if="p.venue.years_in_business"><dt class="text-foreground-muted">Lata działania</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.venue.years_in_business }}</dd></div>
                            <div v-if="p.venue.venue_birthday"><dt class="text-foreground-muted">Data urodzin lokalu</dt><dd class="mt-0.5 text-foreground font-medium">{{ new Date(p.venue.venue_birthday).toLocaleDateString('pl-PL') }}</dd></div>
                        </dl>
                    </Card>

                    <Card v-if="hasData(p.concept)" title="Koncept">
                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div v-if="p.concept.specialty"><dt class="text-foreground-muted">Specjalność</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.concept.specialty }}</dd></div>
                            <div v-if="p.concept.cuisine"><dt class="text-foreground-muted">Kuchnia</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.concept.cuisine }}</dd></div>
                            <div v-if="p.concept.price_level"><dt class="text-foreground-muted">Poziom cen</dt><dd class="mt-0.5 text-foreground font-medium">{{ label('price_levels', p.concept.price_level) }}</dd></div>
                        </dl>
                    </Card>

                    <Card v-if="hasData(p.sales)" title="Sprzedaż">
                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div><dt class="text-foreground-muted">Dowozy</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.sales.delivery ? 'Tak' + (p.sales.delivery_volume ? ' — ' + p.sales.delivery_volume : '') : 'Nie' }}</dd></div>
                            <div v-if="p.sales.platforms?.length"><dt class="text-foreground-muted">Platformy</dt><dd class="mt-0.5 text-foreground font-medium">{{ labelList('platforms', p.sales.platforms) }}</dd></div>
                            <div v-if="p.sales.rush_hours"><dt class="text-foreground-muted">Godziny ruchu</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.sales.rush_hours }}</dd></div>
                        </dl>
                    </Card>

                    <Card v-if="p.customers?.profiles?.length" title="Profil klientów">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="cp in p.customers.profiles" :key="cp" class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 text-xs font-medium">{{ label('customer_profiles', cp) }}</span>
                        </div>
                    </Card>

                    <Card v-if="hasData(p.kitchen)" title="Kuchnia">
                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div><dt class="text-foreground-muted">Własna produkcja</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.kitchen.own_production ? 'Tak' : 'Nie' }}</dd></div>
                            <div><dt class="text-foreground-muted">Półprodukty</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.kitchen.uses_semi_finished ? 'Tak' : 'Nie' }}</dd></div>
                            <div v-if="p.kitchen.suppliers"><dt class="text-foreground-muted">Dostawcy</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.kitchen.suppliers }}</dd></div>
                        </dl>
                    </Card>

                    <Card v-if="hasData(p.organization)" title="Organizacja">
                        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                            <div v-if="p.organization.decision_maker"><dt class="text-foreground-muted">Kto decyduje</dt><dd class="mt-0.5 text-foreground font-medium">{{ label('decision_makers', p.organization.decision_maker) }}</dd></div>
                            <div v-if="p.organization.ordering_person"><dt class="text-foreground-muted">Osoba zamawiająca</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.organization.ordering_person }}</dd></div>
                            <div v-if="p.organization.ordering_frequency"><dt class="text-foreground-muted">Częstotliwość</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.organization.ordering_frequency }}</dd></div>
                        </dl>
                    </Card>

                    <Card v-if="hasData(p.mental)" title="Cechy i podejście">
                        <div v-if="p.mental.personality?.length" class="flex flex-wrap gap-2 mb-3">
                            <span v-for="t in p.mental.personality" :key="t" class="px-2.5 py-1 rounded-full bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 text-xs font-medium">{{ label('personalities', t) }}</span>
                        </div>
                        <p v-if="p.mental.approach_notes" class="text-sm text-foreground italic">{{ p.mental.approach_notes }}</p>
                    </Card>

                    <Card v-if="hasData(p.potential)" title="Potencjał">
                        <dl class="grid grid-cols-2 gap-4 text-sm">
                            <div v-if="p.potential.promo_activities"><dt class="text-foreground-muted">Działania promo</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.potential.promo_activities }}</dd></div>
                            <div v-if="p.potential.media_quality"><dt class="text-foreground-muted">Media</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.potential.media_quality }}</dd></div>
                            <div v-if="p.potential.current_products"><dt class="text-foreground-muted">Co od nas kupują</dt><dd class="mt-0.5 text-foreground font-medium">{{ p.potential.current_products }}</dd></div>
                            <div v-if="p.potential.menu_changes || p.potential.open_to_tests">
                                <dt class="text-foreground-muted">Otwartość</dt>
                                <dd class="mt-0.5 flex gap-2">
                                    <span v-if="p.potential.menu_changes" class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 text-xs font-medium">Zmiany menu</span>
                                    <span v-if="p.potential.open_to_tests" class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 text-xs font-medium">Testy</span>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="p.potential.notes" class="text-sm text-foreground mt-3 italic">{{ p.potential.notes }}</p>
                    </Card>
                </template>
            </div>

            <!-- Panel boczny -->
            <div class="space-y-6">
                <!-- Szybkie akcje -->
                <Card title="Szybkie akcje">
                    <div class="space-y-3">
                        <Link :href="route('tasks.create', { client_id: client.id })" class="flex items-center p-3 rounded-lg border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50 transition-colors">
                            <Icons name="plus" class="w-5 h-5 text-indigo-600 mr-3" />
                            <span class="text-sm font-medium text-gray-900">Nowe zadanie</span>
                        </Link>
                        <Link :href="route('clients.edit', client.id)" class="flex items-center p-3 rounded-lg border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50 transition-colors">
                            <Icons name="edit" class="w-5 h-5 text-indigo-600 mr-3" />
                            <span class="text-sm font-medium text-gray-900">Edytuj dane</span>
                        </Link>
                    </div>
                </Card>

                <!-- Spotkania w kalendarzu — wyróżniony widget -->
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20 rounded-xl shadow-md border-2 border-amber-300 dark:border-amber-700 overflow-hidden ring-1 ring-amber-200 dark:ring-amber-800">
                    <div class="px-6 py-4 bg-gradient-to-r from-amber-500 to-orange-500 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Icons name="calendar" class="w-5 h-5 text-white" />
                            <h3 class="text-lg font-bold text-white">Spotkania w kalendarzu</h3>
                            <span v-if="clientVisits && clientVisits.length > 0" class="ml-1 px-2 py-0.5 rounded-full bg-white/20 text-white text-xs font-semibold">
                                {{ clientVisits.length }}
                            </span>
                        </div>
                        <Link :href="route('calendar.index')" class="text-white/90 hover:text-white text-xs font-medium flex items-center gap-1">
                            Kalendarz →
                        </Link>
                    </div>
                    <div class="p-5">
                        <div v-if="!clientVisits || clientVisits.length === 0" class="text-center py-4 text-foreground-muted">
                            <p class="text-sm">Brak spotkań z tym klientem</p>
                            <p class="text-xs mt-1">Spotkania z kalendarza pojawią się tutaj</p>
                        </div>
                        <ul v-else class="space-y-2">
                            <li v-for="visit in clientVisits" :key="visit.id">
                                <Link
                                    :href="route('calendar.index') + '?openVisit=' + visit.id"
                                    class="block p-3 rounded-lg surface border border-amber-200 dark:border-slate-700 hover:border-amber-400 dark:hover:border-amber-500 hover:shadow-md transition-all"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-foreground truncate">{{ visit.title || 'Spotkanie' }}</span>
                                        <span class="text-xs font-medium text-amber-700 dark:text-amber-400 shrink-0 flex items-center gap-1">
                                            <span v-if="formatVisitTime(visit.visit_time)" class="font-bold">{{ formatVisitTime(visit.visit_time) }}</span>
                                            <span>{{ new Date(visit.visit_date).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short', year: 'numeric' }) }}</span>
                                        </span>
                                    </div>
                                    <div
                                        v-if="visit.description || visit.notes"
                                        class="mt-1.5 text-xs text-slate-600 dark:text-slate-300 line-clamp-2 prose prose-sm max-w-none dark:prose-invert prose-p:my-0.5 prose-p:first:mt-0"
                                        v-html="(visit.description || '') + (visit.notes ? (visit.description ? '<br>' : '') + visit.notes : '')"
                                    ></div>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Informacje -->
                <Card title="Informacje">
                    <dl class="space-y-3 text-sm">
                        <div v-if="assignedHandlowcy.length" class="flex justify-between items-start gap-2">
                            <dt class="text-gray-500 shrink-0">Opiekun handlowy</dt>
                            <dd class="text-gray-900 text-right">
                                <template v-for="(h, i) in assignedHandlowcy" :key="h.id">
                                    <span v-if="i > 0" class="text-gray-400">, </span>
                                    <Link :href="route('users.show', h.id)" class="text-indigo-600 hover:text-indigo-800 hover:underline">{{ h.name }}</Link>
                                </template>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Utworzono</dt>
                            <dd class="text-gray-900">{{ new Date(client.created_at).toLocaleDateString('pl-PL') }}</dd>
                        </div>
                        <div v-if="client.creator" class="flex justify-between">
                            <dt class="text-gray-500">Przez</dt>
                            <dd class="text-gray-900">{{ client.creator.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Aktualizacja</dt>
                            <dd class="text-gray-900">{{ new Date(client.updated_at).toLocaleDateString('pl-PL') }}</dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </div>
</template>
