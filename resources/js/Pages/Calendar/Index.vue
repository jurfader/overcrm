<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import Icons from '@/Components/Icons.vue';
import Select from '@/Components/Select.vue';
import ClientModal from './ClientModal.vue';
import ClientQuickForm from '@/Components/ClientQuickForm.vue';

const page = usePage();

const props = defineProps({
    year: Number,
    month: Number,
    view: String,
    visits: Object,
    trashed: Boolean,
    openedVisitId: [Number, null],
    clients: Array,
    users: Array,
    emailTemplates: Array,
    mailConfigs: Array,
    priceLists: Array,
    statuses: Array,
    startDate: String,
    endDate: String,
    profileOptions: Object,
    selectedUserId: [Number, String, null],
    showAll: Boolean,
    canSelectUser: Boolean,
    managedCalendarIds: { type: Array, default: () => [] },
    isAdminCalendar: { type: Boolean, default: false },
});

const showAddModal = ref(false);
const showClientModal = ref(false);
const showNewClientForm = ref(false);
const selectedVisit = ref(null);
const selectedDate = ref(null);
const isDragging = ref(false);
const CALENDAR_VIEW_KEY = 'calendar-view';
const currentView = ref(props.view || (typeof localStorage !== 'undefined' ? localStorage.getItem(CALENDAR_VIEW_KEY) : null) || 'month');
const selectedWeekStart = ref(null); // Data początku wybranego tygodnia
const selectedDay = ref(null); // Wybrany dzień
const localClients = ref([...props.clients]); // Lokalna kopia klientów

// Wyszukiwarka w kalendarzu
const calendarSearchQuery = ref('');
const calendarSearchResults = ref({ visits: [], clients: [] });
const calendarSearchLoading = ref(false);
const calendarSearchOpen = ref(false);
const calendarSearchSelectedIndex = ref(0);
const calendarSearchRef = ref(null);
let calendarSearchDebounce = null;

async function fetchCalendarSearch() {
    const q = calendarSearchQuery.value.trim();
    if (q.length < 2) {
        calendarSearchResults.value = { visits: [], clients: [] };
        return;
    }
    calendarSearchLoading.value = true;
    try {
        const params = new URLSearchParams({ q, limit: 15, clients: 1 });
        if (props.canSelectUser && userFilter.value) {
            params.set('user_id', userFilter.value);
        }
        const r = await fetch(route('calendar.visits-search') + '?' + params, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await r.json();
        calendarSearchResults.value = {
            visits: data.visits || [],
            clients: data.clients || [],
        };
        calendarSearchSelectedIndex.value = 0;
    } catch {
        calendarSearchResults.value = { visits: [], clients: [] };
    } finally {
        calendarSearchLoading.value = false;
    }
}

function onCalendarSearchInput() {
    clearTimeout(calendarSearchDebounce);
    calendarSearchDebounce = setTimeout(fetchCalendarSearch, 200);
}

const calendarSearchFlat = computed(() => {
    const { visits, clients } = calendarSearchResults.value;
    return [
        ...visits.map(v => ({ type: 'visit', item: v })),
        ...clients.map(c => ({ type: 'client', item: c })),
    ];
});

function selectCalendarSearchResult(entry) {
    if (!entry) return;
    calendarSearchOpen.value = false;
    calendarSearchQuery.value = '';
    calendarSearchResults.value = { visits: [], clients: [] };
    if (entry.type === 'visit') {
        const v = entry.item;
        const raw = v.visit_date;
        const dateStr = (typeof raw === 'string' ? raw : String(raw || '')).split('T')[0];
        const [y, m] = dateStr.split('-').map(Number);
        selectedDay.value = dateStr;
        selectedWeekStart.value = getWeekStart(parseDateLocal(dateStr));
        if (m && (m !== props.month || y !== props.year)) {
            router.get(route('calendar.index'), calendarParams({ year: y, month: m }), {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => nextTick(() => openClientModal(v)),
            });
        } else {
            openClientModal(v);
        }
    } else {
        router.visit(route('clients.show', entry.item.id));
    }
}

function handleCalendarSearchKeydown(e) {
    if (!calendarSearchOpen.value) return;
    const flat = calendarSearchFlat.value;
    if (e.key === 'Escape') {
        calendarSearchOpen.value = false;
        e.preventDefault();
        return;
    }
    if (e.key === 'ArrowDown') {
        calendarSearchSelectedIndex.value = Math.min(calendarSearchSelectedIndex.value + 1, flat.length - 1);
        e.preventDefault();
        return;
    }
    if (e.key === 'ArrowUp') {
        calendarSearchSelectedIndex.value = Math.max(calendarSearchSelectedIndex.value - 1, 0);
        e.preventDefault();
        return;
    }
    if (e.key === 'Enter' && flat[calendarSearchSelectedIndex.value]) {
        selectCalendarSearchResult(flat[calendarSearchSelectedIndex.value]);
        e.preventDefault();
    }
}

function handleCalendarSearchClickOutside(e) {
    if (calendarSearchRef.value && !calendarSearchRef.value.contains(e.target)) {
        calendarSearchOpen.value = false;
    }
}

watch(calendarSearchOpen, (open) => {
    if (open) {
        nextTick(() => document.addEventListener('click', handleCalendarSearchClickOutside));
    } else {
        document.removeEventListener('click', handleCalendarSearchClickOutside);
    }
});

// Filtr użytkownika (tylko dla admina): '' = mój, 'all' = wszystkie, number = konkretny user
const userFilter = ref(
    props.showAll ? 'all' : (props.selectedUserId ?? '')
);

// Opcje do dropdown wyboru kalendarza.
// Admin: 'Mój' + 'Wszystkie' + pełna lista userów.
// Manager (calendar_managers): 'Mój' + tylko zarządzane kalendarze.
const userOptions = computed(() => {
    const base = [{ id: '', name: 'Mój kalendarz', value: '' }];
    if (props.isAdminCalendar) {
        return [
            ...base,
            { id: 'all', name: 'Wszystkie wizyty', value: 'all' },
            ...(props.users || []),
        ];
    }
    const managed = (props.users || []).filter(u => (props.managedCalendarIds || []).includes(u.id));
    return [...base, ...managed];
});

// Parametry do nawigacji kalendarza (zachowaj user_id przy zmianie miesiąca)
function calendarParams(overrides = {}) {
    const params = { year: props.year, month: props.month, ...overrides };
    if (props.canSelectUser && userFilter.value) {
        params.user_id = userFilter.value;
    }
    if (currentView.value && currentView.value !== 'month') {
        params.view = currentView.value;
    }
    if (trashedFilter.value && overrides.trashed !== false) {
        params.trashed = true;
    }
    return params;
}

const trashedFilter = ref(props.trashed || false);

watch(() => props.trashed, (v) => {
    trashedFilter.value = !!v;
});

watch(trashedFilter, () => {
    router.get(route('calendar.index'), calendarParams(), {
        preserveState: true,
        preserveScroll: true,
    });
});

watch(userFilter, () => {
    router.get(route('calendar.index'), calendarParams(), {
        preserveState: true,
        preserveScroll: true,
    });
});

// Synchronizuj currentView z props po nawigacji (nie przy pierwszym mount – tam używamy localStorage)
watch(() => props.view, (v) => {
    if (v && v !== currentView.value) {
        currentView.value = v;
    }
});

// Przywróć zapamiętany widok przy pierwszym ładowaniu (gdy URL nie ma view)
onMounted(() => {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(CALENDAR_VIEW_KEY) : null;
    if (stored && (stored === 'week' || stored === 'day') && (!props.view || props.view === 'month')) {
        currentView.value = stored;
        const today = new Date();
        if (!selectedDay.value) {
            selectedDay.value = formatDate(today);
            selectedWeekStart.value = getWeekStart(today);
        }
        router.get(route('calendar.index'), calendarParams({ view: stored }), {
            preserveState: true,
            preserveScroll: true,
        });
    }
});

function handleVisitRefresh() {
    // Zawsze zostań w aktualnym miesiącu – nie przełączaj na miesiąc terminu realizacji
    router.get(route('calendar.index'), calendarParams(), {
        preserveScroll: true,
    });
}

const monthNames = [
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
];

const dayNames = ['pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.', 'niedz.'];

// Reaktywna kopia wizyt dla drag & drop
const visitsData = ref({});
// Mutowalne listy dla widoku tygodnia/dnia (wszystkie wizyty całodniowe)
const allDayVisitsData = ref({});

// Inicjalizuj tablice dla wszystkich dni kalendarza (wszystkie wizyty = całodniowe)
function initializeVisitsData() {
    const data = {};
    const allDay = {};

    // Zakres: miesiąc ± 2 oraz daty z props (startDate/endDate)
    let firstDay = new Date(props.year, props.month - 2, 1);
    let lastDay = new Date(props.year, props.month + 1, 0);
    if (props.startDate && props.endDate) {
        const start = new Date(props.startDate);
        const end = new Date(props.endDate);
        if (start < firstDay) firstDay = start;
        if (end > lastDay) lastDay = end;
    }

    for (let d = new Date(firstDay); d <= lastDay; d.setDate(d.getDate() + 1)) {
        const dateStr = formatDateHelper(d);
        data[dateStr] = [];
        allDay[dateStr] = [];
    }

    // Wypełnij wizytami z props – wszystkie jako całodniowe
    for (const [date, visits] of Object.entries(props.visits || {})) {
        if (!data[date]) {
            data[date] = [];
            allDay[date] = [];
        }
        const copies = visits.map(v => ({ ...v }));
        data[date] = copies;
        for (const v of copies) allDay[date].push(v);
    }

    visitsData.value = data;
    allDayVisitsData.value = allDay;
}

function formatDateHelper(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

watch(() => [props.visits, props.year, props.month], () => {
    initializeVisitsData();
}, { immediate: true, deep: true });

watch(() => [props.selectedUserId, props.showAll], ([userId, showAll]) => {
    userFilter.value = showAll ? 'all' : (userId ?? '');
});

// Otwórz pełne okno edycji wizyty po zapisie klienta + wizyty
async function openVisitModal(visitId) {
    if (!visitId) return;
    // Szukaj w props.visits
    if (props.visits) {
        for (const visits of Object.values(props.visits)) {
            const visit = visits.find((v) => v.id == visitId);
            if (visit) {
                selectedVisit.value = visit;
                showClientModal.value = true;
                return;
            }
        }
    }
    // Fallback: pobierz wizytę z API
    try {
        const res = await fetch(route('calendar.visit-context', visitId), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.ok) {
            const data = await res.json();
            if (data.visit) {
                selectedVisit.value = data.visit;
                showClientModal.value = true;
            }
        }
    } catch (e) {
        console.error('Błąd pobierania wizyty:', e);
    }
}
watch(() => props.openedVisitId ?? page.props.flash?.openedVisitId, (visitId) => {
    openVisitModal(visitId);
}, { immediate: true });
// Generuj dni kalendarza
const calendarDays = computed(() => {
    const days = [];
    const firstDay = new Date(props.year, props.month - 1, 1);
    const lastDay = new Date(props.year, props.month, 0);
    
    // Dzień tygodnia pierwszego dnia (0 = niedziela, 1 = poniedziałek)
    let startDayOfWeek = firstDay.getDay();
    if (startDayOfWeek === 0) startDayOfWeek = 7;
    
    // Dni z poprzedniego miesiąca
    const prevMonthLastDay = new Date(props.year, props.month - 1, 0).getDate();
    for (let i = startDayOfWeek - 1; i > 0; i--) {
        const day = prevMonthLastDay - i + 1;
        const date = new Date(props.year, props.month - 2, day);
        days.push({
            date: date,
            dateStr: formatDate(date),
            day: day,
            isCurrentMonth: false,
            isToday: isToday(date),
        });
    }
    
    // Dni bieżącego miesiąca
    for (let day = 1; day <= lastDay.getDate(); day++) {
        const date = new Date(props.year, props.month - 1, day);
        days.push({
            date: date,
            dateStr: formatDate(date),
            day: day,
            isCurrentMonth: true,
            isToday: isToday(date),
        });
    }
    
    // Dni z następnego miesiąca
    const remainingDays = 42 - days.length;
    for (let day = 1; day <= remainingDays; day++) {
        const date = new Date(props.year, props.month, day);
        days.push({
            date: date,
            dateStr: formatDate(date),
            day: day,
            isCurrentMonth: false,
            isToday: isToday(date),
        });
    }
    
    return days;
});

function formatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

// Parsuj YYYY-MM-DD jako datę lokalną (bez przesunięcia strefy czasowej)
function parseDateLocal(dateStr) {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function isToday(date) {
    const today = new Date();
    return date.toDateString() === today.toDateString();
}

function navigateMonth(delta) {
    let newMonth = props.month + delta;
    let newYear = props.year;
    
    if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    } else if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    }
    
    router.get(route('calendar.index'), calendarParams({ year: newYear, month: newMonth }), {
        preserveState: true,
        preserveScroll: true,
    });
}

function goToToday() {
    const today = new Date();
    selectedDay.value = formatDate(today);
    selectedWeekStart.value = getWeekStart(today);
    router.get(route('calendar.index'), calendarParams({ 
        year: today.getFullYear(), 
        month: today.getMonth() + 1 
    }), {
        preserveState: true,
        preserveScroll: true,
    });
}

function changeView(view) {
    currentView.value = view;
    try {
        localStorage.setItem(CALENDAR_VIEW_KEY, view);
    } catch (_) {}
    
    // Jeśli nie ma wybranego dnia, użyj dzisiaj
    if (!selectedDay.value) {
        const today = new Date();
        selectedDay.value = formatDate(today);
        selectedWeekStart.value = getWeekStart(today);
    }

    // Widok tygodniowy/dzienny: przeładuj z rozszerzonym zakresem dat (sąsiednie miesiące)
    if (view === 'week' || view === 'day') {
        router.get(route('calendar.index'), calendarParams({ view }), {
            preserveState: true,
            preserveScroll: true,
        });
    }
}

function getWeekStart(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Poniedziałek
    d.setDate(diff);
    return formatDate(d);
}

function selectDay(dateStr) {
    selectedDay.value = dateStr;
    selectedWeekStart.value = getWeekStart(parseDateLocal(dateStr));
}

// Dni dla widoku tygodniowego
const weekDays = computed(() => {
    if (!selectedWeekStart.value) {
        const today = new Date();
        selectedWeekStart.value = getWeekStart(today);
    }
    
    const days = [];
    const startDate = parseDateLocal(selectedWeekStart.value);
    
    for (let i = 0; i < 7; i++) {
        const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate() + i);
        days.push({
            date: date,
            dateStr: formatDate(date),
            day: date.getDate(),
            dayName: dayNames[i],
            isToday: isToday(date),
            isCurrentMonth: date.getMonth() + 1 === props.month,
        });
    }
    
    return days;
});

function navigateWeek(delta) {
    const current = parseDateLocal(selectedWeekStart.value);
    current.setDate(current.getDate() + (delta * 7));
    selectedWeekStart.value = formatDate(current);
    
    // Jeśli tydzień jest w innym miesiącu, zaktualizuj
    if (current.getMonth() + 1 !== props.month || current.getFullYear() !== props.year) {
        router.get(route('calendar.index'), calendarParams({ 
            year: current.getFullYear(), 
            month: current.getMonth() + 1 
        }), {
            preserveState: true,
            preserveScroll: true,
        });
    }
}

function navigateDay(delta) {
    const current = parseDateLocal(selectedDay.value);
    current.setDate(current.getDate() + delta);
    selectedDay.value = formatDate(current);
    selectedWeekStart.value = getWeekStart(current);
    
    // Jeśli dzień jest w innym miesiącu, zaktualizuj
    if (current.getMonth() + 1 !== props.month || current.getFullYear() !== props.year) {
        router.get(route('calendar.index'), calendarParams({ 
            year: current.getFullYear(), 
            month: current.getMonth() + 1 
        }), {
            preserveState: true,
            preserveScroll: true,
        });
    }
}

function ensureAllDayList(dateStr) {
    if (!allDayVisitsData.value[dateStr]) {
        allDayVisitsData.value[dateStr] = [];
    }
    return allDayVisitsData.value[dateStr];
}

function openAddModal(dateStr) {
    selectedDate.value = dateStr;
    addForm.visit_date = dateStr;
    addForm.visit_time = '';
    showAddModal.value = true;
}

function openClientModal(visit) {
    selectedVisit.value = visit;
    showClientModal.value = true;
}

const clientModalRef = ref(null);

async function closeClientModal() {
    if (clientModalRef.value?.silentSave) {
        await clientModalRef.value.silentSave();
    }
    showClientModal.value = false;
    selectedVisit.value = null;
    router.reload({ preserveScroll: true });
}

async function handleModalMinimize() {
    const visit = selectedVisit.value;
    if (!visit?.id) return;
    if (clientModalRef.value?.silentSave) {
        await clientModalRef.value.silentSave();
    }
    showClientModal.value = false;
    router.reload({ preserveScroll: true });
    window.dispatchEvent(new CustomEvent('open-visit-floating', { detail: { visit, minimized: true } }));
}

function handleVisitClick(e, visit) {
    if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        e.stopPropagation();
        window.dispatchEvent(new CustomEvent('open-visit-floating', { detail: { visit } }));
    } else {
        openClientModal(visit);
    }
}

function getVisitColor(visit) {
    // Kolor statusu ma pierwszeństwo, potem custom color wizyty
    if (visit.status?.color) return visit.status.color;
    if (visit.color) return visit.color;
    const statusId = visit.status_id;
    if (statusId && props.statuses?.length) {
        const s = props.statuses.find(st => st.id === statusId);
        if (s?.color) return s.color;
    }
    return '#3B82F6';
}

function getVisitStyle(visit) {
    const color = getVisitColor(visit);
    return {
        backgroundColor: color,
        borderLeftColor: color,
    };
}

function visitTime(visit) {
    const t = visit?.visit_time;
    if (t == null || t === '') return '';
    const s = String(t).slice(0, 5);
    if (!/^\d{1,2}:\d{2}$/.test(s)) return '';
    return s;
}

function visitTitleOnly(visit) {
    return visit.title || visit.client?.name || 'Wizyta';
}

function visitPrimaryLabel(visit) {
    const time = visitTime(visit);
    const title = visitTitleOnly(visit);
    return time ? `${time} ${title}` : title;
}

function getVisitTooltipContent(visit) {
    const parts = [];
    const time = visitTime(visit);
    const title = visitTitleOnly(visit);
    parts.push(time ? `${time} ${title}` : title);
    if (visit.status?.name) parts.push(visit.status.name);
    if (visit.notes) parts.push(visit.notes.substring(0, 80) + (visit.notes.length > 80 ? '…' : ''));
    parts.push('Ctrl+klik – otwórz w osobnym panelu');
    return parts;
}

// Drag & Drop handlers
function onDragStart() {
    isDragging.value = true;
}

function onDragEnd() {
    isDragging.value = false;
}

function onVisitMoved(evt, newDateStr) {
    if (evt.added) {
        const visit = evt.added.element;
        const keptTime = visit.visit_time;
        visit.visit_date = newDateStr;
        updateVisitDate(visit.id, newDateStr, keptTime);
    }
}

function updateVisitDate(visitId, newDate, visitTime = null) {
    const payload = {
        visit_date: newDate,
        deadline: newDate,
        visit_time: visitTime ?? null,
    };
    const [newYear, newMonth] = newDate.split('-').map(Number);
    const movedToOtherMonth = newMonth !== props.month || newYear !== props.year;
    router.put(route('calendar.update', visitId), payload, {
        preserveState: false,
        preserveScroll: true,
        onSuccess: () => {
            // Po przeniesieniu na inny miesiąc – wróć do aktualnego miesiąca (dzisiaj)
            if (movedToOtherMonth) {
                const today = new Date();
                const currentYear = today.getFullYear();
                const currentMonth = today.getMonth() + 1;
                router.get(route('calendar.index'), calendarParams({ year: currentYear, month: currentMonth }), {
                    preserveState: false,
                    preserveScroll: true,
                });
            }
        },
        onError: (errors) => {
            console.error('Błąd aktualizacji wizyty:', errors);
            router.reload({ only: ['visits'] });
        },
    });
}

// Formularz dodawania wizyty (wszystkie całodniowe)
const addForm = useForm({
    client_id: '',
    visit_date: '',
    visit_time: '',
    title: '',
    notes: '',
    color: '#3B82F6',
    status: '',
    phones: [''],
});

function addPhoneInput() {
    addForm.phones.push('');
}

function removePhoneInput(idx) {
    addForm.phones.splice(idx, 1);
    if (addForm.phones.length === 0) addForm.phones.push('');
}

function getVisitTitle() {
    if (addForm.title?.trim()) return addForm.title;
    if (addForm.client_id) {
        const client = localClients.value.find(c => String(c.id) === String(addForm.client_id));
        if (client?.name) return client.name;
    }
    return '';
}

function submitAddForm() {
    addForm.visit_date = selectedDate.value || addForm.visit_date;
    const cleanPhones = (addForm.phones || []).map(p => String(p || '').trim()).filter(Boolean);
    const visitData = {
        client_id: addForm.client_id || null,
        visit_date: addForm.visit_date,
        visit_time: addForm.visit_time?.trim() || null,
        title: getVisitTitle(),
        notes: addForm.notes || null,
        phones: cleanPhones,
        color: addForm.color || '#3B82F6',
        status: addForm.status || null,
        year: props.year,
        month: props.month,
        view: currentView.value,
        user_id: (userFilter.value && userFilter.value !== 'all') ? userFilter.value : undefined,
        open_after_create: true,
    };
    router.post(route('calendar.store'), visitData, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            showAddModal.value = false;
            addForm.reset();
            addForm.phones = [''];
        },
        onError: (errors) => {
            console.error('Błąd dodawania wizyty:', errors);
        },
    });
}

function createVisitWithClient(clientId, clientName) {
    const visitDate = addForm.visit_date || selectedDate.value || formatDate(new Date());
    const visitData = {
        client_id: String(clientId),
        visit_date: visitDate,
        visit_time: addForm.visit_time?.trim() || null,
        title: clientName || addForm.title || '',
        notes: addForm.notes || null,
        color: addForm.color || '#3B82F6',
        status: addForm.status || null,
        year: props.year,
        month: props.month,
        view: currentView.value,
        user_id: (userFilter.value && userFilter.value !== 'all') ? userFilter.value : undefined,
        open_after_create: true,
    };
    router.post(route('calendar.store'), visitData, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            showAddModal.value = false;
            addForm.reset();
        },
        onError: (errors) => {
            console.error('Błąd dodawania wizyty:', errors);
        },
    });
}

function onClientCreated(client) {
    if (!localClients.value.some(c => c.id === client.id)) {
        localClients.value.push(client);
    }
    showNewClientForm.value = false;
    createVisitWithClient(client.id, client.name);
}

// Aktualizuj lokalnych klientów gdy props się zmienią
watch(() => props.clients, (newClients) => {
    localClients.value = [...newClients];
}, { deep: true });
</script>

<template>
    <Head title="Kalendarz" />

    <div class="calendar-container">
        <!-- Header -->
        <div class="calendar-header">
            <div class="flex items-center gap-4">
                <button @click="navigateMonth(-1)" class="nav-btn">
                    <Icons name="chevron-left" class="w-5 h-5" />
                </button>
                <button @click="navigateMonth(1)" class="nav-btn">
                    <Icons name="chevron-right" class="w-5 h-5" />
                </button>
                <button @click="goToToday" class="today-btn">Dziś</button>
            </div>
            
            <h1 class="calendar-title">
                {{ trashedFilter ? 'Kosz – usunięte spotkania' : `${String(month).padStart(2, '0')}.${year} – ${String(month).padStart(2, '0')}.${year}` }}
            </h1>
            
            <div class="flex items-center gap-2">
                <div v-if="canSelectUser" class="w-48">
                    <Select
                        v-model="userFilter"
                        :options="userOptions"
                        placeholder="Kalendarz"
                    />
                </div>
                <button @click="changeView('month')" class="view-btn" :class="{ active: currentView === 'month' }">Miesiąc</button>
                <button @click="changeView('week')" class="view-btn" :class="{ active: currentView === 'week' }">Tydzień</button>
                <button @click="changeView('day')" class="view-btn" :class="{ active: currentView === 'day' }">Dzień</button>
                <label class="flex items-center gap-2 ml-2 cursor-pointer">
                    <input type="checkbox" v-model="trashedFilter" class="rounded border-gray-300 text-amber-600 focus:ring-brand-primary dark:border-slate-600 dark:bg-slate-700" />
                    <span class="text-sm text-foreground">Kosz</span>
                </label>
            </div>
        </div>

        <!-- Wyszukiwarka -->
        <div ref="calendarSearchRef" class="relative max-w-md mb-4">
            <div class="relative">
                <Icons name="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-foreground-subtle pointer-events-none" />
                <input
                    v-model="calendarSearchQuery"
                    type="text"
                    placeholder="Szukaj spotkania lub klienta..."
                    class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-600 surface text-foreground placeholder-slate-400 focus:ring-2 focus:ring-brand-primary focus:border-brand-primary dark:focus:ring-brand-primary/50"
                    @focus="calendarSearchOpen = true"
                    @input="onCalendarSearchInput"
                    @keydown="handleCalendarSearchKeydown"
                />
                <button
                    v-if="calendarSearchQuery"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    @click="calendarSearchQuery = ''; calendarSearchResults = { visits: [], clients: [] }; calendarSearchOpen = false"
                >
                    <Icons name="close" class="w-4 h-4" />
                </button>
            </div>
            <!-- Wyniki wyszukiwania -->
            <div
                v-if="calendarSearchOpen && (calendarSearchQuery.length >= 2 || calendarSearchFlat.length > 0)"
                class="absolute left-0 right-0 top-full mt-1 z-50 surface rounded-lg shadow-xl border border-border overflow-hidden max-h-80 overflow-y-auto"
            >
                <div v-if="calendarSearchLoading" class="p-4 text-center text-foreground-muted text-sm">
                    <Icons name="spinner" class="w-5 h-5 animate-spin inline mr-2" />
                    Szukam...
                </div>
                <div v-else-if="calendarSearchFlat.length === 0" class="p-4 text-center text-foreground-muted text-sm">
                    {{ calendarSearchQuery.length >= 2 ? 'Brak wyników' : 'Wpisz min. 2 znaki' }}
                </div>
                <button
                    v-for="(entry, i) in calendarSearchFlat"
                    :key="entry.type + '-' + (entry.item.id ?? i)"
                    type="button"
                    :class="[
                        'w-full flex items-center gap-3 px-4 py-3 text-left transition-colors',
                        i === calendarSearchSelectedIndex
                            ? 'bg-amber-500/20 dark:bg-amber-500/30'
                            : 'hover:bg-slate-50 dark:hover:bg-slate-700/50',
                    ]"
                    @click="selectCalendarSearchResult(entry)"
                >
                    <div
                        class="w-2 h-2 rounded-full shrink-0"
                        :style="{ backgroundColor: entry.type === 'visit' ? (entry.item.status?.color || entry.item.color || '#3B82F6') : '#6B7280' }"
                    ></div>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-foreground truncate">
                            {{ entry.type === 'visit' ? (entry.item.title || entry.item.client?.name || 'Wizyta') : entry.item.name }}
                        </div>
                        <div class="text-xs text-foreground-muted">
                            <template v-if="entry.type === 'visit'">
                                {{ entry.item.visit_date }} – {{ entry.item.title || entry.item.client?.name || 'Spotkanie' }}
                            </template>
                            <template v-else>
                                {{ entry.item.city || entry.item.nip || 'Klient' }}
                            </template>
                        </div>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded surface-elevated text-slate-600 dark:text-slate-300 shrink-0">
                        {{ entry.type === 'visit' ? 'Spotkanie' : 'Klient' }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Nazwa miesiąca i rok -->
        <div class="month-display">
            <div class="flex items-center gap-4">
                <template v-if="currentView === 'month'">
                    <span class="text-3xl font-bold text-slate-800">{{ monthNames[month - 1] }} {{ year }}</span>
                </template>
                <template v-else-if="currentView === 'week'">
                    <button @click="navigateWeek(-1)" class="nav-btn-sm">
                        <Icons name="chevron-left" class="w-4 h-4" />
                    </button>
                    <span class="text-2xl font-bold text-slate-800">
                        Tydzień {{ weekDays[0]?.day }}-{{ weekDays[6]?.day }} {{ monthNames[month - 1] }}
                    </span>
                    <button @click="navigateWeek(1)" class="nav-btn-sm">
                        <Icons name="chevron-right" class="w-4 h-4" />
                    </button>
                </template>
                <template v-else-if="currentView === 'day'">
                    <button @click="navigateDay(-1)" class="nav-btn-sm">
                        <Icons name="chevron-left" class="w-4 h-4" />
                    </button>
                    <span class="text-2xl font-bold text-slate-800">
                        {{ selectedDay ? new Date(selectedDay).getDate() : '' }} {{ monthNames[month - 1] }} {{ year }}
                    </span>
                    <button @click="navigateDay(1)" class="nav-btn-sm">
                        <Icons name="chevron-right" class="w-4 h-4" />
                    </button>
                </template>
            </div>
            <div class="text-sm text-slate-500 mt-1">
                <Icons name="info" class="w-4 h-4 inline mr-1" />
                Przeciągnij wizytę, aby zmienić jej datę
            </div>
        </div>

        <!-- WIDOK MIESIĄCA -->
        <div v-if="currentView === 'month'" class="calendar-grid">
            <!-- Nagłówki dni tygodnia -->
            <div v-for="dayName in dayNames" :key="dayName" class="day-header">
                {{ dayName }}
            </div>
            
            <!-- Dni z drag & drop -->
            <div 
                v-for="(day, index) in calendarDays" 
                :key="index"
                class="calendar-day"
                :class="{
                    'other-month': !day.isCurrentMonth,
                    'today': day.isToday,
                    'drag-over': isDragging,
                }"
                @dblclick="openAddModal(day.dateStr)"
                @click="selectDay(day.dateStr)"
            >
                <div class="day-number">{{ day.day }}</div>
                
                <!-- Draggable visits container -->
                <draggable
                    :list="visitsData[day.dateStr] || []"
                    :disabled="trashedFilter"
                    group="visits"
                    item-key="id"
                    class="visits-container"
                    :class="{ 'min-h-[60px]': isDragging }"
                    ghost-class="visit-ghost"
                    drag-class="visit-drag"
                    @start="onDragStart"
                    @end="onDragEnd"
                    @change="(evt) => onVisitMoved(evt, day.dateStr)"
                >
                    <template #item="{ element: visit }">
                        <div class="visit-item-wrapper group relative">
                            <div 
                                class="visit-item"
                                :style="getVisitStyle(visit)"
                                @click.stop="handleVisitClick($event, visit)"
                            >
                                <span class="visit-title">
                                    <span v-if="visitTime(visit)" class="visit-time">{{ visitTime(visit) }}</span>
                                    {{ visitTitleOnly(visit) }}
                                </span>
                            </div>
                            <div class="visit-tooltip">
                                <div v-for="(line, i) in getVisitTooltipContent(visit).filter(Boolean)" :key="i" class="visit-tooltip-line">
                                    {{ line }}
                                </div>
                            </div>
                        </div>
                    </template>
                </draggable>
            </div>
        </div>

        <!-- WIDOK TYGODNIA -->
        <div v-else-if="currentView === 'week'" class="week-view">
            <!-- Nagłówki dni -->
            <div class="week-header">
                <div class="time-column-header"></div>
                <div 
                    v-for="day in weekDays" 
                    :key="day.dateStr" 
                    class="week-day-header"
                    :class="{ 'today': day.isToday }"
                    @click="selectedDay = day.dateStr; currentView = 'day'"
                >
                    <div class="text-xs text-slate-500 uppercase">{{ day.dayName }}</div>
                    <div class="text-lg font-bold" :class="day.isToday ? 'text-amber-600' : 'text-slate-800'">{{ day.day }}</div>
                </div>
            </div>
            
            <!-- Wizyty całodniowe -->
            <div class="week-all-day">
                <div class="time-label text-xs text-gray-500 shrink-0"></div>
                <div 
                    v-for="day in weekDays" 
                    :key="'allday-' + day.dateStr" 
                    class="week-all-day-cell"
                    @dblclick="selectedDate = day.dateStr; addForm.visit_date = day.dateStr; addForm.visit_time = ''; showAddModal = true"
                >
                    <draggable
                        :list="ensureAllDayList(day.dateStr)"
                        :disabled="trashedFilter"
                        group="visits"
                        item-key="id"
                        class="h-full min-h-[30px]"
                        ghost-class="visit-ghost"
                        @start="onDragStart"
                        @end="onDragEnd"
                        @change="(evt) => onVisitMoved(evt, day.dateStr)"
                    >
                        <template #item="{ element: visit }">
                            <div class="visit-item-wrapper group relative">
                                <div
                                    class="visit-item-week"
                                    :style="getVisitStyle(visit)"
                                    @click="handleVisitClick($event, visit)"
                                >
                                    <span v-if="visitTime(visit)" class="visit-time">{{ visitTime(visit) }}</span>
                                    {{ visitTitleOnly(visit) }}
                                </div>
                                <div class="visit-tooltip visit-tooltip-week">
                                    <div v-for="(line, i) in getVisitTooltipContent(visit).filter(Boolean)" :key="i" class="visit-tooltip-line">{{ line }}</div>
                                </div>
                            </div>
                        </template>
                    </draggable>
                </div>
            </div>
        </div>

        <!-- WIDOK DNIA -->
        <div v-else-if="currentView === 'day'" class="day-view">
            <!-- Wizyty całodniowe -->
            <div class="day-all-day" @dblclick="selectedDate = selectedDay; addForm.visit_date = selectedDay; addForm.visit_time = ''; showAddModal = true">
                <div class="day-all-day-content flex-1">
                    <draggable
                        v-if="selectedDay"
                        :list="ensureAllDayList(selectedDay)"
                        :disabled="trashedFilter"
                        group="visits"
                        item-key="id"
                        class="flex flex-wrap gap-2 min-h-[80px]"
                        ghost-class="visit-ghost"
                        @start="onDragStart"
                        @end="onDragEnd"
                        @change="(evt) => onVisitMoved(evt, selectedDay)"
                    >
                        <template #item="{ element: visit }">
                            <div class="visit-item-wrapper group relative">
                                <div
                                    class="visit-item-day"
                                    :style="getVisitStyle(visit)"
                                    @click="handleVisitClick($event, visit)"
                                >
                                    <span v-if="visitTime(visit)" class="visit-time">{{ visitTime(visit) }}</span>
                                    {{ visitTitleOnly(visit) }}
                                </div>
                                <div class="visit-tooltip visit-tooltip-week">
                                    <div v-for="(line, i) in getVisitTooltipContent(visit).filter(Boolean)" :key="i" class="visit-tooltip-line">{{ line }}</div>
                                </div>
                            </div>
                        </template>
                    </draggable>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal dodawania wizyty -->
    <div v-if="showAddModal" class="modal-overlay" @click.self="showAddModal = false">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Dodaj wizytę klienta</h3>
                <button @click="showAddModal = false" class="close-btn">
                    <Icons name="close" class="w-5 h-5" />
                </button>
            </div>
            
            <form @submit.prevent="submitAddForm" class="modal-body">
                <!-- Wybór klienta lub dodanie nowego -->
                <div class="form-group">
                    <div class="flex items-center justify-between mb-1">
                        <label>Klient</label>
                        <button 
                            type="button" 
                            @click="showNewClientForm = !showNewClientForm"
                            class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center gap-1"
                        >
                            <Icons :name="showNewClientForm ? 'chevron-up' : 'plus'" class="w-4 h-4" />
                            {{ showNewClientForm ? 'Wybierz istniejącego' : 'Dodaj nowego klienta' }}
                        </button>
                    </div>
                    
                    <!-- Wybór istniejącego klienta -->
                    <select
                        v-if="!showNewClientForm"
                        v-model="addForm.client_id"
                        class="form-select"
                    >
                        <option value="">Wybierz klienta...</option>
                        <option v-for="client in localClients" :key="client.id" :value="client.id">
                            {{ client.name }} {{ client.nip ? `(NIP: ${client.nip})` : '' }}
                        </option>
                    </select>

                    <!-- Quick form nowego klienta -->
                    <div v-else class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg border border-border">
                        <ClientQuickForm
                            @created="onClientCreated"
                            @cancel="showNewClientForm = false"
                        />
                    </div>
                    
                </div>
                
                <div class="form-group">
                    <label>Data</label>
                    <input type="date" v-model="addForm.visit_date" class="form-input" />
                </div>

                <div class="form-group">
                    <label>Godzina (opcjonalnie)</label>
                    <input type="time" v-model="addForm.visit_time" class="form-input w-40" />
                    <span class="text-xs text-foreground-muted block mt-1">Puste = cały dzień, bez godziny na kafelku</span>
                </div>
                
                <div class="form-group">
                    <label>Tytuł (opcjonalnie)</label>
                    <input type="text" v-model="addForm.title" class="form-input" placeholder="Nazwa wizyty..." />
                </div>

                <div class="form-group">
                    <label>Telefony do wizyty</label>
                    <p class="text-xs text-foreground-muted -mt-1 mb-2">
                        Numery z którymi wiązana jest wizyta. W Play Centrali połączenie z tego numeru pokaże tytuł wizyty.
                    </p>
                    <div class="space-y-2">
                        <div v-for="(phone, idx) in addForm.phones" :key="idx" class="flex items-center gap-2">
                            <input
                                type="tel"
                                v-model="addForm.phones[idx]"
                                class="form-input flex-1"
                                :placeholder="idx === 0 ? '+48 500 123 456 (główny)' : 'Dodatkowy numer'"
                            />
                            <button
                                type="button"
                                @click="removePhoneInput(idx)"
                                class="px-2 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition"
                                :title="'Usuń numer'"
                            >
                                <Icons name="close" class="w-4 h-4" />
                            </button>
                        </div>
                        <button
                            type="button"
                            @click="addPhoneInput"
                            class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            <Icons name="plus" class="w-4 h-4" /> Dodaj numer
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select v-model="addForm.status" class="form-input">
                        <option value="">Wybierz status</option>
                        <option v-for="s in statuses" :key="s.id" :value="s.id">
                            {{ s.name }}
                        </option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Kolor</label>
                    <div class="color-picker">
                        <button 
                            type="button"
                            v-for="color in ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6B7280']"
                            :key="color"
                            class="color-btn"
                            :style="{ backgroundColor: color }"
                            :class="{ selected: addForm.color === color }"
                            @click="addForm.color = color"
                        />
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notatki</label>
                    <textarea v-model="addForm.notes" class="form-textarea" rows="3" placeholder="Notatki do wizyty..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" @click="showAddModal = false" class="btn-secondary">Anuluj</button>
                    <button type="submit" class="btn-primary" :disabled="addForm.processing">
                        {{ addForm.processing ? 'Zapisywanie...' : 'Dodaj wizytę' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal klienta -->
    <ClientModal
        ref="clientModalRef"
        v-if="showClientModal"
        :visit="selectedVisit"
        :trashed="trashedFilter"
        :clients="localClients"
        :users="users || []"
        :email-templates="emailTemplates || []"
        :mail-configs="mailConfigs || []"
        :price-lists="priceLists || []"
        :statuses="statuses || []"
        :profile-options="profileOptions || {}"
        @close="closeClientModal"
        @refresh="(newVisitDate) => handleVisitRefresh(newVisitDate)"
        @minimize="handleModalMinimize"
    />
</template>

<style scoped>
.calendar-container {
    @apply bg-slate-50 min-h-screen dark:bg-slate-900;
}

.calendar-header {
    @apply flex items-center justify-between px-4 py-3 bg-white border-b border-slate-200 shadow-sm
           dark:bg-slate-800 dark:border-slate-700;
}

.nav-btn {
    @apply p-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors
           dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300;
}

.today-btn {
    @apply px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-medium transition-colors;
}

.calendar-title {
    @apply text-xl font-bold text-foreground;
}

.view-btn {
    @apply px-3 py-1.5 rounded text-sm text-slate-600 hover:bg-slate-100 transition-colors
           dark:text-slate-400 dark:hover:bg-slate-700;
}

.view-btn.active {
    @apply bg-amber-500 text-white;
}

.month-display {
    @apply px-4 py-3 bg-white border-b border-slate-200
           dark:bg-slate-800 dark:border-slate-700;
}

.calendar-grid {
    @apply grid grid-cols-7 surface;
}

.day-header {
    @apply px-2 py-3 text-center text-sm font-medium text-slate-500 bg-slate-50 border-b border-slate-200
           dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700;
}

.calendar-day {
    @apply min-h-[120px] p-1.5 border-r border-b border-slate-100 bg-white transition-colors cursor-pointer
           dark:bg-slate-800 dark:border-slate-700;
}

.calendar-day:hover {
    @apply bg-amber-50 dark:bg-slate-700;
}

.calendar-day.other-month {
    @apply bg-slate-50 text-slate-400 dark:bg-slate-900/50 dark:text-slate-600;
}

.calendar-day.today {
    @apply bg-amber-50 dark:bg-amber-900/20;
}

.calendar-day.today .day-number {
    @apply bg-amber-500 text-white rounded-full w-7 h-7 flex items-center justify-center;
}

.calendar-day.drag-over {
    @apply bg-amber-100 dark:bg-amber-900/30;
}

.day-number {
    @apply text-sm font-medium text-slate-600 mb-1 dark:text-slate-400;
}

.visits-container {
    @apply space-y-1 min-h-[20px];
}

.visit-item {
    @apply px-2 py-1 rounded text-xs text-white cursor-grab hover:opacity-90 hover:shadow-md transition-all truncate select-none;
    border-left: 3px solid;
}

.visit-item:active {
    @apply cursor-grabbing;
}

.visit-time {
    @apply font-bold mr-1;
}

.visit-title {
    @apply truncate;
}

/* Mini podgląd przy najechaniu */
.visit-item-wrapper {
    @apply relative;
}

.visit-tooltip {
    @apply absolute left-0 bottom-full mb-1 z-50 px-3 py-2 rounded-lg shadow-lg bg-slate-800 text-white text-xs
           opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity duration-150
           pointer-events-none;
    min-width: 180px;
    max-width: 280px;
}

.visit-tooltip-week {
    @apply left-1/2 -translate-x-1/2;
}

.visit-tooltip-line {
    @apply py-0.5;
}

.visit-tooltip-line:first-child {
    @apply font-semibold text-sm;
}

/* Drag & Drop styles */
.visit-ghost {
    @apply opacity-50 bg-slate-300 dark:bg-slate-600;
}

.visit-drag {
    @apply opacity-100 shadow-lg scale-105;
}

/* Modal styles */
.modal-overlay {
    @apply fixed inset-0 bg-black/50 flex items-center justify-center z-50 dark:bg-black/70;
}

.modal-content {
    @apply bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4 flex flex-col
           dark:bg-slate-800 dark:shadow-slate-900/50;
    max-height: 90vh;
}

.modal-header {
    @apply flex items-center justify-between px-6 py-4 border-b dark:border-slate-700;
}

.modal-header h3 {
    @apply text-lg font-semibold text-foreground;
}

.close-btn {
    @apply p-1 rounded-lg hover:bg-gray-100 text-gray-500
           dark:hover:bg-slate-700 dark:text-slate-400;
}

.modal-body {
    @apply p-6 space-y-4 overflow-y-auto;
}

.modal-footer {
    @apply flex justify-end gap-3 pt-4 border-t dark:border-slate-700;
}

.form-group {
    @apply space-y-1;
}

.form-group label {
    @apply block text-sm font-medium text-foreground;
}

.form-input, .form-select, .form-textarea {
    @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-brand-primary
           dark:bg-slate-700 dark:border-slate-600 dark:text-slate-200 dark:placeholder-slate-400;
}

.color-picker {
    @apply flex gap-2;
}

.color-btn {
    @apply w-8 h-8 rounded-full border-2 border-transparent hover:scale-110 transition-transform;
}

.color-btn.selected {
    @apply border-white ring-2 ring-amber-500 dark:border-slate-300;
}

.btn-primary {
    @apply px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 font-medium disabled:opacity-50;
}

.btn-secondary {
    @apply px-4 py-2 bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 font-medium
           dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600;
}

.btn-success {
    @apply px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50 disabled:cursor-not-allowed;
}

.new-client-form {
    @apply p-4 bg-slate-50 rounded-lg border border-slate-200
           dark:bg-slate-700 dark:border-slate-600;
}

.nav-btn-sm {
    @apply p-1 rounded bg-slate-200 hover:bg-slate-300 text-slate-700 transition-colors
           dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300;
}

/* Week View Styles */
.week-view {
    @apply bg-white overflow-auto rounded-lg shadow-sm dark:bg-slate-800;
    max-height: calc(100vh - 200px);
}

.week-header {
    @apply grid sticky top-0 bg-white z-10 border-b border-slate-200
           dark:bg-slate-800 dark:border-slate-700;
    grid-template-columns: 60px repeat(7, 1fr);
}

.time-column-header {
    @apply border-r border-border;
}

.week-day-header {
    @apply p-2 text-center border-r border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors min-w-0 overflow-hidden
           dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-700;
}

.week-day-header.today {
    @apply bg-amber-50 dark:bg-amber-900/20;
}

.week-all-day {
    @apply grid border-b border-border;
    grid-template-columns: 60px repeat(7, 1fr);
}

.week-all-day-cell {
    @apply p-2 border-r border-slate-200 min-h-[120px] dark:border-slate-700 min-w-0 overflow-hidden;
}

.week-grid {
    @apply divide-y divide-slate-100 dark:divide-slate-700;
}

.week-row {
    @apply grid;
    grid-template-columns: 60px repeat(7, 1fr);
}

.time-label {
    @apply px-2 py-2 text-xs text-slate-500 text-right border-r border-slate-200 bg-slate-50
           dark:bg-slate-800 dark:border-slate-700 dark:text-slate-500;
}

.week-cell {
    @apply p-1 border-r border-slate-100 min-h-[50px] min-w-0 overflow-hidden hover:bg-amber-50 cursor-pointer transition-colors
           dark:border-slate-700 dark:hover:bg-slate-700;
}

.week-cell.today {
    @apply bg-amber-50 dark:bg-amber-900/20;
}

.visit-item-week {
    @apply px-2 py-1 rounded text-xs text-white mb-1 cursor-pointer hover:opacity-90 hover:shadow-sm truncate block min-w-0;
    border-left: 3px solid;
}

/* Day View Styles */
.day-view {
    @apply bg-white overflow-auto rounded-lg shadow-sm dark:bg-slate-800;
    max-height: calc(100vh - 200px);
}

.day-all-day {
    @apply flex border-b border-slate-200 sticky top-0 bg-white z-10
           dark:bg-slate-800 dark:border-slate-700;
}

.day-all-day .time-label {
    @apply w-[80px] flex-shrink-0;
}

.day-all-day-content {
    @apply flex-1 p-2;
}

.day-grid {
    @apply divide-y divide-slate-100 dark:divide-slate-700;
}

.day-row {
    @apply flex;
}

.day-row .time-label {
    @apply w-[80px] flex-shrink-0;
}

.day-cell {
    @apply flex-1 p-2 min-h-[80px] hover:bg-amber-50 cursor-pointer transition-colors
           dark:hover:bg-slate-700;
}

.visit-item-day {
    @apply px-3 py-1 rounded text-sm text-white cursor-pointer hover:opacity-90 hover:shadow-sm;
    border-left: 3px solid;
}

.visit-item-day-full {
    @apply px-4 py-3 rounded text-white cursor-pointer hover:opacity-90 hover:shadow-md mb-2;
    border-left: 4px solid;
}
</style>
