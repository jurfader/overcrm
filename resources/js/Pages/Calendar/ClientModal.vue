<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick, markRaw } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import ClickToCall from '@/Components/ClickToCall.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    visit: Object,
    floatingMode: { type: Boolean, default: false },
    trashed: { type: Boolean, default: false },
    clients: {
        type: Array,
        default: () => []
    },
    users: {
        type: Array,
        default: () => []
    },
    emailTemplates: {
        type: Array,
        default: () => []
    },
    mailConfigs: {
        type: Array,
        default: () => []
    },
    priceLists: {
        type: Array,
        default: () => []
    },
    statuses: {
        type: Array,
        default: () => []
    },
    profileOptions: {
        type: Object,
        default: () => ({})
    },
});

const emit = defineEmits(['close', 'refresh', 'minimize']);

const page = usePage();
const activeTab = ref('details');

// Sprawdź czy moduł Ringostat jest aktywny
const hasRingostat = computed(() => {
    const modules = page.props.activeModules || [];
    return modules.some(m => m.name === 'playcentrala' || m.name === 'ringostat');
});
const isLoading = ref(false);
const invoices = ref([]);
const orders = ref([]);
const isSaving = ref(false);
/** Krótki kod do zgłoszeń / logów (np. VIS-XXXX) */
function makeErrorRef(prefix) {
    const t = Date.now().toString(36).toUpperCase();
    const r = Math.random().toString(36).slice(2, 6).toUpperCase();
    return `${prefix}-${t}-${r}`;
}

const visitSaveErrorRef = ref('');
const visitSaveErrorMessage = ref('');

const showDeleteModal = ref(false);
const showForceDeleteModal = ref(false);
const isDeleting = ref(false);

// Edytor opisu - tryb widoku (formatowany HTML vs surowe źródło)
const showDescriptionSource = ref(false);
const descriptionEditorRef = ref(null);
const showTextColorPicker = ref(false);
const showBgColorPicker = ref(false);
const editorColors = ['#000000', '#333333', '#666666', '#999999', '#ffffff', '#ff0000', '#ff6600', '#ffcc00', '#00cc00', '#0066ff', '#6600cc', '#cc00cc', '#ff0066', '#00cccc', '#663300', '#996633'];

// Szkic do localStorage – zapis przed deployem/odświeżeniem
const DRAFT_KEY_PREFIX = 'visit-draft-';
const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000; // 24h
let draftSaveTimeout = null;

function getDraftKey() {
    return props.visit?.id ? `${DRAFT_KEY_PREFIX}${props.visit.id}` : null;
}

function saveDraftToStorage() {
    const key = getDraftKey();
    if (!key) return;
    try {
        const draft = {
            description: form.description,
            notes: form.notes,
            title: form.title,
            link: form.link,
            website: form.website,
            savedAt: Date.now(),
        };
        localStorage.setItem(key, JSON.stringify(draft));
    } catch (_) {}
}

function loadDraftFromStorage() {
    const key = getDraftKey();
    if (!key) return null;
    try {
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const draft = JSON.parse(raw);
        if (!draft || Date.now() - (draft.savedAt || 0) > DRAFT_MAX_AGE_MS) return null;
        return draft;
    } catch (_) {
        return null;
    }
}

function clearDraft() {
    const key = getDraftKey();
    if (key) {
        try { localStorage.removeItem(key); } catch (_) {}
    }
}

function restoreDraft() {
    const draft = loadDraftFromStorage();
    if (!draft) return;
    if (draft.description != null) form.description = draft.description;
    if (draft.notes != null) form.notes = draft.notes;
    if (draft.title != null) form.title = draft.title;
    if (draft.link != null) form.link = draft.link;
    if (draft.website != null) form.website = draft.website;
    syncDescriptionEditorFromForm();
    hasDraftRestore.value = false;
    // Nie czyścimy draftu – zostaje do następnego otwarcia, jeśli użytkownik zamknie bez zapisu
}

const hasDraftRestore = ref(false);


function scheduleDraftSave() {
    if (draftSaveTimeout) clearTimeout(draftSaveTimeout);
    draftSaveTimeout = setTimeout(saveDraftToStorage, 800);
}

// Główny formularz edycji wizyty (godzina opcjonalna)
const form = useForm({
    title: props.visit?.title || '',
    client_id: props.visit?.client_id || '',
    visit_date: props.visit?.visit_date ? props.visit.visit_date.split('T')[0] : '',
    visit_time: props.visit?.visit_time ? String(props.visit.visit_time).substring(0, 5) : '',
    deadline: props.visit?.deadline ? String(props.visit.deadline).split('T')[0] : (props.visit?.visit_date ? props.visit.visit_date.split('T')[0] : ''),
    notes: props.visit?.notes || '',
    description: props.visit?.description || '',
    phones: Array.isArray(props.visit?.phones) && props.visit.phones.length > 0 ? [...props.visit.phones] : [''],
    link: props.visit?.link || '',
    website: props.visit?.website || '',
    status: props.visit?.status_id?.toString() || props.visit?.status || '',
    color: props.visit?.color || '#3B82F6',
    user_id: props.visit?.user_id || '',
    order_value: props.visit?.order_value || '',
});

function addVisitPhone() {
    form.phones.push('');
}

function removeVisitPhone(idx) {
    form.phones.splice(idx, 1);
    if (form.phones.length === 0) form.phones.push('');
}

// Dynamiczne statusy z bazy danych
const statuses = computed(() => {
    return props.statuses.map(s => ({
        value: s.id.toString(),
        label: s.name,
        color: s.color || '#3B82F6',
        bgClass: getBgClass(s.color),
    }));
});

function getBgClass(hexColor) {
    // Mapowanie kolorów hex na klasy Tailwind
    const colorMap = {
        '#3B82F6': 'bg-blue-100 text-blue-800',
        '#F59E0B': 'bg-yellow-100 text-yellow-800',
        '#8B5CF6': 'bg-purple-100 text-purple-800',
        '#10B981': 'bg-green-100 text-green-800',
        '#6B7280': 'bg-gray-100 text-gray-800',
        '#EF4444': 'bg-red-100 text-red-800',
    };
    return colorMap[hexColor?.toUpperCase()] || 'bg-gray-100 text-gray-800';
}

const currentStatus = computed(() => {
    const statusId = form.status?.toString();
    return statuses.value.find(s => s.value === statusId) || statuses.value[0] || { label: 'Brak', bgClass: 'bg-gray-100 text-gray-800' };
});

const visitCreatedAtFormatted = computed(() => {
    const d = props.visit?.created_at;
    if (!d) return '—';
    const date = new Date(d);
    return date.toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
});

// Edytor opisu - synchronizacja contenteditable z form.description
function syncDescriptionEditorFromForm() {
    nextTick(() => {
        const el = descriptionEditorRef.value;
        if (el && !showDescriptionSource.value) {
            el.innerHTML = form.description || '';
        }
    });
}

function onDescriptionEditorInput(e) {
    form.description = e.target.innerHTML;
}

function formatDescription(command, value = null) {
    descriptionEditorRef.value?.focus();
    document.execCommand(command, false, value);
}

// Formularz NIP lookup
const nipForm = useForm({ nip: '' });
const nipResult = ref(null);
const nipLoading = ref(false);
const clientCardNipLoading = ref(false);
const clientCardNipError = ref('');

// Formularz zamówienia Apilo
const orderClient = computed(() => props.visit?.client || {});

// Ringostat - połączenia klienta
const clientCalls = ref([]);
const loadingCalls = ref(false);

function loadClientCalls() {
    const visitId = props.visit?.id;
    if (!visitId) return;
    loadingCalls.value = true;

    // Priorytet — nowy endpoint oparty o wizytę: matchuje po visit_id, client_id wizyty I po numerach z phones
    fetch(route('playcentrala.visit-calls', visitId), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    })
    .then(r => r.json())
    .then(data => {
        clientCalls.value = data.calls || [];
    })
    .catch(() => {})
    .finally(() => loadingCalls.value = false);
}

// Audio player for calls tab
const playingId = ref(null);
const callAudio = ref(null);

function toggleCallPlay(call) {
    const url = call.recording_wav_url || call.recording_url;
    if (!url) return;
    if (playingId.value === call.id) {
        callAudio.value?.pause();
        callAudio.value = null;
        playingId.value = null;
        return;
    }
    callAudio.value?.pause();
    playingId.value = call.id;
    callAudio.value = new Audio(url);
    callAudio.value.play();
    callAudio.value.onended = () => { playingId.value = null; callAudio.value = null; };
}
const VAT_RATES = [0, 5, 8, 23];

/** Nowy wiersz zamówienia — stabilny klucz (Vue nie „przenosi” pól między wierszami przy :key=index) */
function newOrderLine() {
    return {
        _apiloRowKey:
            typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
                ? crypto.randomUUID()
                : `r-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`,
        product_id: '',
        name: '',
        quantity: 1,
        price: 0,
        tax_rate: 23,
    };
}

/** Katalog Apilo poza głęboką reaktywnością Vue — inaczej edycja ceny w wierszu zamówienia mutuje te same obiekty co lista z API */
function cloneApiloCatalogFromApi(products) {
    const list = Array.isArray(products) ? products : [];
    const clones = list.map((p) => {
        try {
            return structuredClone(p);
        } catch {
            return JSON.parse(JSON.stringify(p));
        }
    });
    return markRaw(clones);
}

// ===================================================================
// CORE Orders (zakładka "Zamówienia") — niezależne od Apilo
// ===================================================================
const coreOrders = ref([]);
const coreOrdersLoading = ref(false);
const coreOrderModalOpen = ref(false);
const coreOrderSubmitting = ref(false);
const coreProductOptions = ref([]);
const coreOrderForm = ref(emptyCoreOrderForm());

function emptyCoreOrderForm() {
    return {
        order_date: new Date().toISOString().split('T')[0],
        delivery_date: '',
        status: 'new',
        notes: '',
        items: [emptyCoreOrderItem()],
    };
}
function emptyCoreOrderItem() {
    return { product_id: null, name: '', sku: '', unit: 'szt', quantity: 1, price_net: 0, vat_rate: 23 };
}

async function loadCoreOrders() {
    if (!props.visit?.client_id) return;
    coreOrdersLoading.value = true;
    try {
        const r = await fetch(route('clients.orders.list', props.visit.client_id), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });
        const d = await r.json();
        coreOrders.value = d.orders || [];
    } catch (e) {
        coreOrders.value = [];
    } finally {
        coreOrdersLoading.value = false;
    }
}

async function loadCoreProductOptions() {
    if (coreProductOptions.value.length) return;
    try {
        // Pobiera z aktywnego ProductProvider (LocalProductProvider domyślnie,
        // ApiloProductProvider gdy admin przełączy w Settings → Integracje).
        const r = await fetch(route('products.search'), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });
        const d = await r.json();
        coreProductOptions.value = (d.products || []).filter(p => p.active !== false);
    } catch (e) {
        coreProductOptions.value = [];
    }
}

function openCoreOrderForm() {
    coreOrderForm.value = emptyCoreOrderForm();
    loadCoreProductOptions();
    coreOrderModalOpen.value = true;
}

function addCoreItem() { coreOrderForm.value.items.push(emptyCoreOrderItem()); }
function removeCoreItem(i) { coreOrderForm.value.items.splice(i, 1); }

function pickCoreProduct(i, productId) {
    const p = coreProductOptions.value.find(x => String(x.id) === String(productId));
    if (!p) return;
    const item = coreOrderForm.value.items[i];
    item.product_id = p.id;
    item.name = p.name;
    item.sku = p.sku || '';
    item.unit = p.unit || 'szt';
    item.price_net = parseFloat(p.price_net) || 0;
    item.vat_rate = p.vat_rate ?? 23;
}

const coreOrderTotals = computed(() => {
    let net = 0, vat = 0;
    for (const it of coreOrderForm.value.items) {
        const lineNet = (parseFloat(it.quantity) || 0) * (parseFloat(it.price_net) || 0);
        const lineVat = lineNet * ((parseInt(it.vat_rate) || 0) / 100);
        net += lineNet; vat += lineVat;
    }
    return { net, vat, gross: net + vat };
});

function formatPlnAmount(n) {
    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(n || 0);
}

async function submitCoreOrder() {
    if (!props.visit?.client_id) return;
    if (!coreOrderForm.value.items.length || !coreOrderForm.value.items.some(i => i.name?.trim())) {
        alert('Dodaj przynajmniej jedną pozycję'); return;
    }
    coreOrderSubmitting.value = true;
    try {
        const r = await fetch(route('orders.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '') },
            credentials: 'same-origin',
            body: JSON.stringify({
                client_id: props.visit.client_id,
                order_date: coreOrderForm.value.order_date,
                delivery_date: coreOrderForm.value.delivery_date || null,
                status: coreOrderForm.value.status,
                notes: coreOrderForm.value.notes || null,
                items: coreOrderForm.value.items
                    .filter(i => i.name?.trim())
                    .map(i => ({
                        product_id: i.product_id || null,
                        name: i.name, sku: i.sku || null, unit: i.unit,
                        quantity: i.quantity, price_net: i.price_net, vat_rate: i.vat_rate,
                    })),
            }),
        });
        const d = await r.json();
        if (!r.ok || !d.success) {
            alert(d.message || 'Nie udało się utworzyć zamówienia');
            return;
        }
        coreOrderModalOpen.value = false;
        await loadCoreOrders();
    } catch (e) {
        alert('Błąd: ' + e.message);
    } finally {
        coreOrderSubmitting.value = false;
    }
}

const orderForm = useForm({
    products: [newOrderLine()],
    // Szczegóły zamówienia (data + godzina z wizyty, żeby Apilo nie pokazywał 00:00)
    order_date: props.visit?.visit_date ? props.visit.visit_date.split('T')[0] : new Date().toISOString().split('T')[0],
    order_time: props.visit?.visit_time || '12:00',
    platform_id: '',
    payment_type: '',
    carrier_account: '',
    // Dane zamawiającego
    customer_name: '',
    customer_nip: '',
    customer_street: '',
    customer_street_number: '',
    customer_zip: '',
    customer_city: '',
    customer_phone: '',
    customer_email: '',
    // Kopia do wysyłki
    same_address: true,
    delivery_name: '',
    delivery_street: '',
    delivery_street_number: '',
    delivery_zip: '',
    delivery_city: '',
    delivery_phone: '',
    delivery_email: '',
    inpost_parcel_point: '',
    inpost_parcel_address: '', // Adres paczkomatu z mapy (ul. X 1, 00-000 Miasto)
});

/** Ostatnie dane zamawiający + wysyłka z udanych zamówień Apilo (per klient w tej przeglądarce) */
const APILO_ORDER_ADDRESS_STORAGE_PREFIX = 'planner_apilo_last_order_addr_v1:';

const APILO_ORDER_ADDRESS_KEYS = [
    'customer_name', 'customer_nip', 'customer_street', 'customer_street_number',
    'customer_zip', 'customer_city', 'customer_phone', 'customer_email',
    'same_address',
    'delivery_name', 'delivery_street', 'delivery_street_number',
    'delivery_zip', 'delivery_city', 'delivery_phone', 'delivery_email',
    'inpost_parcel_point', 'inpost_parcel_address',
];

function apiloOrderAddressStorageKey() {
    const id = orderClient.value?.id;
    if (id != null && id !== '') {
        return APILO_ORDER_ADDRESS_STORAGE_PREFIX + String(id);
    }
    const nip = String(orderClient.value?.nip || '').replace(/\D/g, '');
    if (nip.length >= 10) {
        return APILO_ORDER_ADDRESS_STORAGE_PREFIX + 'nip:' + nip;
    }
    return null;
}

function loadSavedApiloOrderAddresses() {
    const key = apiloOrderAddressStorageKey();
    if (!key || typeof localStorage === 'undefined') {
        return null;
    }
    try {
        const raw = localStorage.getItem(key);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

function applySavedApiloOrderAddresses(saved) {
    for (const k of APILO_ORDER_ADDRESS_KEYS) {
        if (Object.prototype.hasOwnProperty.call(saved, k)) {
            orderForm[k] = saved[k];
        }
    }
}

function persistApiloOrderAddresses() {
    const key = apiloOrderAddressStorageKey();
    if (!key || typeof localStorage === 'undefined') {
        return;
    }
    try {
        const payload = {};
        for (const k of APILO_ORDER_ADDRESS_KEYS) {
            payload[k] = orderForm[k];
        }
        localStorage.setItem(key, JSON.stringify(payload));
    } catch (e) {
        console.warn('persistApiloOrderAddresses failed', e);
    }
}

/** Zapis szkicu adresów (bez składania zamówienia) — debounce + flush przy wyjściu z Apilo */
let apiloAddressDraftTimer = null;

function schedulePersistApiloOrderAddresses() {
    if (!apiloOrderAddressStorageKey()) {
        return;
    }
    clearTimeout(apiloAddressDraftTimer);
    apiloAddressDraftTimer = setTimeout(() => {
        apiloAddressDraftTimer = null;
        persistApiloOrderAddresses();
    }, 450);
}

function flushPersistApiloOrderAddresses() {
    if (apiloAddressDraftTimer) {
        clearTimeout(apiloAddressDraftTimer);
        apiloAddressDraftTimer = null;
    }
    if (apiloOrderAddressStorageKey()) {
        persistApiloOrderAddresses();
    }
}

function flushApiloOrderDraftOnUnload() {
    if (activeTab.value === 'apilo') {
        flushPersistApiloOrderAddresses();
    }
}

watch(
    () => APILO_ORDER_ADDRESS_KEYS.map((k) => orderForm[k]).join('\x1e'),
    () => {
        if (activeTab.value !== 'apilo') {
            return;
        }
        schedulePersistApiloOrderAddresses();
    }
);

watch(activeTab, (tab, prev) => {
    if (prev === 'apilo' && tab !== 'apilo') {
        flushPersistApiloOrderAddresses();
    }
});

// Czy wybrana dostawa to InPost (paczkomat lub kurier) — pokazuj pole wyboru paczkomatu
const isInPostPaczkomat = computed(() => {
    const id = orderForm.carrier_account;
    if (!id) return false;
    const carrier = apiloCarriers.value.find(c => c.id === id);
    const name = (carrier?.name || '').toLowerCase();
    return name.includes('inpost');
});

// InPost – mapa w osobnym oknie (geowidget ma błędy w SPA)
const inpostGeowidgetToken = computed(() => page.props.inpostGeowidgetToken || '');
const showInPostGeowidget = computed(() => isInPostPaczkomat.value && !!inpostGeowidgetToken.value);

function openInPostMapPopup() {
    const url = route('inpost.map');
    const w = window.open(url, 'inpost-map', 'width=900,height=700,scrollbars=yes');
    if (!w) return;
    const handler = (e) => {
        if (e.origin !== window.location.origin || e.data?.type !== 'inpost-point-selected') return;
        const name = e.data?.name;
        const address = e.data?.address;
        if (name) orderForm.inpost_parcel_point = String(name).trim().toUpperCase();
        if (address) orderForm.inpost_parcel_address = String(address).trim();
        window.removeEventListener('message', handler);
    };
    window.addEventListener('message', handler);
}

// Opcje z Apilo (platformy, płatności, dostawy)
const apiloPlatforms = ref([]);
const apiloPaymentTypes = ref([]);
const apiloCarriers = ref([]);
const apiloOptionsLoading = ref(false);
const apiloOptionsLoaded = ref(false);

async function loadApiloOptions() {
    if (apiloOptionsLoaded.value || apiloOptionsLoading.value) return;
    apiloOptionsLoading.value = true;
    try {
        const optionsUrl = route('apilo.order-options');
        const response = await fetch(optionsUrl, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            console.warn('Apilo options HTTP error', response.status);
            return; // nie ustawiaj loaded=true → spróbuje znów przy następnym otwarciu zakładki
        }
        const data = await response.json();
        apiloPlatforms.value = data.platforms || [];
        apiloPaymentTypes.value = data.payment_types || [];
        apiloCarriers.value = data.carriers || [];

        // Mark loaded TYLKO jeśli faktycznie mamy dane. Inaczej — Apilo padło, retry przy
        // następnym otwarciu (np. po cache:clear lub gdy Apilo wróci do działania).
        if (apiloPaymentTypes.value.length > 0) {
            apiloOptionsLoaded.value = true;
        } else {
            console.warn('Apilo zwrócił pustą listę typów płatności — spróbuję znów przy następnym otwarciu');
        }

        const defs = data.defaults || {};

        // Kanał: przypisany użytkownikowi w ustawieniach, inaczej pierwszy z listy
        if (apiloPlatforms.value.length) {
            const wanted = defs.platform_id;
            const plat =
                wanted != null &&
                wanted !== '' &&
                apiloPlatforms.value.find((p) => String(p.id) === String(wanted));
            if (plat) {
                orderForm.platform_id = plat.id;
            } else if (orderForm.platform_id === '' || orderForm.platform_id == null) {
                orderForm.platform_id = apiloPlatforms.value[0].id;
            }
        }

        // Płatność: domyślnie pobranie (pierwszy typ rozpoznany po nazwie), inaczej pierwszy z listy
        if (apiloPaymentTypes.value.length) {
            const codId = defs.payment_type_id;
            const codPt =
                codId != null &&
                codId !== '' &&
                apiloPaymentTypes.value.find((p) => String(p.id) === String(codId));
            if (codPt) {
                orderForm.payment_type = codPt.id;
            } else if (orderForm.payment_type === '' || orderForm.payment_type == null) {
                orderForm.payment_type = apiloPaymentTypes.value[0].id;
            }
        }

        // Dostawa: domyślnie "Inpost (Kurier do 1000)" jeśli jest na liście — inaczej defs z backendu lub pierwszy
        if (apiloCarriers.value.length && (orderForm.carrier_account === '' || orderForm.carrier_account == null)) {
            const inpostDefault = apiloCarriers.value.find((c) => {
                const name = String(c.name || '').toLowerCase();
                return name.includes('inpost') && (name.includes('1000') || name.includes('kurier do 1000'));
            });
            const defaultCarrier = defs.carrier_account;
            const defsCarrier =
                defaultCarrier != null &&
                defaultCarrier !== '' &&
                apiloCarriers.value.find((c) => String(c.id) === String(defaultCarrier));

            if (inpostDefault) {
                orderForm.carrier_account = inpostDefault.id;
            } else if (defsCarrier) {
                orderForm.carrier_account = defsCarrier.id;
            } else {
                orderForm.carrier_account = apiloCarriers.value[0].id;
            }
        }
    } catch (e) {
        console.error('Error loading Apilo options:', e);
    } finally {
        apiloOptionsLoading.value = false;
    }
}

// Data/godzina z wizyty; zamawiający + wysyłka z ostatniego zapisanego zamówienia albo z karty klienta
function prefillOrderForm() {
    const v = props.visit;
    if (v) {
        orderForm.order_date = v.visit_date ? v.visit_date.split('T')[0] : orderForm.order_date;
        orderForm.order_time = v.visit_time || '12:00';
    }
    const saved = loadSavedApiloOrderAddresses();
    if (saved) {
        applySavedApiloOrderAddresses(saved);
        return;
    }
    const c = orderClient.value;
    orderForm.customer_name = c.name || '';
    orderForm.customer_nip = c.nip || '';
    orderForm.customer_street = c.street || c.address || '';
    orderForm.customer_street_number = c.street_number || '';
    orderForm.customer_zip = c.postal_code || '';
    orderForm.customer_city = c.city || '';
    orderForm.customer_phone = c.phone || '';
    orderForm.customer_email = c.email || '';
    orderForm.same_address = true;
    orderForm.delivery_name = c.name || '';
    orderForm.delivery_street = c.street || c.address || '';
    orderForm.delivery_street_number = c.street_number || '';
    orderForm.delivery_zip = c.postal_code || '';
    orderForm.delivery_city = c.city || '';
    orderForm.delivery_phone = c.phone || '';
    orderForm.delivery_email = c.email || '';
    orderForm.inpost_parcel_point = '';
    orderForm.inpost_parcel_address = '';
}

function copyToDelivery() {
    orderForm.delivery_name = orderForm.customer_name;
    orderForm.delivery_street = orderForm.customer_street;
    orderForm.delivery_street_number = orderForm.customer_street_number;
    orderForm.delivery_zip = orderForm.customer_zip;
    orderForm.delivery_city = orderForm.customer_city;
    orderForm.delivery_phone = orderForm.customer_phone;
    orderForm.delivery_email = orderForm.customer_email;
}

// Produkty z Apilo
const apiloProducts = ref([]);
const productsLoading = ref(false);
const productsLoaded = ref(false);
const productSearchQuery = ref({});  // { [index]: string }
const productDropdownOpen = ref({}); // { [index]: boolean }

async function loadApiloProducts(forceRefresh = false) {
    if (productsLoaded.value && !forceRefresh) return;
    productsLoading.value = true;
    try {
        const refresh = forceRefresh ? '1' : '0';
        const url = route('calendar.products') + '?refresh=' + refresh;
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (data.success) {
            apiloProducts.value = cloneApiloCatalogFromApi(data.products);
        }
    } catch (error) {
        console.error('Error loading Apilo products:', error);
    } finally {
        productsLoading.value = false;
        productsLoaded.value = true;
    }
}

function getFilteredProducts(index) {
    const query = (productSearchQuery.value[index] || '').toLowerCase().trim();
    if (!query) return apiloProducts.value.slice(0, 20); // pokaż pierwsze 20 gdy brak zapytania
    return apiloProducts.value.filter(p => 
        (p.name || '').toLowerCase().includes(query) ||
        (p.sku || '').toLowerCase().includes(query) ||
        (p.ean || '').toLowerCase().includes(query)
    ).slice(0, 20);
}

function onProductSearch(index) {
    productDropdownOpen.value[index] = true;
    // Załaduj produkty jeśli jeszcze nie pobrane
    if (!productsLoaded.value && !productsLoading.value) {
        loadApiloProducts();
    }
}

/** VAT z Apilo bywa stringiem „8.00” — select ma :value liczbowe, bez tego zostaje 23%. */
function normalizeApiloVatForSelect(rate) {
    if (rate === null || rate === undefined || rate === '') {
        return 23;
    }
    const n = Number(rate);
    if (!Number.isFinite(n)) {
        return 23;
    }
    const r = Math.round(n);
    if (VAT_RATES.includes(r)) {
        return r;
    }
    return VAT_RATES.reduce((best, x) => (Math.abs(x - n) < Math.abs(best - n) ? x : best), 23);
}

/** Cena brutto z katalogu (liczba / string PL). Jeśli brutto 0 a jest netto + VAT — przelicz. */
function parseApiloCatalogPrices(catalogRow) {
    const parseMoney = (v) => {
        if (typeof v === 'number' && Number.isFinite(v)) {
            return v;
        }
        const s = String(v ?? '').replace(/\s/g, '').replace(',', '.');
        const x = parseFloat(s);
        return Number.isFinite(x) ? x : 0;
    };
    const vat = normalizeApiloVatForSelect(catalogRow.tax_rate);
    let brutto = parseMoney(catalogRow.price);
    const netto = parseMoney(catalogRow.price_net);
    if ((brutto <= 0 || !Number.isFinite(brutto)) && netto > 0) {
        brutto = vat > 0 ? round2(netto * (1 + vat / 100)) : netto;
    }
    return { brutto: round2(brutto), tax_rate: vat };
}

function selectProduct(index, catalogRow) {
    const { brutto, tax_rate } = parseApiloCatalogPrices(catalogRow);
    const prev = orderForm.products[index];
    const pid = catalogRow.id;
    // Cały obiekt linii na nowo (pewna reaktywność useForm + brak „przyklejonych” pól)
    orderForm.products.splice(index, 1, {
        _apiloRowKey: prev._apiloRowKey,
        product_id: pid != null && pid !== '' ? String(pid) : '',
        name: catalogRow.name || '',
        quantity: prev.quantity ?? 1,
        price: brutto,
        tax_rate,
    });
    productSearchQuery.value[index] = catalogRow.name || '';
    productDropdownOpen.value[index] = false;
}

function closeProductDropdown(index) {
    // Małe opóźnienie żeby kliknięcie w listę zadziałało
    setTimeout(() => {
        productDropdownOpen.value[index] = false;
    }, 200);
}

function clearProductSelection(index) {
    orderForm.products[index].product_id = '';
    orderForm.products[index].name = '';
    orderForm.products[index].price = 0;
    orderForm.products[index].tax_rate = 23;
    productSearchQuery.value[index] = '';
}

// Formularz nowego klienta
const showNewClientForm = ref(false);
const newClientForm = useForm({
    name: '',
    short_name: '',
    type: 'company',
    nip: '',
    regon: '',
    email: '',
    phone: '',
    address: '',
    street: '',
    building_number: '',
    apartment_number: '',
    city: '',
    postal_code: '',
});
const gusLoadingNewClient = ref(false);
const gusErrorNewClient = ref('');
const gusSuccessNewClient = ref('');

// Obsługa emaili/ofert
const selectedTemplateId = ref('');
const selectedMailConfigId = ref('');
const useCustomMessage = ref(false);
const customSubject = ref('');
const customHtmlContent = ref('');
const templateSubjectOverride = ref(''); // Opcjonalna zmiana tematu przy wysyłce z szablonu
const recipientEmailOverride = ref(''); // Opcjonalna zmiana adresu odbiorcy (gdy puste = client.email)
const emailAttachments = ref([]); // Załączniki do maila (File[])
const selectedPriceListId = ref(''); // Cennik do dołączenia jako PDF
const emailPreview = ref(null);
const isLoadingPreview = ref(false);
const isSendingEmail = ref(false);
const showEmailPreview = ref(false);

const selectedTemplate = computed(() => {
    return props.emailTemplates.find(t => t.id === selectedTemplateId.value);
});

const effectiveRecipientEmail = computed(() => {
    const over = recipientEmailOverride.value?.trim();
    return over || client.value?.email || '';
});

function onEmailAttachmentsChange(e) {
    const files = Array.from(e.target.files || []);
    const remaining = 10 - emailAttachments.value.length;
    const toAdd = files.slice(0, remaining);
    emailAttachments.value = [...emailAttachments.value, ...toAdd];
    e.target.value = '';
}
function removeEmailAttachment(index) {
    emailAttachments.value = emailAttachments.value.filter((_, i) => i !== index);
}

const hasMailConfig = computed(() => {
    return props.mailConfigs.length > 0 && props.mailConfigs.some(c => c.is_verified);
});

const defaultMailConfig = computed(() => {
    return props.mailConfigs.find(c => c.is_default) || props.mailConfigs[0];
});

const client = computed(() => props.visit?.client || {});
const localClients = ref([...props.clients]);

// Wyszukiwarka klienta
const clientSearchOpen = ref(false);
const clientSearchQuery = ref('');
const clientSearchResults = ref([]);
const clientSearchLoading = ref(false);
const clientSearchInputRef = ref(null);
let clientSearchDebounce = null;

const selectedClientDisplay = computed(() => {
    const id = form.client_id?.toString();
    if (!id) return '';
    const c = localClients.value.find(x => x.id == id) || clientSearchResults.value.find(x => x.id == id);
    return c ? (c.short_name || c.name) : '';
});

async function fetchClientSearch() {
    clientSearchLoading.value = true;
    try {
        const url = route('clients.search') + '?q=' + encodeURIComponent(clientSearchQuery.value) + '&limit=25';
        const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await r.json();
        clientSearchResults.value = data.clients || [];
    } catch {
        clientSearchResults.value = [];
    } finally {
        clientSearchLoading.value = false;
    }
}

function onClientSearchInput() {
    clearTimeout(clientSearchDebounce);
    clientSearchDebounce = setTimeout(() => fetchClientSearch(), 200);
}

function openClientSearch() {
    clientSearchOpen.value = true;
    clientSearchQuery.value = '';
    clientSearchResults.value = [];
    fetchClientSearch();
    nextTick(() => clientSearchInputRef.value?.focus());
}

function selectClient(c) {
    form.client_id = c.id;
    if (!localClients.value.some(x => x.id === c.id)) {
        localClients.value.push({ id: c.id, name: c.full_name || c.name, short_name: c.name });
    }
    clientSearchOpen.value = false;
    clientSearchQuery.value = '';
    // initClientForm wywoła się automatycznie przez watch form.client_id (loadClientFormFresh)
    // — fetcha pełne dane z API zamiast polegać na okrojonych danych z search ({id, name, full_name}).
}

/**
 * Załaduj pełne dane klienta z API i wypełnij clientForm.
 * Krytyczne: po zmianie form.client_id, clientForm musi mieć dane TEGO klienta (a nie poprzedniego).
 * Bez tego: zapisywaliśmy NIP klienta A do klienta B → unique constraint violation.
 */
async function loadClientFormFresh(clientId) {
    if (!clientId) {
        clientForm.value = null;
        return;
    }
    try {
        const r = await fetch(route('clients.show-json', clientId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!r.ok) {
            // 403/404 → fallback do props.visit.client jeśli pasuje
            if (props.visit?.client?.id == clientId) initClientForm();
            return;
        }
        const data = await r.json();
        if (data?.success && data.client) {
            initClientForm(data.client);
        }
    } catch (e) {
        console.error('loadClientFormFresh error:', e);
    }
}

function closeClientSearch() {
    clientSearchOpen.value = false;
}

function detachClient() {
    if (window.confirm('Czy na pewno chcesz odpiąć klienta od tego spotkania?')) {
        form.client_id = '';
    }
}

onMounted(() => {
    syncDescriptionEditorFromForm();
    loadClientData(); // Faktury i zamówienia w tle – nie blokuje wyświetlenia opisu
    if (props.visit?.client?.id) initClientForm();
    if (props.visit?.id && loadDraftFromStorage()) {
        hasDraftRestore.value = true;
    }
    window.addEventListener('beforeunload', saveDraftToStorage);
    window.addEventListener('beforeunload', flushApiloOrderDraftOnUnload);
    document.addEventListener('visibilitychange', onVisibilityChange);
});

function onVisibilityChange() {
    if (document.visibilityState === 'hidden') {
        saveDraftToStorage();
        if (activeTab.value === 'apilo') {
            flushPersistApiloOrderAddresses();
        }
    }
}

/** Cichy zapis wizyty na serwer (fetch PUT). Zwraca Promise<boolean>. */
async function silentSave() {
    if (!props.visit?.id) return false;
    if (!showDescriptionSource.value && descriptionEditorRef.value) {
        form.description = descriptionEditorRef.value.innerHTML || '';
    }
    const csrfToken = document.cookie
        .split('; ')
        .find(r => r.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
    try {
        const url = route('calendar.update', props.visit.id);
        const r = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({
                _method: 'PUT',
                title: form.title,
                client_id: form.client_id === '' ? null : (form.client_id || props.visit?.client_id || null),
                visit_date: form.visit_date,
                visit_time: form.visit_time || null,
                deadline: form.visit_date || form.deadline,
                notes: form.notes,
                description: form.description,
                phones: (form.phones || []).map(p => String(p || '').trim()).filter(Boolean),
                link: form.link,
                website: form.website,
                status: form.status,
                color: form.color,
                user_id: form.user_id,
                order_value: form.order_value,
            }),
        });
        if (r.ok) { clearDraft(); return true; }
    } catch (_) {}
    saveDraftToStorage();
    return false;
}

defineExpose({ silentSave });

onBeforeUnmount(() => {
    saveDraftToStorage();
    if (draftSaveTimeout) clearTimeout(draftSaveTimeout);
    if (activeTab.value === 'apilo') {
        flushPersistApiloOrderAddresses();
    } else if (apiloAddressDraftTimer) {
        clearTimeout(apiloAddressDraftTimer);
        apiloAddressDraftTimer = null;
    }
    window.removeEventListener('beforeunload', saveDraftToStorage);
    window.removeEventListener('beforeunload', flushApiloOrderDraftOnUnload);
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

// Zapis szkicu przy zmianie formularza (przed deployem/odświeżeniem)
watch([() => form.description, () => form.notes, () => form.title, () => form.link, () => form.website, () => form.phones], () => {
    scheduleDraftSave();
}, { deep: true });

// KRYTYCZNE: po zmianie wybranego klienta przeładuj clientForm świeżymi danymi z API.
// Bez tego: select WENA z pickera, ale clientForm dalej ma dane Łucjana z poprzedniego widoku
// → save próbuje zapisać NIP Łucjana do WENY → unique constraint conflict.
watch(() => form.client_id, (newId, oldId) => {
    if (newId === oldId) return;
    loadClientFormFresh(newId);
});


watch(selectedTemplateId, () => {
    templateSubjectOverride.value = '';
});

watch(() => props.clients, (newClients) => {
    localClients.value = [...newClients];
}, { deep: true });

watch([() => props.visit?.id, showDescriptionSource], () => {
    syncDescriptionEditorFromForm();
});

watch(() => props.visit?.id, (newId, oldId) => {
    recipientEmailOverride.value = '';
    visitSaveErrorRef.value = '';
    visitSaveErrorMessage.value = '';
    // Reset form gdy zmienia się wizyta (np. przełączenie między panelami)
    if (newId && newId !== oldId && props.visit) {
        if (props.visit.client?.id) initClientForm();
        const v = props.visit;
        form.title = v.title || '';
        form.client_id = v.client_id || '';
        form.visit_date = v.visit_date ? v.visit_date.split('T')[0] : '';
        form.visit_time = v.visit_time ? String(v.visit_time).substring(0, 5) : '';
        form.deadline = v.deadline ? String(v.deadline).split('T')[0] : (v.visit_date ? v.visit_date.split('T')[0] : '');
        form.notes = v.notes || '';
        form.description = v.description || '';
        form.link = v.link || '';
        form.website = v.website || '';
        form.status = v.status_id?.toString() || v.status || '';
        form.color = v.color || '#3B82F6';
        form.user_id = v.user_id || '';
        form.order_value = v.order_value || '';
        form.clearErrors();
        nextTick(() => syncDescriptionEditorFromForm());

        // Apilo: linie zamówienia i cache listy produktów są per-wizyta — inaczej zostają ceny z poprzedniego klienta
        orderForm.products = [newOrderLine()];
        productSearchQuery.value = {};
        productDropdownOpen.value = {};
        apiloProducts.value = [];
        productsLoaded.value = false;
        apiloOptionsLoaded.value = false;
        orderError.value = '';
    }
});

watch(activeTab, (tab) => {
    if (tab === 'details') {
        syncDescriptionEditorFromForm();
    }
    if (tab === 'invoices') {
        if (props.visit?.client?.nip && !nipForm.nip) {
            nipForm.nip = String(props.visit.client.nip).replace(/\D/g, '').slice(0, 10);
        }
        if (nipForm.nip && String(nipForm.nip).replace(/\D/g, '').length >= 10 && invoices.value.length === 0 && !isLoading.value) {
            fetchInvoicesByNip();
        }
    }
});

// Synchronizacja: zmiana daty spotkania = deadline (wizyty całodniowe)
watch(() => form.visit_date, (d) => {
    if (d) form.deadline = d;
});

async function loadClientData() {
    if (!props.visit?.id) return;
    
    isLoading.value = true;
    try {
        const response = await fetch(route('calendar.show', props.visit.id), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        invoices.value = data.invoices || [];
        orders.value = data.orders || [];
        if (props.visit?.client?.nip && !nipForm.nip) {
            nipForm.nip = String(props.visit.client.nip).replace(/\D/g, '').slice(0, 10);
        }
    } catch (error) {
        console.error('Error loading client data:', error);
    } finally {
        isLoading.value = false;
    }
}

const invoiceDetailsCache = ref({});
const loadingInvoiceId = ref(null);
const trackingLoadingOrderId = ref(null);

async function openOrderTracking(orderId) {
    if (!orderId || trackingLoadingOrderId.value) return;
    trackingLoadingOrderId.value = orderId;
    try {
        const url = route('apilo.order-tracking', { orderId });
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        const links = data.links || [];
        if (links.length > 0) {
            window.open(links[0].url, '_blank', 'noopener,noreferrer');
        } else {
            console.warn('Brak linków do śledzenia dla zamówienia', orderId);
        }
    } catch (error) {
        console.error('Błąd pobierania trackingu:', error);
    } finally {
        trackingLoadingOrderId.value = null;
    }
}

async function fetchInvoicesByNip() {
    const nip = String(nipForm.nip || '').replace(/\D/g, '');
    if (nip.length < 10) return;
    isLoading.value = true;
    try {
        const response = await fetch(route('fakturownia.invoices-by-nip') + '?nip=' + encodeURIComponent(nip), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        invoices.value = data.invoices || [];
    } catch (error) {
        console.error('Error fetching invoices:', error);
        invoices.value = [];
    } finally {
        isLoading.value = false;
    }
}

function retryInvoiceDetail(invoiceId) {
    invoiceDetailsCache.value[invoiceId] = null;
    toggleInvoicePositions(invoiceId);
}

async function toggleInvoicePositions(invoiceId) {
    if (loadingInvoiceId.value === invoiceId) return;
    if (invoiceDetailsCache.value[invoiceId] && !invoiceDetailsCache.value[invoiceId]?.error) {
        invoiceDetailsCache.value[invoiceId] = null;
        return;
    }
    loadingInvoiceId.value = invoiceId;
    try {
        const response = await fetch(route('fakturownia.invoice-detail', invoiceId), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        invoiceDetailsCache.value[invoiceId] = data;
    } catch (error) {
        console.error('Error loading invoice:', error);
        invoiceDetailsCache.value[invoiceId] = { error: error.message };
    } finally {
        loadingInvoiceId.value = null;
    }
}

// ==================== KARTA KLIENTA ====================
const defaultProfile = {
    venue: { city_size: '', location: '', venue_type: '', venue_size: '', kitchen_staff: '', total_staff: '', years_in_business: '', venue_birthday: '' },
    concept: { specialty: '', cuisine: '', price_level: '' },
    sales: { delivery: false, delivery_volume: '', platforms: [], rush_hours: '' },
    customers: { profiles: [] },
    kitchen: { own_production: false, uses_semi_finished: false, suppliers: '' },
    organization: { decision_maker: '', ordering_person: '', ordering_frequency: '' },
    mental: { personality: [], approach_notes: '' },
    potential: { promo_activities: '', media_quality: '', current_products: '', menu_changes: false, open_to_tests: false, notes: '' },
    discounts: '',
    payment_form: '',
    delivery_info: '',
};

function mergeProfile(saved) {
    if (!saved) return JSON.parse(JSON.stringify(defaultProfile));
    const merged = JSON.parse(JSON.stringify(defaultProfile));
    for (const section in merged) {
        if (saved[section] === undefined) continue;
        if (typeof merged[section] !== 'object' || merged[section] === null || Array.isArray(merged[section])) {
            merged[section] = saved[section];
        } else {
            for (const key in merged[section]) {
                if (saved[section][key] !== undefined && saved[section][key] !== null) {
                    merged[section][key] = saved[section][key];
                }
            }
        }
    }
    return merged;
}

const clientForm = ref(null);
const clientCardSaving = ref(false);
const clientCardSuccess = ref(false);
const openProfileSections = ref({});

function initClientForm(clientOverride) {
    clientCardNipError.value = '';
    const c = clientOverride ?? props.visit?.client ?? {};
    clientForm.value = {
        type: c.type || 'company',
        name: c.name || '',
        short_name: c.short_name || '',
        nip: c.nip || '',
        regon: c.regon || '',
        email: c.email || '',
        phone: c.phone || '',
        phone2: c.phone2 || '',
        website: c.website || '',
        street: c.street || '',
        building_number: c.building_number || '',
        apartment_number: c.apartment_number || '',
        postal_code: c.postal_code || '',
        city: c.city || '',
        country: c.country || 'Polska',
        contact_person: c.contact_person || '',
        contact_email: c.contact_email || '',
        contact_phone: c.contact_phone || '',
        status: c.status || 'active',
        notes: c.notes || '',
        birthday: c.birthday || '',
        profile: mergeProfile(c.profile),
    };
}

function toggleProfileSection(section) {
    openProfileSections.value[section] = !openProfileSections.value[section];
}

const clientCardError = ref('');

/** @returns {Promise<boolean>} true gdy zapis OK lub pominięty; false gdy błąd API */
async function saveClientCard(silent = false) {
    const clientId = form.client_id || props.visit?.client?.id;
    if (!clientId || !clientForm.value) {
        return true;
    }

    const errRef = makeErrorRef('CLI');
    clientCardSaving.value = true;
    clientCardSuccess.value = false;
    clientCardError.value = '';
    try {
        const csrfToken = document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        const response = await fetch(route('calendar.update-client', clientId), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify(clientForm.value),
        });

        // Obsłuż wygaśniętą sesję (419) lub inną odpowiedź HTML zamiast JSON
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.status === 419 || response.status === 401) {
                clientCardError.value = 'Sesja wygasła — odśwież stronę (Ctrl+R) i zaloguj się ponownie.';
                return false;
            }
            clientCardError.value = `Serwer zwrócił nieprawidłową odpowiedź (HTTP ${response.status}) · Kod: ${errRef}`;
            return false;
        }

        const data = await response.json();
        if (data.success) {
            if (data.client && props.visit?.client) {
                Object.assign(props.visit.client, data.client);
            }
            clientCardSuccess.value = true;
            setTimeout(() => clientCardSuccess.value = false, 3000);
            if (!silent) emit('refresh');
            return true;
        }
        if (data.errors) {
            clientCardError.value =
                Object.values(data.errors)
                    .flat()
                    .join(', ') + ` · Kod: ${errRef}`;
        } else if (data.message) {
            clientCardError.value = `${data.message} · Kod: ${errRef}`;
        } else {
            clientCardError.value = `Nie udało się zapisać danych (HTTP ${response.status}) · Kod: ${errRef}`;
        }
        return false;
    } catch (error) {
        clientCardError.value = `Błąd połączenia: ${error.message} · Kod: ${makeErrorRef('NET')}`;
        return false;
    } finally {
        clientCardSaving.value = false;
    }
}

function minimizeVisit() {
    emit('minimize');
}

async function saveAndStay() {
    if (!showDescriptionSource.value && descriptionEditorRef.value) {
        form.description = descriptionEditorRef.value.innerHTML || '';
    }
    if (activeTab.value === 'apilo') {
        flushPersistApiloOrderAddresses();
    }
    isSaving.value = true;
    try {
        const ok = await silentSave();
        if (!ok) {
            alert('Błąd zapisu wizyty.');
            return;
        }
        if (form.client_id && clientForm.value) {
            const clientOk = await saveClientCard(true);
            if (!clientOk) {
                activeTab.value = 'client_card';
            }
        }
    } finally {
        isSaving.value = false;
    }
}

async function saveVisit() {
    // Szkic Apilo: natychmiastowy zapis (debounce mógł jeszcze nie pisać w localStorage)
    if (activeTab.value === 'apilo') {
        flushPersistApiloOrderAddresses();
    }
    // Przed zapisem: zsynchronizuj contenteditable → form.description (gdy użytkownik edytował w trybie WYSIWYG)
    if (!showDescriptionSource.value && descriptionEditorRef.value) {
        form.description = descriptionEditorRef.value.innerHTML || '';
    }
    form.deadline = form.visit_date || form.deadline;
    if (form.visit_time === '') {
        form.visit_time = null;
    }

    visitSaveErrorRef.value = '';
    visitSaveErrorMessage.value = '';
    isSaving.value = true;

    // 1. NAJPIERW zapisz kartę klienta (fetch) — przed Inertia redirect
    if (form.client_id && clientForm.value) {
        const clientOk = await saveClientCard(true);
        if (!clientOk) {
            activeTab.value = 'client_card';
            isSaving.value = false;
            return;
        }
    }

    // 2. POTEM zapisz wizytę (Inertia form.put — powoduje redirect)
    const newVisitDate = form.visit_date;
    form.put(route('calendar.update', props.visit.id), {
        preserveScroll: true,
        onSuccess: () => {
            visitSaveErrorRef.value = '';
            visitSaveErrorMessage.value = '';
            clearDraft();
            emit('refresh', newVisitDate);
            emit('close');
        },
        onError: (errors) => {
            visitSaveErrorRef.value = makeErrorRef('VIS');
            const keys = errors && typeof errors === 'object' ? Object.keys(errors) : [];
            if (keys.length === 0) {
                visitSaveErrorMessage.value =
                    'Serwer nie zwrócił szczegółów błędu. Możliwy problem z sesją (odśwież stronę), siecią lub błąd aplikacji.';
            } else {
                visitSaveErrorMessage.value = '';
            }
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
}

function confirmDelete() {
    showDeleteModal.value = true;
}

function deleteVisit() {
    isDeleting.value = true;
    router.delete(route('calendar.destroy', props.visit.id), {
        onSuccess: () => {
            clearDraft();
            showDeleteModal.value = false;
            emit('close');
            emit('refresh');
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function restoreVisit() {
    isDeleting.value = true;
    router.post(route('calendar.restore', props.visit.id), {}, {
        onSuccess: () => {
            clearDraft();
            emit('close');
            emit('refresh');
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function confirmForceDelete() {
    showForceDeleteModal.value = true;
}

function forceDeleteVisit() {
    isDeleting.value = true;
    router.delete(route('calendar.force-delete', props.visit.id), {
        onSuccess: () => {
            clearDraft();
            showForceDeleteModal.value = false;
            emit('close');
            emit('refresh');
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

async function sendReviewRequest() {
    if (!client.value.email) {
        alert('Klient nie ma przypisanego adresu email');
        return;
    }
    activeTab.value = 'offer';
}

// Podgląd szablonu lub własnej wiadomości
async function previewEmailTemplate() {
    const hasTemplate = selectedTemplateId.value && !useCustomMessage.value;
    const hasCustom = useCustomMessage.value && customSubject.value?.trim() && customHtmlContent.value?.trim();
    if (!hasTemplate && !hasCustom) {
        alert(useCustomMessage.value ? 'Wpisz temat i treść wiadomości' : 'Wybierz szablon wiadomości');
        return;
    }
    
    isLoadingPreview.value = true;
    try {
        const body = useCustomMessage.value
            ? { subject: customSubject.value, html_content: customHtmlContent.value }
            : { template_id: selectedTemplateId.value, subject: templateSubjectOverride.value?.trim() || null };
        const response = await fetch(route('calendar.preview-email', props.visit.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify(body),
        });
        
        const data = await response.json();
        if (data.success) {
            emailPreview.value = data;
            if (hasTemplate && !templateSubjectOverride.value?.trim()) {
                templateSubjectOverride.value = data.subject || '';
            }
            showEmailPreview.value = true;
        } else {
            alert(data.message || 'Błąd podglądu');
        }
    } catch (error) {
        console.error('Błąd podglądu:', error);
        alert('Wystąpił błąd podczas generowania podglądu');
    } finally {
        isLoadingPreview.value = false;
    }
}

// Wysyłanie emaila
async function sendEmail() {
    const hasTemplate = selectedTemplateId.value && !useCustomMessage.value;
    const hasCustom = useCustomMessage.value && customSubject.value?.trim() && customHtmlContent.value?.trim();
    if (!hasTemplate && !hasCustom) {
        alert(useCustomMessage.value ? 'Wpisz temat i treść wiadomości' : 'Wybierz szablon wiadomości');
        return;
    }
    
    const toEmail = effectiveRecipientEmail.value;
    if (!toEmail) {
        alert('Wpisz adres email odbiorcy');
        return;
    }
    
    if (!hasMailConfig.value) {
        alert('Nie masz skonfigurowanego serwera pocztowego. Przejdź do Ustawień → Serwer pocztowy');
        return;
    }
    
    if (!confirm(`Czy na pewno chcesz wysłać email do ${toEmail}?`)) {
        return;
    }
    
    isSendingEmail.value = true;
    try {
        const formData = new FormData();
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) formData.append('_token', csrf);
        if (useCustomMessage.value) {
            formData.append('subject', customSubject.value || '');
            formData.append('html_content', customHtmlContent.value || '');
        } else {
            formData.append('template_id', selectedTemplateId.value || '');
            const subj = templateSubjectOverride.value?.trim();
            if (subj) formData.append('subject', subj);
        }
        const mailCfg = selectedMailConfigId.value;
        if (mailCfg) formData.append('mail_config_id', mailCfg);
        formData.append('to_email', effectiveRecipientEmail.value);
        emailAttachments.value.forEach((f) => formData.append('attachments[]', f));
        if (selectedPriceListId.value) formData.append('price_list_id', selectedPriceListId.value);
        const response = await fetch(route('calendar.send-email', props.visit.id), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf || '',
                'Accept': 'application/json',
            },
            body: formData,
        });
        
        const data = await response.json();
        if (data.success) {
            alert('Email został wysłany pomyślnie!');
            showEmailPreview.value = false;
            emailAttachments.value = [];
        } else {
            alert(data.message || 'Błąd wysyłania emaila');
        }
    } catch (error) {
        console.error('Błąd wysyłania:', error);
        alert('Wystąpił błąd podczas wysyłania emaila');
    } finally {
        isSendingEmail.value = false;
    }
}

async function lookupNip() {
    if (!nipForm.nip || nipForm.nip.length < 10) return;

    nipLoading.value = true;
    nipResult.value = null;

    try {
        const response = await fetch(route('calendar.lookup-nip') + '?nip=' + nipForm.nip, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        if (data.success && data.existing_client) {
            nipResult.value = { ...data.existing_client, existing: true };
        } else if (data.success) {
            nipResult.value = data.data;
        } else {
            nipResult.value = { error: data.message };
        }
    } catch (error) {
        nipResult.value = { error: 'Błąd połączenia z API GUS' };
    } finally {
        nipLoading.value = false;
    }
}

async function lookupNipForClientCard() {
    if (!clientForm.value?.nip || String(clientForm.value.nip).replace(/\D/g, '').length < 10) return;
    const nip = String(clientForm.value.nip).replace(/\D/g, '').slice(0, 10);
    clientCardNipLoading.value = true;
    clientCardNipError.value = '';
    try {
        const response = await fetch(route('calendar.lookup-nip') + '?nip=' + encodeURIComponent(nip), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        if (data.success && data.existing_client && clientForm.value) {
            const c = data.existing_client;
            form.client_id = String(c.id);
            if (!localClients.value.some(lc => lc.id === c.id)) {
                localClients.value.push({ ...c, short_name: c.short_name || c.name });
            }
            initClientForm(c);
            clientCardNipError.value = `⚠ Ten NIP ma już klienta w bazie: ${c.name}. Wybrano istniejącego klienta.`;
        } else if (data.success && data.data && clientForm.value) {
            const d = data.data;
            clientForm.value.name = d.name || clientForm.value.name;
            // Zachowaj pełną nazwę z GUS — short_name = name (nie skrót)
            if (d.name) {
                clientForm.value.short_name = d.name.length <= 100 ? d.name : '';
            }
            clientForm.value.nip = d.nip || nip;
            clientForm.value.regon = d.regon || clientForm.value.regon;
            clientForm.value.street = d.street || clientForm.value.street;
            clientForm.value.building_number = d.building_number || clientForm.value.building_number;
            clientForm.value.apartment_number = d.apartment_number || clientForm.value.apartment_number;
            clientForm.value.postal_code = d.postal_code || clientForm.value.postal_code;
            clientForm.value.city = d.city || clientForm.value.city;
        } else {
            clientCardNipError.value = data.message || 'Nie znaleziono firmy o podanym NIP';
        }
    } catch (error) {
        clientCardNipError.value = 'Błąd połączenia z API GUS';
    } finally {
        clientCardNipLoading.value = false;
    }
}

const orderGusLoading = ref(false);
const orderGusError = ref('');

async function lookupNipForOrderForm() {
    const nipRaw = orderForm.customer_nip ? String(orderForm.customer_nip).replace(/\D/g, '') : '';
    if (nipRaw.length < 10) {
        orderGusError.value = 'Wprowadź poprawny NIP (10 cyfr)';
        return;
    }
    const nip = nipRaw.slice(0, 10);
    orderGusLoading.value = true;
    orderGusError.value = '';
    try {
        // skip_existing=1 — zamówienie ma wziąć ŚWIEŻE dane z GUS, nie dane klienta z bazy
        // (zamawiający może różnić się od klienta wizyty, nie aktualizujemy klienta)
        const response = await fetch(route('calendar.lookup-nip') + '?nip=' + encodeURIComponent(nip) + '&skip_existing=1', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        if (data.success && data.data) {
            const d = data.data;
            orderForm.customer_name = d.name || orderForm.customer_name;
            orderForm.customer_nip = d.nip || nip;
            const streetNr = [d.building_number, d.apartment_number].filter(Boolean).join('/');
            orderForm.customer_street = d.street || orderForm.customer_street;
            orderForm.customer_street_number = streetNr || orderForm.customer_street_number;
            orderForm.customer_zip = d.postal_code || orderForm.customer_zip;
            orderForm.customer_city = d.city || orderForm.customer_city;
            orderGusError.value = 'ℹ Dane z GUS uzupełnione — dotyczą tylko tego zamówienia (klient wizyty nie jest nadpisywany)';
        } else {
            orderGusError.value = data.message || 'Nie znaleziono firmy o podanym NIP';
        }
    } catch (error) {
        orderGusError.value = 'Błąd połączenia z API GUS';
    } finally {
        orderGusLoading.value = false;
    }
}

function addProduct() {
    orderForm.products.push(newOrderLine());
}

function removeProduct(index) {
    orderForm.products.splice(index, 1);
    // Przebuduj productSearchQuery — usuń wpis i przesuń klucze wyższe
    const newQuery = {};
    for (const [key, val] of Object.entries(productSearchQuery.value)) {
        const k = parseInt(key);
        if (k < index) newQuery[k] = val;
        else if (k > index) newQuery[k - 1] = val;
        // k === index — pomijamy (usunięta linia)
    }
    productSearchQuery.value = newQuery;
}

function clearAllProducts() {
    orderForm.products = [newOrderLine()];
    productSearchQuery.value = {};
}

const orderTotal = computed(() => {
    return orderForm.products.reduce((sum, p) => sum + (p.quantity * p.price), 0);
});

const orderTotalNet = computed(() => {
    return orderForm.products.reduce((sum, p) => {
        const brutto = p.quantity * p.price;
        const vat = parseFloat(p.tax_rate) || 23;
        return sum + (vat > 0 ? round2(brutto / (1 + vat / 100)) : brutto);
    }, 0);
});

/** Dzisiejsza data YYYY-MM-DD w strefie lokalnej przeglądarki */
function todayLocalYmd() {
    const n = new Date();
    const y = n.getFullYear();
    const m = String(n.getMonth() + 1).padStart(2, '0');
    const d = String(n.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/** Koniec karencji: payment_to + 10 dni kalendarzowych (włącznie – tego dnia jeszcze nie blokujemy po stronie „>”) */
function wireInvoicePastOverdueGrace(invoice) {
    if (!invoice.payment_to) return false;
    const due = String(invoice.payment_to).slice(0, 10);
    const parts = due.split('-').map(Number);
    if (parts.length !== 3 || parts.some((x) => Number.isNaN(x))) return false;
    const grace = new Date(parts[0], parts[1] - 1, parts[2]);
    grace.setDate(grace.getDate() + 10);
    const geY = grace.getFullYear();
    const geM = String(grace.getMonth() + 1).padStart(2, '0');
    const geD = String(grace.getDate()).padStart(2, '0');
    const graceEnd = `${geY}-${geM}-${geD}`;
    return todayLocalYmd() > graceEnd;
}

// Zaległe faktury przelewowe: po terminie + karencja 10 dni (bez faktur pobraniowych)
function isInvoiceCod(invoice) {
    const candidates = [
        invoice.payment_type,
        invoice.payment_type_name,
        invoice.payment_kind,
        invoice.payment,
        invoice.description,
        invoice.description_footer,
        invoice.additional_description,
    ];
    for (const pt of candidates) {
        const name = typeof pt === 'string' ? pt : (pt?.name ?? '');
        if (!name) continue;
        const lower = String(name).toLowerCase();
        if (lower.includes('pobran')) return true;
        if (lower.includes('cash_on_delivery') || lower === 'cod') return true;
    }
    return false;
}
const hasOverdueWireInvoices = computed(() =>
    invoices.value.some(
        (i) => i.status !== 'paid' && !isInvoiceCod(i) && wireInvoicePastOverdueGrace(i)
    )
);

/** Nieopłacone faktury pobraniowe – blokują kolejne zamówienie za pobraniem */
const hasUnpaidCodInvoices = computed(() =>
    invoices.value.some((i) => i.status !== 'paid' && isInvoiceCod(i))
);

/** Wybrany w Apilo sposób płatności = za pobraniem — wtedy nie blokujemy zaległości */
const isSelectedApiloPaymentCod = computed(() => {
    const id = orderForm.payment_type;
    const pt = apiloPaymentTypes.value.find((p) => String(p.id) === String(id));
    const name = (pt?.name || '').toLowerCase();
    if (!name) return false;
    return (
        name.includes('pobran') ||
        name.includes('pobrani') ||
        name.includes('cod') ||
        (name.includes('gotów') && name.includes('odbior')) ||
        (name.includes('gotowk') && name.includes('odbior'))
    );
});

const orderBlockedByOverdue = computed(
    () => hasOverdueWireInvoices.value && !isSelectedApiloPaymentCod.value
);

const orderBlockedByPaymentRules = computed(
    () => orderBlockedByOverdue.value
);

const orderCreating = ref(false);
const orderError = ref('');

async function createOrder() {
    if (orderBlockedByOverdue.value) {
        orderError.value =
            'Nie można dodać zamówienia – klient ma nieopłacone faktury z przekroczonym terminem płatności (powyżej 10 dni karencji). Wybierz płatność za pobraniem albo ureguluj zaległości.';
        return;
    }
    orderCreating.value = true;
    orderError.value = '';
    try {
        // Przygotuj produkty — upewnij się że quantity i price to liczby
        const cleanProducts = orderForm.products
            .filter(p => p.name && p.name.trim())
            .map((p) => ({
                product_id: p.product_id || null,
                name: p.name.trim(),
                quantity: parseInt(p.quantity) || 1,
                price: parseFloat(p.price) || 0,
                tax_rate: p.tax_rate != null ? parseFloat(p.tax_rate) : 23,
            }));

        if (cleanProducts.length === 0) {
            orderError.value = 'Dodaj przynajmniej jeden produkt z nazwą';
            return;
        }

        // Pobierz CSRF token z cookie (Laravel/Inertia)
        const csrfToken = document.cookie
            .split('; ')
            .find(row => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        const response = await fetch(route('apilo.create-order', props.visit.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify({
                products: cleanProducts,
                order_date: orderForm.order_date,
                order_time: orderForm.order_time || null,
                platform_id: orderForm.platform_id || null,
                payment_type: orderForm.payment_type || null,
                carrier_account: orderForm.carrier_account || null,
                customer: {
                    name: orderForm.customer_name,
                    nip: orderForm.customer_nip,
                    street: orderForm.customer_street,
                    street_number: orderForm.customer_street_number,
                    zip: orderForm.customer_zip,
                    city: orderForm.customer_city,
                    phone: orderForm.customer_phone,
                    email: orderForm.customer_email,
                },
                delivery: orderForm.same_address ? {} : {
                    name: orderForm.delivery_name,
                    street: orderForm.delivery_street,
                    street_number: orderForm.delivery_street_number,
                    zip: orderForm.delivery_zip,
                    city: orderForm.delivery_city,
                    phone: orderForm.delivery_phone,
                    email: orderForm.delivery_email,
                    inpost_parcel_point: isInPostPaczkomat.value ? (orderForm.inpost_parcel_point || '').trim().toUpperCase() : null,
                    inpost_parcel_address: isInPostPaczkomat.value ? (orderForm.inpost_parcel_address || '').trim() : null,
                },
            }),
        });
        
        const data = await response.json();
        if (data.success) {
            orders.value.unshift(data.order);
            persistApiloOrderAddresses();
            orderForm.products = [newOrderLine()];
            productSearchQuery.value = {};
            productDropdownOpen.value = {};
            // Zaktualizuj wartość zamówienia
            if (data.order?.total) {
                form.order_value = data.order.total;
            }
            orderError.value = '';
        } else {
            orderError.value = data.message || 'Błąd tworzenia zamówienia';
        }
    } catch (error) {
        console.error('Error creating order:', error);
        orderError.value = 'Błąd połączenia z serwerem';
    } finally {
        orderCreating.value = false;
    }
}

function toggleNewClientForm() {
    showNewClientForm.value = !showNewClientForm.value;
    if (showNewClientForm.value) {
        gusErrorNewClient.value = '';
        gusSuccessNewClient.value = '';
    }
}

async function fetchFromGusForNewClient() {
    const nipClean = newClientForm.nip?.replace(/[^0-9]/g, '') || '';
    if (nipClean.length < 10) {
        gusErrorNewClient.value = 'Wprowadź poprawny NIP (10 cyfr)';
        gusSuccessNewClient.value = '';
        return;
    }
    gusLoadingNewClient.value = true;
    gusErrorNewClient.value = '';
    gusSuccessNewClient.value = '';
    try {
        const response = await fetch(route('clients.lookup-nip', { nip: nipClean }));
        const result = await response.json();
        if (result.success && result.existing_client) {
            const client = result.existing_client;
            if (!localClients.value.some((c) => c.id === client.id)) {
                localClients.value.push({ ...client, short_name: client.name });
            }
            form.client_id = String(client.id);
            initClientForm(client);
            showNewClientForm.value = false;
            newClientForm.reset();
            gusSuccessNewClient.value = `Znaleziono istniejącego klienta: ${client.name}`;
        } else if (result.success && result.data) {
            const data = result.data;
            if (data.name) newClientForm.name = data.name;
            // Zachowaj pełną nazwę z GUS — short_name = name (zamiast skróconej formy "SP. Z O.O.")
            // Display w aplikacji używa `short_name || name`, więc gdy short_name === name,
            // wszędzie widzimy dokładnie to co GUS zwrócił. Jeśli nazwa > 100 znaków (limit DB),
            // pozostaw puste — fallback i tak weźmie pełne `name`.
            if (data.name) {
                newClientForm.short_name = data.name.length <= 100 ? data.name : '';
            }
            if (data.regon) newClientForm.regon = data.regon;
            if (data.street) {
                newClientForm.street = data.street;
                newClientForm.address = data.street;
            }
            if (data.building_number) newClientForm.building_number = data.building_number;
            if (data.apartment_number) newClientForm.apartment_number = data.apartment_number;
            if (data.city) newClientForm.city = data.city;
            if (data.postal_code) newClientForm.postal_code = data.postal_code;
            if (data.address && !data.street) {
                const pa = parseAddressForGus(data.address);
                if (pa.street) newClientForm.street = pa.street;
                if (pa.building_number) newClientForm.building_number = pa.building_number;
                if (pa.apartment_number) newClientForm.apartment_number = pa.apartment_number;
                if (pa.postal_code) newClientForm.postal_code = pa.postal_code;
                if (pa.city) newClientForm.city = pa.city;
            }
            newClientForm.type = 'company';
            gusSuccessNewClient.value = 'Dane zostały pobrane z rejestru GUS/VAT';
        } else {
            gusErrorNewClient.value = result.message || 'Nie znaleziono firmy';
        }
    } catch (error) {
        console.error('GUS lookup error:', error);
        gusErrorNewClient.value = 'Błąd podczas pobierania danych. Sprawdź połączenie.';
    } finally {
        gusLoadingNewClient.value = false;
    }
}

function parseAddressForGus(address) {
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

async function submitNewClient() {
    newClientForm.processing = true;
    newClientForm.clearErrors();
    try {
        const csrfToken = document.cookie
            .split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];
        const res = await fetch(route('clients.store'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify(newClientForm.data()),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data?.success === false) {
            if (res.status === 422 && data?.errors) {
                Object.entries(data.errors).forEach(([k, v]) => {
                    newClientForm.setError(k, Array.isArray(v) ? v[0] : String(v));
                });
            } else {
                alert(data?.message || 'Nie udało się dodać klienta.');
            }
            return;
        }
        const c = data.client;

        // Backend zwraca {existing: true} gdy znaleziono klienta z tym samym NIP-em.
        // BEZ tej obsługi user widziałby dane istniejącego klienta zamiast swojego —
        // wyglądało jak "nazwa się zmieniła, NIP się nie zapisał". Pytamy go.
        if (c && data.existing) {
            const enteredName = newClientForm.name?.trim() || '';
            const msg = `Klient z tym NIP już istnieje w bazie:\n\n  „${c.name}"\n\nWpisana nazwa: „${enteredName}"\n\nKliknij OK żeby przypiąć istniejącego klienta do wizyty.\nKliknij Anuluj żeby edytować NIP / nazwę.`;
            if (!window.confirm(msg)) {
                newClientForm.processing = false;
                return;
            }
        }

        if (c) {
            // Po `existing` zaufaj backendowi (nie nadpisuj polami z formularza); inaczej połącz z formem.
            const enriched = data.existing ? {
                id: c.id,
                name: c.name,
                short_name: c.name,
                type: c.type,
                nip: c.nip,
            } : {
                id: c.id,
                name: c.name,
                short_name: newClientForm.short_name || c.name,
                type: c.type,
                nip: c.nip,
                regon: newClientForm.regon,
                email: newClientForm.email,
                phone: newClientForm.phone,
                city: newClientForm.city,
                street: newClientForm.street,
                building_number: newClientForm.building_number,
                apartment_number: newClientForm.apartment_number,
                postal_code: newClientForm.postal_code,
            };
            if (!localClients.value.some(x => x.id === c.id)) {
                localClients.value.push(enriched);
            }
            form.client_id = c.id;
            initClientForm(enriched);
        }
        showNewClientForm.value = false;
        newClientForm.reset();
    } catch (e) {
        console.error('submitNewClient error:', e);
        alert('Błąd sieci — nie udało się dodać klienta.');
    } finally {
        newClientForm.processing = false;
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(value || 0);
}

/** Wartości techniczne z API Fakturowni → czytelna etykieta PL */
const FAKTUROWNIA_PAYMENT_LABELS = {
    transfer: 'Przelew',
    bank_transfer: 'Przelew',
    przelew: 'Przelew',
    wire_transfer: 'Przelew',
    cash_on_delivery: 'Za pobraniem',
    cod: 'Za pobraniem',
    pobranie: 'Za pobraniem',
    delivery: 'Za pobraniem',
    cash: 'Gotówka',
    gotowka: 'Gotówka',
    card: 'Karta płatnicza',
    payment_card: 'Karta płatnicza',
    credit_card: 'Karta płatnicza',
    blik: 'BLIK',
    payu: 'PayU',
    paypal: 'PayPal',
    przelewy24: 'Przelewy24',
    tpay: 'Tpay',
    stripe: 'Stripe',
    compensation: 'Kompensata',
    barter: 'Barter',
    loan: 'Pożyczka',
    leasing: 'Leasing',
    other: 'Inna',
    split_payment: 'Płatność podzielona',
    installment: 'Raty',
    // Zwykle własny wpis w słowniku Fakturowni (Ustawienia → sposoby płatności), nie „wyłączone”
    off: 'OFF (własny typ z Fakturowni)',
};

function humanizeFakturowniaPayment(raw) {
    if (raw == null || raw === '') return '';
    const s = String(raw).trim();
    if (!s) return '';
    const key = s.toLowerCase().replace(/-/g, '_');
    if (FAKTUROWNIA_PAYMENT_LABELS[key]) {
        return FAKTUROWNIA_PAYMENT_LABELS[key];
    }
    // Już po polsku / zwykły opis — zostaw
    if (/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/.test(s) || /\s/.test(s)) {
        return s;
    }
    // Ostatnia deska: slug bez tłumaczenia
    if (key.includes('_')) {
        return key
            .split('_')
            .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
            .join(' ');
    }
    return s;
}

/** Etykieta metody płatności z listy faktur Fakturowni */
function invoicePaymentLabel(inv) {
    if (!inv) return '';
    const candidates = [
        inv.payment_type_name,
        inv.payment_type,
        inv.payment_kind,
        inv.payment,
    ];
    for (const pt of candidates) {
        if (typeof pt === 'string' && pt.trim()) {
            return humanizeFakturowniaPayment(pt.trim());
        }
        if (pt && typeof pt === 'object' && pt.name) {
            return humanizeFakturowniaPayment(String(pt.name).trim());
        }
    }
    return '';
}

function productPriceNet(product) {
    const brutto = parseFloat(product.price) || 0;
    const vat = parseFloat(product.tax_rate) || 23;
    return vat > 0 ? round2(brutto / (1 + vat / 100)) : brutto;
}

function updatePriceFromNet(product, event) {
    const net = parseFloat(event.target?.value) || 0;
    const vat = parseFloat(product.tax_rate) || 23;
    product.price = vat > 0 ? round2(net * (1 + vat / 100)) : net;
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('pl-PL');
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const invoiceStatusColors = {
    paid: 'bg-green-100 text-green-800',
    sent: 'bg-yellow-100 text-yellow-800',
    pending: 'bg-orange-100 text-orange-800',
    draft: 'bg-gray-100 text-gray-800',
};

const invoiceStatusLabels = {
    paid: 'Opłacona',
    sent: 'Wysłana',
    pending: 'Oczekuje',
    draft: 'Szkic',
    completed: 'Zrealizowane',
    shipped: 'Wysłane',
    processing: 'W realizacji',
    cancelled: 'Anulowane',
};
</script>

<template>
    <div :class="floatingMode ? 'client-modal-embedded' : 'modal-overlay'">
        <div class="client-modal">
            <!-- Header z nazwą klienta -->
            <div class="modal-header">
                <h2 class="text-xl font-semibold text-foreground">
                    {{ client.name || form.title || 'Nowa wizyta' }}
                </h2>
                <div v-if="!floatingMode" class="flex items-center gap-1">
                    <button @click="minimizeVisit" class="close-btn" title="Minimalizuj do paska">
                        <Icons name="chevron-right" class="w-5 h-5" />
                    </button>
                    <button @click="emit('close')" class="close-btn">
                        <Icons name="close" class="w-5 h-5" />
                    </button>
                </div>
            </div>

            <!-- Zakładki -->
            <div :class="['tabs', { 'tabs-scrollable': floatingMode }]">
                <button 
                    v-for="tab in [
                        { id: 'details', label: 'SZCZEGÓŁY' },
                        ...(visit?.client_id ? [{ id: 'client_card', label: 'KARTA KLIENTA' }] : []),
                        { id: 'offer', label: 'OFERTA' },
                        { id: 'orders', label: 'ZAMÓWIENIA' },
                        { id: 'apilo', label: 'DODAJ ZAMÓWIENIE (APILO)' },
                        { id: 'invoices', label: 'FAKTUROWNIA' },
                        ...(hasRingostat ? [{ id: 'calls', label: 'POŁĄCZENIA' }] : []),
                    ]"
                    :key="tab.id"
                    @click="activeTab = tab.id; if (tab.id === 'apilo') { loadApiloProducts(true); prefillOrderForm(); loadApiloOptions(); } if (tab.id === 'calls') { loadClientCalls(); } if (tab.id === 'client_card') { initClientForm(); } if (tab.id === 'orders') { loadCoreOrders(); }"
                    :class="['tab-btn', { active: activeTab === tab.id }]"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Zawartość -->
            <div class="modal-content">
                <!-- SZCZEGÓŁY -->
                <div v-if="activeTab === 'details'" class="tab-content">
                    <div class="form-grid">
                        <!-- Lewa kolumna -->
                        <div class="form-column">
                            <!-- Tytuł -->
                            <div class="form-group">
                                <label class="form-label">Tytuł*</label>
                                <input 
                                    type="text" 
                                    v-model="form.title" 
                                    class="form-input"
                                    placeholder="Tytuł"
                                />
                                <span class="form-hint">Tytuł</span>
                            </div>

                            <!-- Klient -->
                            <div class="form-group relative">
                                <label class="form-label">Klient</label>
                                <div class="flex gap-2">
                                    <div class="flex-1 relative">
                                        <input
                                            ref="clientSearchInputRef"
                                            type="text"
                                            :value="clientSearchOpen ? clientSearchQuery : selectedClientDisplay"
                                            @focus="openClientSearch"
                                            @input="clientSearchOpen ? (clientSearchQuery = $event.target.value, onClientSearchInput()) : null"
                                            class="form-input w-full pr-8"
                                            placeholder="Szukaj klienta po nazwie, NIP lub email..."
                                            autocomplete="off"
                                        />
                                        <button
                                            v-if="form.client_id && !clientSearchOpen"
                                            type="button"
                                            @click="detachClient"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-foreground-muted hover:text-foreground"
                                            title="Wyczyść"
                                        >
                                            <Icons name="close" class="w-4 h-4" />
                                        </button>
                                        <Icons v-else-if="!clientSearchOpen" name="search" class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-foreground-subtle pointer-events-none" />
                                        <div
                                            v-if="clientSearchOpen"
                                            class="absolute top-full left-0 right-0 mt-1 surface border border-slate-200 dark:border-slate-600 rounded-lg shadow-xl z-50 max-h-64 overflow-y-auto"
                                        >
                                            <div v-if="clientSearchLoading" class="p-4 text-center text-foreground-muted text-sm">
                                                <Icons name="spinner" class="w-5 h-5 animate-spin mx-auto" />
                                            </div>
                                            <template v-else>
                                                <button
                                                    v-for="c in clientSearchResults"
                                                    :key="c.id"
                                                    type="button"
                                                    @click="selectClient(c)"
                                                    class="w-full px-4 py-2.5 text-left hover:bg-surface-elevated text-sm flex justify-between items-center gap-2 transition-colors"
                                                >
                                                    <span class="truncate">{{ c.name }}</span>
                                                    <span v-if="c.full_name && c.full_name !== c.name" class="text-foreground-muted text-xs truncate shrink-0">{{ c.full_name }}</span>
                                                </button>
                                                <p v-if="clientSearchResults.length === 0 && !clientSearchLoading" class="p-4 text-foreground-muted text-sm">
                                                    {{ clientSearchQuery.length >= 2 ? 'Brak wyników' : 'Wpisz min. 2 znaki, aby wyszukać' }}
                                                </p>
                                            </template>
                                        </div>
                                    </div>
                                    <a
                                        v-if="form.client_id"
                                        :href="route('clients.show', form.client_id)"
                                        target="_blank"
                                        class="icon-btn"
                                        title="Szczegóły klienta"
                                    >
                                        <Icons name="document" class="w-5 h-5" />
                                    </a>
                                </div>
                                <div v-if="clientSearchOpen" class="fixed inset-0 z-40" @click="closeClientSearch"></div>
                                <button 
                                    type="button"
                                    @click="toggleNewClientForm"
                                    class="add-client-link"
                                >
                                    <Icons name="plus" class="w-4 h-4" />
                                    Dodaj klienta
                                </button>

                                <!-- Mini formularz nowego klienta -->
                                <div v-if="showNewClientForm" class="new-client-mini">
                                    <input v-model="newClientForm.name" placeholder="Nazwa firmy *" class="form-input mb-2" />
                                    <div class="mb-2">
                                        <div class="flex gap-2">
                                            <input v-model="newClientForm.nip" placeholder="NIP" class="form-input flex-1" />
                                            <button
                                                type="button"
                                                @click="fetchFromGusForNewClient"
                                                :disabled="gusLoadingNewClient || !newClientForm.nip"
                                                class="px-3 py-2 bg-info text-white rounded hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed text-sm whitespace-nowrap flex items-center gap-1 transition-opacity"
                                                title="Pobierz dane z GUS"
                                            >
                                                <Icons name="search" class="w-4 h-4" />
                                                <span v-if="!gusLoadingNewClient">GUS</span>
                                                <span v-else>...</span>
                                            </button>
                                        </div>
                                        <span v-if="gusErrorNewClient" class="text-red-500 text-xs block mt-1">{{ gusErrorNewClient }}</span>
                                        <span v-if="gusSuccessNewClient" class="text-green-600 text-xs block mt-1">{{ gusSuccessNewClient }}</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 mb-2">
                                        <input v-model="newClientForm.phone" placeholder="Telefon" class="form-input" />
                                        <input v-model="newClientForm.email" placeholder="Email" class="form-input" />
                                    </div>
                                    <button 
                                        type="button" 
                                        @click="submitNewClient"
                                        :disabled="!newClientForm.name || newClientForm.processing"
                                        class="btn-success btn-sm w-full"
                                    >
                                        {{ newClientForm.processing ? 'Zapisywanie...' : 'Dodaj klienta' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Opis -->
                            <div class="form-group">
                                <label class="form-label">Opis*</label>
                                <div v-if="hasDraftRestore" class="mb-2 flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="restoreDraft()"
                                        class="text-sm px-3 py-1.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 hover:bg-amber-200 dark:hover:bg-amber-800/50"
                                    >
                                        Przywróć zapisany szkic
                                    </button>
                                </div>
                                <div class="editor-toolbar">
                                    <button 
                                        type="button" 
                                        title="Źródło dokumentu"
                                        :class="{ 'bg-surface-elevated': showDescriptionSource }"
                                        @click="showDescriptionSource = !showDescriptionSource"
                                    >
                                        <Icons name="document" class="w-4 h-4" />
                                        Źródło dokumentu
                                    </button>
                                    <span class="toolbar-separator"></span>
                                    <button type="button" title="Pogrubienie" @click.prevent="formatDescription('bold')"><strong>B</strong></button>
                                    <button type="button" title="Kursywa" @click.prevent="formatDescription('italic')"><em>I</em></button>
                                    <button type="button" title="Podkreślenie" @click.prevent="formatDescription('underline')"><u>U</u></button>
                                    <button type="button" title="Przekreślenie" @click.prevent="formatDescription('strikeThrough')"><s>S</s></button>
                                    <span class="toolbar-separator"></span>
                                    <div class="relative">
                                        <button
                                            type="button"
                                            title="Kolor tekstu"
                                            :class="{ 'bg-surface-elevated': showTextColorPicker }"
                                            @click.stop="showTextColorPicker = !showTextColorPicker; showBgColorPicker = false"
                                        >
                                            <span class="text-sm font-bold" style="color: #333;">A</span>
                                        </button>
                                        <div v-if="showTextColorPicker" class="color-picker-popover absolute left-0 top-full mt-1.5 z-50">
                                            <div class="text-xs font-medium text-foreground-muted px-2 py-1.5 border-b border-slate-100 dark:border-slate-600">Kolor tekstu</div>
                                            <div class="p-3">
                                                <div class="grid grid-cols-4 gap-2">
                                                    <button
                                                        v-for="c in editorColors"
                                                        :key="'t'+c"
                                                        type="button"
                                                        class="color-swatch"
                                                        :class="{ 'border-slate-400 shadow-inner': ['#ffffff', '#999999'].includes(c) }"
                                                        :style="{ backgroundColor: c }"
                                                        @click="formatDescription('foreColor', c); showTextColorPicker = false"
                                                    ></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="relative">
                                        <button
                                            type="button"
                                            title="Kolor tła"
                                            :class="{ 'bg-surface-elevated': showBgColorPicker }"
                                            @click.stop="showBgColorPicker = !showBgColorPicker; showTextColorPicker = false"
                                        >
                                            <span class="text-sm font-bold px-0.5 rounded" style="background: #ffeb3b; color: #333;">A</span>
                                        </button>
                                        <div v-if="showBgColorPicker" class="color-picker-popover absolute left-0 top-full mt-1.5 z-50">
                                            <div class="text-xs font-medium text-foreground-muted px-2 py-1.5 border-b border-slate-100 dark:border-slate-600">Kolor tła</div>
                                            <div class="p-3">
                                                <div class="grid grid-cols-4 gap-2">
                                                    <button
                                                        v-for="c in editorColors"
                                                        :key="'b'+c"
                                                        type="button"
                                                        class="color-swatch"
                                                        :class="{ 'border-slate-400 shadow-inner': ['#ffffff', '#999999'].includes(c) }"
                                                        :style="{ backgroundColor: c }"
                                                        @click="formatDescription('backColor', c); showBgColorPicker = false"
                                                    ></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="showTextColorPicker || showBgColorPicker" class="fixed inset-0 z-40" @click="showTextColorPicker = false; showBgColorPicker = false"></div>
                                <!-- Widok sformatowany (domyślny) -->
                                <div
                                    v-show="!showDescriptionSource"
                                    ref="descriptionEditorRef"
                                    contenteditable="true"
                                    class="form-textarea description-editor min-h-[150px]"
                                    data-placeholder="Opis wizyty..."
                                    @input="onDescriptionEditorInput"
                                ></div>
                                <!-- Widok surowego HTML -->
                                <textarea 
                                    v-show="showDescriptionSource"
                                    v-model="form.description" 
                                    class="form-textarea"
                                    rows="6"
                                    placeholder="Opis wizyty (HTML)..."
                                ></textarea>
                                <span class="form-hint">Opis</span>
                            </div>

                            <!-- Notatki -->
                            <div class="form-group">
                                <label class="form-label">Notatki</label>
                                <textarea
                                    v-model="form.notes"
                                    class="form-textarea"
                                    rows="3"
                                    placeholder="Notatki..."
                                ></textarea>
                                <span class="form-hint">Notatki</span>
                            </div>

                            <!-- Telefony wizyty -->
                            <div class="form-group">
                                <label class="form-label">Telefony wizyty</label>
                                <p class="text-xs text-foreground-muted -mt-1 mb-2">
                                    Numery wiązane z tą wizytą. W Play Centrali połączenie z tego numeru pokaże tytuł wizyty. Po zapisie dopisane zostaną do klienta.
                                </p>
                                <div class="space-y-2">
                                    <div v-for="(phone, idx) in form.phones" :key="'vp-' + idx" class="flex items-center gap-2">
                                        <input
                                            type="tel"
                                            v-model="form.phones[idx]"
                                            class="form-input flex-1"
                                            :placeholder="idx === 0 ? '+48 500 123 456 (główny)' : 'Dodatkowy numer'"
                                        />
                                        <button
                                            type="button"
                                            @click="removeVisitPhone(idx)"
                                            class="px-2 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition"
                                            title="Usuń numer"
                                        >
                                            <Icons name="close" class="w-4 h-4" />
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        @click="addVisitPhone"
                                        class="inline-flex items-center gap-1 text-sm text-brand-primary hover:underline"
                                    >
                                        <Icons name="plus" class="w-4 h-4" /> Dodaj numer
                                    </button>
                                </div>
                                <span class="form-hint">Telefony kontaktowe wizyty</span>
                            </div>

                            <!-- Link do social media -->
                            <div class="form-group">
                                <label class="form-label">Link</label>
                                <div class="flex gap-2">
                                    <input 
                                        type="url" 
                                        v-model="form.link" 
                                        class="form-input flex-1"
                                        placeholder="https://facebook.com"
                                    />
                                    <a 
                                        v-if="form.link" 
                                        :href="form.link" 
                                        target="_blank"
                                        class="icon-btn"
                                        title="Otwórz link"
                                    >
                                        <Icons name="external-link" class="w-5 h-5" />
                                    </a>
                                </div>
                                <span class="form-hint">Link do social media</span>
                            </div>

                            <!-- Link do strony -->
                            <div class="form-group">
                                <label class="form-label">Link do strony</label>
                                <div class="flex gap-2">
                                    <input 
                                        type="url" 
                                        v-model="form.website" 
                                        class="form-input flex-1"
                                        placeholder="https://www.example.com"
                                    />
                                    <a 
                                        v-if="form.website" 
                                        :href="form.website" 
                                        target="_blank"
                                        class="icon-btn"
                                        title="Otwórz stronę"
                                    >
                                        <Icons name="external-link" class="w-5 h-5" />
                                    </a>
                                </div>
                                <span class="form-hint">Link do strony www</span>
                            </div>
                        </div>

                        <!-- Prawa kolumna -->
                        <div class="form-column">
                            <!-- Wyślij mail -->
                            <div class="form-group">
                                <label class="form-label">Wyślij mail z prośbą o opinię</label>
                                <button 
                                    type="button"
                                    @click="sendReviewRequest"
                                    class="btn-send"
                                >
                                    <Icons name="paper-airplane" class="w-4 h-4" />
                                    Wyślij
                                </button>
                            </div>

                            <!-- Status -->
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select v-model="form.status" class="form-select status-select">
                                    <option value="">Wybierz status</option>
                                    <option v-for="s in statuses" :key="s.value" :value="s.value">
                                        {{ s.label }}
                                    </option>
                                </select>
                                <div class="mt-2">
                                    <span :class="['status-badge', currentStatus.bgClass]">
                                        {{ currentStatus.label }}
                                    </span>
                                </div>
                                <span class="form-hint">Request status</span>
                            </div>

                            <!-- Data wprowadzenia (kiedy spotkanie zostało pierwszy raz utworzone) -->
                            <div class="form-group">
                                <label class="form-label">Data wprowadzenia</label>
                                <div class="form-input bg-surface-2 text-foreground py-2 px-3 rounded-lg">
                                    {{ visitCreatedAtFormatted }}
                                </div>
                                <span class="form-hint">Data pierwszego utworzenia spotkania</span>
                            </div>

                            <!-- Data spotkania (całodniowe) -->
                            <div class="form-group">
                                <label class="form-label">Data spotkania*</label>
                                <input 
                                    type="date" 
                                    v-model="form.visit_date" 
                                    class="form-input"
                                />
                                <span class="form-hint">Termin spotkania w kalendarzu</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Godzina spotkania</label>
                                <input
                                    type="time"
                                    v-model="form.visit_time"
                                    class="form-input w-40"
                                />
                                <span class="form-hint">Opcjonalnie — gdy pusta, w kalendarzu bez godziny</span>
                            </div>

                            <!-- Pracownik -->
                            <div class="form-group">
                                <label class="form-label">Pracownik*</label>
                                <select v-model="form.user_id" class="form-select">
                                    <option value="">Wybierz pracownika</option>
                                    <option v-for="user in users" :key="user.id" :value="user.id">
                                        {{ user.name }}
                                    </option>
                                </select>
                                <span class="form-hint">Pracownik</span>
                            </div>

                            <!-- Urodziny lokalu (gdy jest klient) -->
                            <div v-if="form.client_id && clientForm" class="form-group">
                                <label class="form-label">Urodziny lokalu</label>
                                <input type="date" v-model="clientForm.profile.venue.venue_birthday" class="form-input" />
                                <span class="form-hint">Rocznica otwarcia – handlowiec dostanie przypomnienie 30 dni przed</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ZAMÓWIENIA (CORE) -->
                <div v-if="activeTab === 'orders'" class="tab-content">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="section-title mb-0">Zamówienia klienta</h4>
                        <button type="button" @click="openCoreOrderForm()" class="btn-primary btn-sm" :disabled="!visit?.client_id">
                            <Icons name="plus" class="w-4 h-4" />
                            Nowe zamówienie
                        </button>
                    </div>

                    <div v-if="!visit?.client_id" class="text-center py-12 surface-elevated rounded-lg">
                        <Icons name="users" class="w-12 h-12 mx-auto mb-3 text-foreground-subtle opacity-50" />
                        <p class="text-sm text-foreground-muted">Najpierw przypisz klienta do wizyty (zakładka „Szczegóły"), aby tworzyć zamówienia.</p>
                    </div>

                    <div v-else-if="coreOrdersLoading" class="text-center py-8 text-foreground-muted text-sm">Ładuję…</div>

                    <div v-else-if="!coreOrders.length" class="text-center py-12 surface-elevated rounded-lg">
                        <Icons name="shopping-cart" class="w-12 h-12 mx-auto mb-3 text-foreground-subtle opacity-50" />
                        <p class="text-sm text-foreground-muted">Brak zamówień. Kliknij „Nowe zamówienie" żeby utworzyć pierwsze.</p>
                    </div>

                    <ul v-else class="space-y-2">
                        <li v-for="o in coreOrders" :key="o.id"
                            class="surface-elevated rounded-md p-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-semibold text-foreground">{{ o.number }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded-full"
                                          :class="{
                                              'bg-foreground-muted/15 text-foreground-muted': o.status === 'draft',
                                              'bg-info/15 text-info': o.status === 'new',
                                              'bg-warning/15 text-warning': o.status === 'in_progress',
                                              'bg-success/15 text-success': o.status === 'completed',
                                              'bg-destructive/15 text-destructive': o.status === 'cancelled',
                                          }">
                                        {{ o.status_label }}
                                    </span>
                                </div>
                                <p class="text-xs text-foreground-muted mt-1">
                                    {{ o.order_date }} · {{ o.items_count }} {{ o.items_count === 1 ? 'pozycja' : 'pozycji' }}
                                    <span v-if="o.user_name"> · {{ o.user_name }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="text-sm font-bold text-foreground font-mono">{{ formatPlnAmount(o.total_gross) }}</span>
                                <a :href="route('orders.pdf', o.id)" target="_blank" class="text-xs text-brand-primary hover:underline whitespace-nowrap">
                                    <Icons name="document-arrow-down" class="w-4 h-4 inline" /> PDF
                                </a>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- OFERTA -->
                <div v-if="activeTab === 'offer'" class="tab-content">
                    <div class="offer-section">
                        <h4 class="section-title">Oferta dla klienta</h4>
                        
                        <!-- Informacja o braku emaila klienta -->
                        <div v-if="!client.email" class="warning-box mb-4">
                            <Icons name="info" class="w-5 h-5" />
                            <span>Klient nie ma przypisanego adresu email. Wpisz adres odbiorcy poniżej lub dodaj email w danych klienta.</span>
                        </div>
                        
                        <!-- Informacja o braku konfiguracji SMTP -->
                        <div v-if="!hasMailConfig" class="warning-box mb-4">
                            <Icons name="mail" class="w-5 h-5" />
                            <span>Nie masz skonfigurowanego serwera pocztowego. <a href="/settings/mail" class="text-blue-600 underline">Skonfiguruj SMTP</a></span>
                        </div>
                        
                        <!-- Wartość zamówienia -->
                        <div class="offer-value mb-6">
                            <label class="form-label">Wartość zamówienia</label>
                            <div class="flex items-center gap-2">
                                <input 
                                    type="number" 
                                    v-model="form.order_value" 
                                    class="form-input w-40"
                                    placeholder="0.00"
                                    step="0.01"
                                />
                                <span class="text-foreground-muted">PLN</span>
                            </div>
                        </div>
                        
                        <!-- Wybór szablonu lub własna wiadomość -->
                        <div class="email-template-section">
                            <h5 class="font-semibold text-foreground mb-3">Wyślij ofertę emailem</h5>
                            
                            <div class="flex gap-4 mb-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="useCustomMessage" :value="false" class="form-radio" />
                                    <span>Szablon</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="useCustomMessage" :value="true" class="form-radio" />
                                    <span>Własna wiadomość</span>
                                </label>
                            </div>
                            
                            <!-- Szablon -->
                            <div v-if="!useCustomMessage" class="space-y-4 mb-4">
                                <div class="form-group">
                                    <label class="form-label">Wybierz szablon wiadomości *</label>
                                    <select v-model="selectedTemplateId" class="form-select">
                                        <option value="">-- Wybierz szablon --</option>
                                        <option 
                                            v-for="template in emailTemplates.filter(t => t.is_active)" 
                                            :key="template.id" 
                                            :value="template.id"
                                        >
                                            {{ template.name }} ({{ template.category === 'offer' ? 'Oferta' : template.category }})
                                        </option>
                                    </select>
                                    <p v-if="selectedTemplate?.description" class="text-sm text-foreground-muted mt-1">
                                        {{ selectedTemplate.description }}
                                    </p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Temat maila</label>
                                    <input 
                                        v-model="templateSubjectOverride" 
                                        type="text" 
                                        class="form-input" 
                                        placeholder="Opcjonalnie – zostaw puste, aby użyć tematu z szablonu"
                                    />
                                </div>
                            </div>
                            
                            <!-- Własna wiadomość -->
                            <div v-else class="space-y-4 mb-4">
                                <div class="form-group">
                                    <label class="form-label">Temat *</label>
                                    <input v-model="customSubject" type="text" class="form-input" placeholder="Temat wiadomości" />
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Treść (HTML) *</label>
                                    <textarea v-model="customHtmlContent" class="form-input min-h-[120px]" placeholder="Wpisz treść wiadomości. Możesz użyć HTML." rows="6"></textarea>
                                </div>
                            </div>
                            
                            <!-- Cennik jako PDF -->
                            <div v-if="priceLists && priceLists.length" class="form-group mb-4">
                                <label class="form-label">Dołącz cennik (PDF)</label>
                                <select v-model="selectedPriceListId" class="form-select">
                                    <option value="">— bez cennika —</option>
                                    <option v-for="pl in priceLists" :key="pl.id" :value="pl.id">
                                        {{ pl.name }}
                                    </option>
                                </select>
                                <span class="form-hint">Wybrany cennik zostanie skonwertowany do PDF i dołączony do maila.</span>
                            </div>

                            <!-- Załączniki -->
                            <div class="form-group mb-4">
                                <label class="form-label">Załączniki</label>
                                <input
                                    type="file"
                                    multiple
                                    class="form-input text-sm py-2"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.zip"
                                    @change="onEmailAttachmentsChange"
                                />
                                <div v-if="emailAttachments.length" class="mt-2 flex flex-wrap gap-2">
                                    <span
                                        v-for="(f, i) in emailAttachments"
                                        :key="i"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded surface-elevated text-sm"
                                    >
                                        {{ f.name }}
                                        <button type="button" @click="removeEmailAttachment(i)" class="text-foreground-muted hover:text-red-500 transition-colors">
                                            <Icons name="close" class="w-3.5 h-3.5" />
                                        </button>
                                    </span>
                                </div>
                                <span class="form-hint">Max 10 plików, 10 MB każdy. PDF, DOC, XLS, obrazy, ZIP.</span>
                            </div>
                            
                            <!-- Wybór konfiguracji SMTP (jeśli jest więcej niż jedna) -->
                            <div v-if="mailConfigs.length > 1" class="form-group mb-4">
                                <label class="form-label">Wyślij z konta</label>
                                <select v-model="selectedMailConfigId" class="form-select">
                                    <option value="">{{ defaultMailConfig?.name }} (domyślne)</option>
                                    <option 
                                        v-for="config in mailConfigs.filter(c => c.is_verified)" 
                                        :key="config.id" 
                                        :value="config.id"
                                    >
                                        {{ config.name }} ({{ config.mail_from_address }})
                                    </option>
                                </select>
                            </div>
                            
                            <!-- Odbiorca -->
                            <div class="form-group mb-4">
                                <label class="form-label">Adres email odbiorcy *</label>
                                <input 
                                    v-model="recipientEmailOverride" 
                                    type="email" 
                                    class="form-input" 
                                    :placeholder="client.email || 'np. klient@firma.pl'"
                                />
                                <span v-if="client.email && !recipientEmailOverride" class="form-hint">Domyślnie: {{ client.email }} ({{ client.name }})</span>
                                <span v-else class="form-hint">Możesz wpisać inny adres niż w danych klienta</span>
                            </div>
                            
                            <!-- Akcje -->
                            <div class="flex items-center gap-3">
                                <button 
                                    @click="previewEmailTemplate"
                                    :disabled="(useCustomMessage ? !(customSubject?.trim() && customHtmlContent?.trim()) : !selectedTemplateId) || isLoadingPreview"
                                    class="btn-secondary"
                                >
                                    <Icons name="document" class="w-4 h-4 mr-2" />
                                    {{ isLoadingPreview ? 'Ładowanie...' : 'Podgląd' }}
                                </button>
                                <button 
                                    @click="sendEmail"
                                    :disabled="(useCustomMessage ? !(customSubject?.trim() && customHtmlContent?.trim()) : !selectedTemplateId) || !effectiveRecipientEmail || !hasMailConfig || isSendingEmail"
                                    class="btn-primary"
                                >
                                    <Icons name="paper-airplane" class="w-4 h-4 mr-2" />
                                    {{ isSendingEmail ? 'Wysyłanie...' : 'Wyślij email' }}
                                </button>
                            </div>
                        </div>
                        
                        <!-- Brak szablonów -->
                        <div v-if="emailTemplates.length === 0" class="empty-templates mt-6">
                            <Icons name="document" class="w-10 h-10 text-foreground-subtle mb-2" />
                            <p class="text-foreground-muted">Brak szablonów email.</p>
                            <p class="text-sm text-foreground-subtle">Szablony możesz utworzyć w Panelu administracyjnym → Szablony Email</p>
                        </div>
                    </div>
                    
                    <!-- Modal podglądu emaila -->
                    <div v-if="showEmailPreview" class="email-preview-modal">
                        <div class="email-preview-content">
                            <div class="email-preview-header">
                                <h3 class="font-semibold">Podgląd wiadomości</h3>
                                <button @click="showEmailPreview = false" class="close-btn">
                                    <Icons name="close" class="w-5 h-5" />
                                </button>
                            </div>
                            <div class="email-preview-meta">
                                <div class="meta-row">
                                    <span class="meta-label">Do:</span>
                                    <span>{{ client.name }} &lt;{{ effectiveRecipientEmail }}&gt;</span>
                                </div>
                                <div class="meta-row">
                                    <span class="meta-label">Temat:</span>
                                    <span class="font-medium">{{ (!useCustomMessage && templateSubjectOverride?.trim()) ? templateSubjectOverride : emailPreview?.subject }}</span>
                                </div>
                            </div>
                            <div class="email-preview-body">
                                <div v-html="emailPreview?.html"></div>
                            </div>
                            <div class="email-preview-footer">
                                <button @click="showEmailPreview = false" class="btn-secondary">
                                    Zamknij
                                </button>
                                <button 
                                    @click="sendEmail"
                                    :disabled="isSendingEmail"
                                    class="btn-primary"
                                >
                                    <Icons name="paper-airplane" class="w-4 h-4 mr-2" />
                                    {{ isSendingEmail ? 'Wysyłanie...' : 'Wyślij teraz' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- APILO -->
                <div v-if="activeTab === 'apilo'" class="tab-content">
                    <div class="order-form">
                        <h4 class="section-title">Nowe zamówienie w Apilo</h4>

                        <!-- Powiadomienie o zaległych fakturach -->
                        <div
                            v-if="orderBlockedByOverdue"
                            class="mb-4 p-4 rounded-lg gradient-subtle border border-brand-primary/30 text-amber-800 dark:text-amber-200 flex items-center gap-3"
                        >
                            <Icons name="alert" class="w-5 h-5 flex-shrink-0" />
                            <div>
                                <strong>Nie można dodać zamówienia</strong> – termin płatności faktur przelewowych przekroczony o więcej niż 10 dni. Wybierz <strong>za pobraniem</strong> albo ureguluj zaległości.
                            </div>
                        </div>

                        <!-- SZCZEGÓŁY ZAMÓWIENIA + PŁATNOŚĆ I WYSYŁKA -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                            <!-- Szczegóły zamówienia -->
                            <div class="border rounded-lg dark:border-slate-700 p-4">
                                <h5 class="text-sm font-semibold text-foreground mb-3">Szczegóły zamówienia</h5>
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-xs text-foreground-muted">Data zamówienia *</label>
                                            <input v-model="orderForm.order_date" type="date" class="form-input text-sm" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-foreground-muted">Godzina zamówienia</label>
                                            <input v-model="orderForm.order_time" type="time" class="form-input text-sm" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">Kanał sprzedaży *</label>
                                        <select v-model="orderForm.platform_id" class="form-select text-sm">
                                            <option value="" disabled>{{ apiloOptionsLoading ? 'Ładowanie...' : 'Kanał sprzedaży' }}</option>
                                            <option v-for="p in apiloPlatforms" :key="p.id" :value="p.id">{{ p.name || ('Platforma #' + p.id) }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Płatność i wysyłka -->
                            <div class="border rounded-lg dark:border-slate-700 p-4">
                                <h5 class="text-sm font-semibold text-foreground mb-3">Płatność i wysyłka</h5>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-xs text-foreground-muted">Sposób zapłaty *</label>
                                        <select v-model="orderForm.payment_type" class="form-select text-sm">
                                            <option value="" disabled>{{ apiloOptionsLoading ? 'Ładowanie...' : 'Sposób zapłaty' }}</option>
                                            <option v-for="pt in apiloPaymentTypes" :key="pt.id" :value="pt.id">{{ pt.name || ('Płatność #' + pt.id) }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">Dostawa</label>
                                        <select v-model="orderForm.carrier_account" class="form-select text-sm">
                                            <option value="" disabled>{{ apiloOptionsLoading ? 'Ładowanie...' : 'Dostawa' }}</option>
                                            <option v-for="ca in apiloCarriers" :key="ca.id" :value="ca.id">{{ ca.name || ('Kurier #' + ca.id) }}</option>
                                        </select>
                                    </div>
                                    <div v-if="isInPostPaczkomat" class="space-y-2">
                                        <div>
                                            <label class="text-xs text-foreground-muted">Paczkomat InPost (np. KRA010)</label>
                                            <div class="flex gap-2">
                                                <input
                                                    v-model="orderForm.inpost_parcel_point"
                                                    type="text"
                                                    placeholder="KRA010"
                                                    class="form-input text-sm flex-1"
                                                    maxlength="20"
                                                />
                                                <button
                                                    v-if="showInPostGeowidget"
                                                    type="button"
                                                    @click="openInPostMapPopup"
                                                    class="btn-secondary text-xs px-3 py-2 whitespace-nowrap"
                                                >
                                                    Wybierz na mapie
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status produktów Apilo -->
                        <div class="mb-4 flex items-center gap-2 flex-wrap">
                            <button 
                                @click="loadApiloProducts(true)" 
                                class="btn-secondary text-xs px-3 py-1.5"
                                :disabled="productsLoading"
                            >
                                <Icons name="refresh" class="w-3.5 h-3.5 mr-1" :class="{ 'animate-spin': productsLoading }" />
                                {{ productsLoading ? 'Ładowanie...' : 'Odśwież bazę produktów' }}
                            </button>
                            <span v-if="productsLoaded" class="text-xs px-2 py-1 rounded-full bg-green-100 dark:bg-green-900/30 text-success">
                                {{ apiloProducts.length }} produktów załadowanych
                            </span>
                            <span v-else-if="productsLoading" class="text-xs text-foreground-muted">
                                Pobieranie z Apilo...
                            </span>
                        </div>
                        
                        <div class="products-list">
                            <div class="products-header" style="grid-template-columns: 1fr 70px 70px 90px 90px 40px;">
                                <span>Produkt</span>
                                <span>Ilość</span>
                                <span>VAT %</span>
                                <span>Cena netto</span>
                                <span>Cena brutto</span>
                                <span></span>
                            </div>
                            
                            <div 
                                v-for="(product, index) in orderForm.products" 
                                :key="product._apiloRowKey"
                                class="border-t dark:border-slate-700"
                            >
                                <!-- Wiersz: input + ilość + vat + netto + brutto + usuń -->
                                <div class="grid gap-2 p-3 items-center" style="grid-template-columns: 1fr 70px 70px 90px 90px 40px;">
                                    <div class="relative">
                                        <Icons name="search" class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-foreground-subtle pointer-events-none" />
                                        <input 
                                            v-model="productSearchQuery[index]" 
                                            type="text" 
                                            :placeholder="product.name || 'Szukaj produktu...'"
                                            class="form-input text-sm pl-8 pr-8"
                                            @focus="onProductSearch(index)"
                                            @input="onProductSearch(index)"
                                            @blur="closeProductDropdown(index)"
                                            autocomplete="off"
                                        />
                                        <button 
                                            v-if="product.name" 
                                            @click="clearProductSelection(index)"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-foreground-muted hover:text-red-500 transition-colors"
                                            type="button"
                                        >
                                            <Icons name="close" class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                    <input 
                                        v-model.number="product.quantity" 
                                        type="number" 
                                        min="1"
                                        class="form-input text-sm"
                                    />
                                    <select 
                                        v-model.number="product.tax_rate" 
                                        class="form-select text-sm"
                                    >
                                        <option v-for="r in VAT_RATES" :key="r" :value="r">{{ r }}%</option>
                                    </select>
                                    <input 
                                        type="number" 
                                        step="0.01"
                                        min="0"
                                        placeholder="0.00"
                                        class="form-input text-sm"
                                        title="Cena netto"
                                        :value="productPriceNet(product)"
                                        @input="updatePriceFromNet(product, $event)"
                                    />
                                    <input 
                                        v-model.number="product.price" 
                                        type="number" 
                                        step="0.01"
                                        min="0"
                                        placeholder="0.00"
                                        class="form-input text-sm"
                                        title="Cena brutto"
                                    />
                                    <button 
                                        @click="removeProduct(index)" 
                                        class="btn-icon-danger"
                                        v-if="orderForm.products.length > 1"
                                    >
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                                <!-- Wybrany produkt (info) -->
                                <div v-if="product.name && !productDropdownOpen[index]" class="px-3 pb-2 text-xs text-foreground-muted truncate">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-brand-primary">
                                        {{ product.name }}
                                        <span v-if="product.product_id" class="text-amber-500 dark:text-amber-500">#{{ product.product_id }}</span>
                                    </span>
                                </div>
                                <!-- Lista wyników wyszukiwania (inline, nie absolute) -->
                                <div 
                                    v-if="productDropdownOpen[index] && productsLoaded"
                                    class="mx-3 mb-2 max-h-48 overflow-y-auto surface-2 border border-gray-200 dark:border-slate-600 rounded-lg"
                                >
                                    <div v-if="productsLoading" class="p-3 text-center text-xs text-foreground-muted">
                                        <Icons name="refresh" class="w-4 h-4 animate-spin inline mr-1" />
                                        Ładowanie produktów...
                                    </div>
                                    <div v-else-if="getFilteredProducts(index).length === 0" class="p-3 text-center text-xs text-foreground-muted">
                                        Nie znaleziono produktów pasujących do "{{ productSearchQuery[index] }}"
                                    </div>
                                    <button 
                                        v-for="(p, pIdx) in getFilteredProducts(index)" 
                                        :key="'apilo-p-' + index + '-' + pIdx + '-' + (p.id ?? 'noid') + '-' + String(p.sku || '').slice(0, 24)"
                                        type="button"
                                        @mousedown.prevent="selectProduct(index, p)"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-amber-50 dark:hover:bg-amber-900/30 border-b border-border last:border-0 transition-colors cursor-pointer"
                                        :class="{ 'bg-amber-50 dark:bg-amber-900/30': product.product_id && String(product.product_id) === String(p.id) }"
                                    >
                                        <div class="font-medium text-foreground truncate">{{ p.name }}</div>
                                        <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                                            <span class="text-xs font-medium text-brand-primary">{{ formatCurrency(p.price) }} brutto</span>
                                            <span class="text-xs text-foreground-muted">{{ formatCurrency(p.price_net ?? (p.price / (1 + (p.tax_rate || 23) / 100))) }} netto</span>
                                            <span class="text-xs text-foreground-muted">{{ p.tax_rate ?? 23 }}% VAT</span>
                                            <span v-if="p.sku" class="text-xs text-foreground-subtle">SKU: {{ p.sku }}</span>
                                            <span v-if="p.ean" class="text-xs text-foreground-subtle">EAN: {{ p.ean }}</span>
                                        </div>
                                    </button>
                                </div>
                                <!-- Info gdy produkty nie załadowane -->
                                <div 
                                    v-else-if="productDropdownOpen[index] && !productsLoaded && !productsLoading"
                                    class="mx-3 mb-2 p-3 surface-2 border border-gray-200 dark:border-slate-600 rounded-lg text-center"
                                >
                                    <p class="text-xs text-foreground-muted mb-2">Produkty nie zostały jeszcze pobrane z Apilo</p>
                                    <button 
                                        @mousedown.prevent="loadApiloProducts(true)"
                                        class="text-xs text-brand-primary hover:underline font-medium"
                                    >
                                        Kliknij aby pobrać bazę produktów
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between mt-4">
                            <div class="flex items-center gap-3">
                                <button @click="addProduct" class="btn-link">
                                    <Icons name="plus" class="w-4 h-4" />
                                    Dodaj produkt
                                </button>
                                <button v-if="orderForm.products.length > 1" @click="clearAllProducts" class="btn-link text-red-500 hover:text-destructive dark:hover:text-red-300">
                                    <Icons name="trash" class="w-4 h-4" />
                                    Wyczyść
                                </button>
                            </div>
                            <div class="order-total text-right">
                                <div>
                                    <span class="text-foreground-muted text-sm">Netto:</span>
                                    <span class="font-semibold text-foreground ml-1">{{ formatCurrency(orderTotalNet) }}</span>
                                </div>
                                <div>
                                    <span class="text-foreground-muted text-sm">Brutto:</span>
                                    <span class="total-value">{{ formatCurrency(orderTotal) }}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- DANE ZAMAWIAJĄCEGO I WYSYŁKI -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
                            <!-- Dane zamawiającego -->
                            <div class="border rounded-lg dark:border-slate-700 p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <h5 class="text-sm font-semibold text-foreground">Dane zamawiającego</h5>
                                    <button
                                        type="button"
                                        @click="lookupNipForOrderForm"
                                        :disabled="orderGusLoading || !orderForm.customer_nip || String(orderForm.customer_nip).replace(/\D/g, '').length < 10"
                                        class="text-xs text-brand-primary hover:underline disabled:opacity-50 disabled:cursor-not-allowed disabled:no-underline flex items-center gap-1"
                                        title="Pobierz dane z GUS po NIP"
                                    >
                                        <Icons v-if="orderGusLoading" name="refresh" class="w-3.5 h-3.5 animate-spin" />
                                        <Icons v-else name="search" class="w-3.5 h-3.5" />
                                        {{ orderGusLoading ? 'Pobieram...' : 'Pobierz z GUS' }}
                                    </button>
                                </div>
                                <p v-if="orderGusError" class="text-xs text-destructive mb-2">{{ orderGusError }}</p>
                                <div class="space-y-2">
                                    <div>
                                        <label class="text-xs text-foreground-muted">Nazwa *</label>
                                        <input v-model="orderForm.customer_name" type="text" class="form-input text-sm" placeholder="Nazwa klienta" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">NIP</label>
                                        <input v-model="orderForm.customer_nip" type="text" class="form-input text-sm" placeholder="NIP firmy" />
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="col-span-2">
                                            <label class="text-xs text-foreground-muted">Ulica</label>
                                            <input v-model="orderForm.customer_street" type="text" class="form-input text-sm" placeholder="Ulica" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-foreground-muted">Numer</label>
                                            <input v-model="orderForm.customer_street_number" type="text" class="form-input text-sm" placeholder="Nr" />
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div>
                                            <label class="text-xs text-foreground-muted">Kod pocztowy</label>
                                            <input v-model="orderForm.customer_zip" type="text" class="form-input text-sm" placeholder="00-000" />
                                        </div>
                                        <div class="col-span-2">
                                            <label class="text-xs text-foreground-muted">Miasto</label>
                                            <input v-model="orderForm.customer_city" type="text" class="form-input text-sm" placeholder="Miasto" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">Telefon *</label>
                                        <input v-model="orderForm.customer_phone" type="text" class="form-input text-sm" placeholder="Telefon" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">E-mail</label>
                                        <input v-model="orderForm.customer_email" type="email" class="form-input text-sm" placeholder="E-mail" />
                                    </div>
                                </div>
                            </div>

                            <!-- Dane wysyłki -->
                            <div class="border rounded-lg dark:border-slate-700 p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <h5 class="text-sm font-semibold text-foreground">Dane wysyłki</h5>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" v-model="orderForm.same_address" class="rounded border-border-bright text-amber-500 focus:ring-brand-primary" />
                                        <span class="text-xs text-foreground-muted">Taki sam jak zamawiający</span>
                                    </label>
                                </div>
                                <div v-if="!orderForm.same_address" class="space-y-2">
                                    <div class="flex justify-end -mt-1 mb-1">
                                        <button @click="copyToDelivery" type="button" class="text-xs text-brand-primary hover:underline">
                                            Skopiuj z zamawiającego
                                        </button>
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">Nazwa *</label>
                                        <input v-model="orderForm.delivery_name" type="text" class="form-input text-sm" placeholder="Nazwa odbiorcy" />
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="col-span-2">
                                            <label class="text-xs text-foreground-muted">Ulica</label>
                                            <input v-model="orderForm.delivery_street" type="text" class="form-input text-sm" placeholder="Ulica" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-foreground-muted">Numer</label>
                                            <input v-model="orderForm.delivery_street_number" type="text" class="form-input text-sm" placeholder="Nr" />
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div>
                                            <label class="text-xs text-foreground-muted">Kod pocztowy</label>
                                            <input v-model="orderForm.delivery_zip" type="text" class="form-input text-sm" placeholder="00-000" />
                                        </div>
                                        <div class="col-span-2">
                                            <label class="text-xs text-foreground-muted">Miasto</label>
                                            <input v-model="orderForm.delivery_city" type="text" class="form-input text-sm" placeholder="Miasto" />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">Telefon *</label>
                                        <input v-model="orderForm.delivery_phone" type="text" class="form-input text-sm" placeholder="Telefon" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-foreground-muted">E-mail</label>
                                        <input v-model="orderForm.delivery_email" type="email" class="form-input text-sm" placeholder="E-mail" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Błąd -->
                        <div v-if="orderError" class="mt-4 p-3 bg-destructive/10 border border-red-200 dark:border-red-700 rounded-lg text-destructive text-sm">
                            {{ orderError }}
                        </div>

                        <button 
                            @click="createOrder"
                            class="btn-primary w-full mt-6"
                            :disabled="orderCreating || orderTotal === 0 || !orderForm.products.some(p => p.name) || orderBlockedByPaymentRules"
                        >
                            <Icons v-if="orderCreating" name="refresh" class="w-4 h-4 mr-2 animate-spin" />
                            <Icons v-else name="shopping-cart" class="w-4 h-4 mr-2" />
                            {{ orderCreating ? 'Tworzenie zamówienia...' : 'Utwórz zamówienie w Apilo' }}
                        </button>
                    </div>
                    
                    <!-- Historia zamówień -->
                    <div class="orders-history mt-8">
                        <h4 class="section-title">Historia zamówień</h4>
                        
                        <div v-if="isLoading" class="loading-state">
                            Ładowanie...
                        </div>
                        
                        <div v-else-if="orders.length === 0" class="empty-state">
                            Brak zamówień dla tego klienta
                        </div>
                        
                        <div v-else class="orders-list">
                            <div v-for="order in orders" :key="order.id" class="order-item">
                                <div class="order-header flex-wrap gap-2">
                                    <span class="order-id">#{{ order.id }}</span>
                                    <span class="order-date">{{ formatDate(order.date) }}</span>
                                    <button
                                        v-if="order.has_tracking_sent"
                                        type="button"
                                        class="tracking-badge tracking-badge-clickable"
                                        title="Kliknij, aby otworzyć śledzenie przesyłki"
                                        :disabled="trackingLoadingOrderId === order.id"
                                        @click="openOrderTracking(order.id)"
                                    >
                                        <Icons v-if="trackingLoadingOrderId !== order.id" name="truck" class="w-3.5 h-3.5 shrink-0" />
                                        <span v-else class="inline-block w-3.5 h-3.5 shrink-0 border-2 border-current border-t-transparent rounded-full animate-spin" />
                                        {{ trackingLoadingOrderId === order.id ? 'Ładowanie...' : 'Tracking' }}
                                    </button>
                                </div>
                                <div class="order-footer">
                                    <span :class="['status-badge', invoiceStatusColors[order.status] || 'bg-surface-elevated text-foreground']">
                                        {{ invoiceStatusLabels[order.status] || order.status }}
                                    </span>
                                    <span class="order-total">{{ formatCurrency(order.total) }}</span>
                                </div>
                                <div
                                    v-if="order.payment_method"
                                    class="text-xs text-foreground-muted mt-1 pl-0.5"
                                >
                                    Płatność: {{ order.payment_method }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FAKTUROWNIA -->
                <div v-if="activeTab === 'invoices'" class="tab-content">
                    <h4 class="section-title">Faktury z Fakturowni</h4>
                    
                    <!-- NIP i pobieranie faktur -->
                    <div class="nip-lookup mb-6">
                        <label class="form-label">Wyszukaj po NIP</label>
                        <div class="flex gap-2 flex-wrap">
                            <input 
                                v-model="nipForm.nip"
                                type="text"
                                maxlength="10"
                                placeholder="Wprowadź NIP (10 cyfr)..."
                                class="form-input flex-1 min-w-[140px]"
                                @keyup.enter="fetchInvoicesByNip"
                            />
                            <button 
                                @click="fetchInvoicesByNip" 
                                type="button"
                                class="btn-primary"
                                :disabled="isLoading || String(nipForm.nip || '').replace(/\D/g, '').length < 10"
                            >
                                {{ isLoading ? 'Pobieranie...' : 'Pobierz faktury' }}
                            </button>
                            <button 
                                @click="lookupNip" 
                                type="button"
                                class="btn-secondary"
                                :disabled="nipLoading || nipForm.nip.length < 10"
                            >
                                {{ nipLoading ? 'Szukam...' : 'Szukaj w GUS' }}
                            </button>
                        </div>
                        
                        <div v-if="nipResult && !nipResult.error" class="nip-result">
                            <div class="result-row">
                                <span class="label">Nazwa:</span>
                                <span class="value">{{ nipResult.name }}</span>
                            </div>
                            <div class="result-row" v-if="nipResult.short_name && nipResult.short_name !== nipResult.name">
                                <span class="label">Skrócona:</span>
                                <span class="value">{{ nipResult.short_name }}</span>
                            </div>
                            <div class="result-row" v-if="nipResult.regon">
                                <span class="label">REGON:</span>
                                <span class="value">{{ nipResult.regon }}</span>
                            </div>
                            <div class="result-row" v-if="nipResult.street || nipResult.city">
                                <span class="label">Adres:</span>
                                <span class="value">{{ [nipResult.street, [nipResult.building_number, nipResult.apartment_number].filter(Boolean).join('/'), [nipResult.postal_code, nipResult.city].filter(Boolean).join(' ')].filter(Boolean).join(' ') }}</span>
                            </div>
                            <div class="result-row" v-else-if="nipResult.address">
                                <span class="label">Adres:</span>
                                <span class="value">{{ nipResult.address }}</span>
                            </div>
                        </div>
                        
                        <div v-if="nipResult?.error" class="nip-error">
                            {{ nipResult.error }}
                        </div>
                    </div>
                    
                    <div v-if="isLoading" class="loading-state">
                        Ładowanie faktur...
                    </div>
                    
                    <div v-else-if="invoices.length === 0" class="empty-state">
                        Brak faktur dla tego klienta
                    </div>
                    
                    <div v-else class="invoices-list">
                        <div v-for="invoice in invoices" :key="invoice.id" class="invoice-item">
                            <div 
                                class="invoice-row cursor-pointer hover:bg-surface-elevated/50 transition-colors"
                                @click="toggleInvoicePositions(invoice.id)"
                            >
                                <div class="invoice-header">
                                    <span class="invoice-number">{{ invoice.number }}</span>
                                    <span :class="['status-badge', invoiceStatusColors[invoice.status] || 'bg-surface-elevated text-foreground']">
                                        {{ invoiceStatusLabels[invoice.status] || invoice.status }}
                                    </span>
                                    <a
                                        :href="route('fakturownia.invoice-pdf', invoice.id)"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-indigo-100 text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50 transition-colors"
                                        title="Podgląd PDF faktury"
                                        @click.stop
                                    >
                                        <Icons name="eye" class="w-3 h-3" />
                                        PDF
                                    </a>
                                    <Icons
                                        :name="(invoiceDetailsCache[invoice.id] || loadingInvoiceId === invoice.id) ? 'chevron-down' : 'chevron-right'"
                                        class="w-4 h-4 text-foreground-muted ml-1"
                                    />
                                </div>
                                <div class="invoice-details">
                                    <span>Wystawiona: {{ formatDate(invoice.issue_date) }}</span>
                                    <span>Termin: {{ formatDate(invoice.payment_to) }}</span>
                                    <span v-if="invoicePaymentLabel(invoice)">Płatność: {{ invoicePaymentLabel(invoice) }}</span>
                                </div>
                                <div class="invoice-amount">
                                    {{ formatCurrency(invoice.price_gross) }}
                                </div>
                            </div>
                            <!-- Pozycje faktury -->
                            <div v-if="loadingInvoiceId === invoice.id" class="invoice-positions px-4 py-3 text-sm text-foreground-muted">
                                Ładowanie pozycji...
                            </div>
                            <div v-else-if="invoiceDetailsCache[invoice.id]?.error" class="invoice-positions px-4 py-3 text-sm text-red-500 flex items-center gap-2">
                                {{ invoiceDetailsCache[invoice.id].error }}
                                <button type="button" @click="retryInvoiceDetail(invoice.id)" class="text-indigo-600 hover:text-indigo-800 text-xs underline">
                                    Ponów
                                </button>
                            </div>
                            <div v-else-if="invoiceDetailsCache[invoice.id]?.positions?.length" class="invoice-positions border-t dark:border-slate-700">
                                <div class="px-4 py-2 text-xs font-medium text-foreground-muted uppercase">Pozycje na fakturze</div>
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-foreground-muted border-b dark:border-slate-700">
                                            <th class="px-4 py-2 font-medium">Nazwa</th>
                                            <th class="px-4 py-2 font-medium text-right">Ilość</th>
                                            <th class="px-4 py-2 font-medium text-right">Cena netto</th>
                                            <th class="px-4 py-2 font-medium text-right">Wartość</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr 
                                            v-for="(pos, i) in invoiceDetailsCache[invoice.id].positions" 
                                            :key="i"
                                            class="border-b dark:border-slate-700/50"
                                        >
                                            <td class="px-4 py-2">{{ pos.name || '—' }}</td>
                                            <td class="px-4 py-2 text-right">{{ pos.quantity ?? '—' }}</td>
                                            <td class="px-4 py-2 text-right">{{ formatCurrency(pos.price_net) }}</td>
                                            <td class="px-4 py-2 text-right font-medium">{{ formatCurrency(pos.total_price_net) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else-if="invoiceDetailsCache[invoice.id] && (!invoiceDetailsCache[invoice.id].positions || invoiceDetailsCache[invoice.id].positions.length === 0)" class="invoice-positions px-4 py-3 text-sm text-foreground-muted">
                                Brak pozycji
                            </div>
                        </div>
                        
                        <!-- Podsumowanie -->
                        <div class="invoices-summary">
                            <div class="summary-row">
                                <span>Łączna wartość:</span>
                                <span class="font-bold">{{ formatCurrency(invoices.reduce((s, i) => s + (i.price_gross || 0), 0)) }}</span>
                            </div>
                            <div class="summary-row text-green-600">
                                <span>Opłacone:</span>
                                <span class="font-bold">{{ formatCurrency(invoices.filter(i => i.status === 'paid').reduce((s, i) => s + (i.price_gross || 0), 0)) }}</span>
                            </div>
                            <div class="summary-row text-red-600">
                                <span>Do zapłaty:</span>
                                <span class="font-bold">{{ formatCurrency(invoices.filter(i => i.status !== 'paid').reduce((s, i) => s + (i.price_gross || 0), 0)) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KARTA KLIENTA -->
                <div v-if="activeTab === 'client_card'" class="tab-content">
                    <div v-if="!clientForm" class="loading-state">Ładowanie danych klienta...</div>
                    <div v-else>
                        <!-- Sukces -->
                        <div v-if="clientCardSuccess" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
                            Dane klienta zostały zapisane.
                        </div>
                        <!-- Błąd -->
                        <div v-if="clientCardError" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm dark:bg-red-900/20 dark:border-red-800 dark:text-red-400">
                            {{ clientCardError }}
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- LEWA KOLUMNA: dane podstawowe -->
                            <div class="space-y-5">
                                <div>
                                    <h4 class="section-title">Dane podstawowe</h4>
                                    <div class="space-y-3">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="form-label">Typ</label>
                                                <select v-model="clientForm.type" class="form-select">
                                                    <option value="company">Firma</option>
                                                    <option value="person">Osoba prywatna</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Status</label>
                                                <select v-model="clientForm.status" class="form-select">
                                                    <option value="active">Aktywny</option>
                                                    <option value="inactive">Nieaktywny</option>
                                                    <option value="potential">Potencjalny</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label">Nazwa</label>
                                            <input v-model="clientForm.name" class="form-input" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="form-label">Nazwa skrócona</label>
                                                <input v-model="clientForm.short_name" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">NIP</label>
                                                <div class="flex gap-2">
                                                    <input 
                                                        v-model="clientForm.nip" 
                                                        class="form-input flex-1" 
                                                        placeholder="10 cyfr"
                                                        maxlength="13"
                                                    />
                                                    <button 
                                                        type="button"
                                                        @click="lookupNipForClientCard"
                                                        class="btn-primary shrink-0"
                                                        :disabled="clientCardNipLoading || String(clientForm.nip || '').replace(/\D/g, '').length < 10"
                                                    >
                                                        {{ clientCardNipLoading ? '...' : 'Pobierz z GUS' }}
                                                    </button>
                                                </div>
                                                <p v-if="clientCardNipError" class="mt-1 text-sm text-red-500 dark:text-red-400">{{ clientCardNipError }}</p>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label">REGON</label>
                                            <input v-model="clientForm.regon" class="form-input" />
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="section-title">Dane kontaktowe</h4>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="form-label">Email</label>
                                            <input type="email" v-model="clientForm.email" class="form-input" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="form-label">Telefon</label>
                                                <div class="flex items-center gap-2">
                                                    <input v-model="clientForm.phone" class="form-input flex-1" />
                                                    <ClickToCall v-if="clientForm.phone" :phone="clientForm.phone" size="md" />
                                                </div>
                                            </div>
                                            <div>
                                                <label class="form-label">Telefon 2</label>
                                                <div class="flex items-center gap-2">
                                                    <input v-model="clientForm.phone2" class="form-input flex-1" />
                                                    <ClickToCall v-if="clientForm.phone2" :phone="clientForm.phone2" size="md" />
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label">Strona www</label>
                                            <input v-model="clientForm.website" class="form-input" />
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="section-title">Adres</h4>
                                    <div class="space-y-3">
                                        <div class="grid grid-cols-3 gap-3">
                                            <div class="col-span-2">
                                                <label class="form-label">Ulica</label>
                                                <input v-model="clientForm.street" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Nr budynku</label>
                                                <input v-model="clientForm.building_number" class="form-input" />
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-3 gap-3">
                                            <div>
                                                <label class="form-label">Nr lokalu</label>
                                                <input v-model="clientForm.apartment_number" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Kod pocztowy</label>
                                                <input v-model="clientForm.postal_code" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Miasto</label>
                                                <input v-model="clientForm.city" class="form-input" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="clientForm.type === 'company'">
                                    <h4 class="section-title">Osoba kontaktowa</h4>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="form-label">Imię i nazwisko</label>
                                            <input v-model="clientForm.contact_person" class="form-input" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="form-label">Email kontaktowy</label>
                                                <input type="email" v-model="clientForm.contact_email" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Telefon kontaktowy</label>
                                                <div class="flex items-center gap-2">
                                                    <input v-model="clientForm.contact_phone" class="form-input flex-1" />
                                                    <ClickToCall v-if="clientForm.contact_phone" :phone="clientForm.contact_phone" size="md" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="section-title">Notatki</h4>
                                    <textarea v-model="clientForm.notes" rows="3" class="form-textarea w-full"></textarea>
                                </div>
                            </div>

                            <!-- PRAWA KOLUMNA: profil lokalu -->
                            <div class="space-y-2">
                                <h4 class="section-title mb-0">Profil lokalu gastronomicznego</h4>

                                <!-- Organizacja -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('organization')" class="profile-accordion-btn">
                                        <span>Organizacja</span>
                                        <Icons :name="openProfileSections.organization ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.organization" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="form-label">Kto decyduje</label>
                                                <select v-model="clientForm.profile.organization.decision_maker" class="form-select">
                                                    <option value="">— wybierz —</option>
                                                    <option v-for="(label, val) in profileOptions.decision_makers" :key="val" :value="val">{{ label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Kto zamawia</label>
                                                <input v-model="clientForm.profile.organization.ordering_person" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Częstotliwość zamówień</label>
                                                <input v-model="clientForm.profile.organization.ordering_frequency" class="form-input" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Mental właściciela -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('mental')" class="profile-accordion-btn">
                                        <span>Mental właściciela</span>
                                        <Icons :name="openProfileSections.mental ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.mental" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="form-label">Cechy osobowości</label>
                                                <div class="flex flex-wrap gap-2 mt-1">
                                                    <label v-for="(label, val) in profileOptions.personalities" :key="val" class="flex items-center gap-1.5">
                                                        <input type="checkbox" :value="val" v-model="clientForm.profile.mental.personality" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                        <span class="text-sm text-foreground">{{ label }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="form-label">Notatki o podejściu</label>
                                                <textarea v-model="clientForm.profile.mental.approach_notes" rows="2" class="form-textarea w-full"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lokal -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('venue')" class="profile-accordion-btn">
                                        <span>Lokal</span>
                                        <Icons :name="openProfileSections.venue ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.venue" class="profile-accordion-body">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="form-label">Wielkość miejscowości</label>
                                                <select v-model="clientForm.profile.venue.city_size" class="form-select">
                                                    <option value="">— wybierz —</option>
                                                    <option v-for="(label, val) in profileOptions.city_sizes" :key="val" :value="val">{{ label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Położenie</label>
                                                <select v-model="clientForm.profile.venue.location" class="form-select">
                                                    <option value="">— wybierz —</option>
                                                    <option v-for="(label, val) in profileOptions.locations" :key="val" :value="val">{{ label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Typ lokalu</label>
                                                <select v-model="clientForm.profile.venue.venue_type" class="form-select">
                                                    <option value="">— wybierz —</option>
                                                    <option v-for="(label, val) in profileOptions.venue_types" :key="val" :value="val">{{ label }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Wielkość lokalu</label>
                                                <input v-model="clientForm.profile.venue.venue_size" class="form-input" placeholder="np. 50m²" />
                                            </div>
                                            <div>
                                                <label class="form-label">Pracownicy kuchni</label>
                                                <input type="number" v-model.number="clientForm.profile.venue.kitchen_staff" class="form-input" min="0" />
                                            </div>
                                            <div>
                                                <label class="form-label">Wszyscy pracownicy</label>
                                                <input type="number" v-model.number="clientForm.profile.venue.total_staff" class="form-input" min="0" />
                                            </div>
                                            <div>
                                                <label class="form-label">Lat na rynku</label>
                                                <input type="number" v-model.number="clientForm.profile.venue.years_in_business" class="form-input" min="0" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Ustalone rabaty -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('discounts')" class="profile-accordion-btn">
                                        <span>Ustalone rabaty</span>
                                        <Icons :name="openProfileSections.discounts ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.discounts" class="profile-accordion-body">
                                        <div>
                                            <label class="form-label">Rabaty</label>
                                            <input v-model="clientForm.profile.discounts" class="form-input" placeholder="np. 5% na dostawę" />
                                        </div>
                                    </div>
                                </div>

                                <!-- Forma płatności -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('payment_form')" class="profile-accordion-btn">
                                        <span>Forma płatności</span>
                                        <Icons :name="openProfileSections.payment_form ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.payment_form" class="profile-accordion-body">
                                        <div>
                                            <label class="form-label">Forma płatności</label>
                                            <input v-model="clientForm.profile.payment_form" class="form-input" placeholder="np. przelew, gotówka" />
                                        </div>
                                    </div>
                                </div>

                                <!-- Informacje o dostawie -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('delivery_info')" class="profile-accordion-btn">
                                        <span>Informacje o dostawie</span>
                                        <Icons :name="openProfileSections.delivery_info ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.delivery_info" class="profile-accordion-body">
                                        <div>
                                            <label class="form-label">Informacje o dostawie</label>
                                            <input v-model="clientForm.profile.delivery_info" class="form-input" placeholder="np. godziny dostaw, adres" />
                                        </div>
                                    </div>
                                </div>

                                <!-- Profil lokalu -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('concept')" class="profile-accordion-btn">
                                        <span>Profil lokalu</span>
                                        <Icons :name="openProfileSections.concept ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.concept" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="form-label">Specjalność</label>
                                                <input v-model="clientForm.profile.concept.specialty" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Kuchnia</label>
                                                <input v-model="clientForm.profile.concept.cuisine" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Poziom cenowy</label>
                                                <select v-model="clientForm.profile.concept.price_level" class="form-select">
                                                    <option value="">— wybierz —</option>
                                                    <option v-for="(label, val) in profileOptions.price_levels" :key="val" :value="val">{{ label }}</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sprzedaż -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('sales')" class="profile-accordion-btn">
                                        <span>Sprzedaż</span>
                                        <Icons :name="openProfileSections.sales ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.sales" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" v-model="clientForm.profile.sales.delivery" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                <span class="text-sm text-foreground">Dostawa</span>
                                            </label>
                                            <div v-if="clientForm.profile.sales.delivery">
                                                <label class="form-label">Wolumen dostaw</label>
                                                <input v-model="clientForm.profile.sales.delivery_volume" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Platformy</label>
                                                <div class="flex flex-wrap gap-2 mt-1">
                                                    <label v-for="(label, val) in profileOptions.platforms" :key="val" class="flex items-center gap-1.5">
                                                        <input type="checkbox" :value="val" v-model="clientForm.profile.sales.platforms" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                        <span class="text-sm text-foreground">{{ label }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="form-label">Godziny szczytu</label>
                                                <input v-model="clientForm.profile.sales.rush_hours" class="form-input" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Klienci -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('customers')" class="profile-accordion-btn">
                                        <span>Klienci</span>
                                        <Icons :name="openProfileSections.customers ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.customers" class="profile-accordion-body">
                                        <div class="flex flex-wrap gap-2">
                                            <label v-for="(label, val) in profileOptions.customer_profiles" :key="val" class="flex items-center gap-1.5">
                                                <input type="checkbox" :value="val" v-model="clientForm.profile.customers.profiles" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                <span class="text-sm text-foreground">{{ label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Kuchnia -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('kitchen')" class="profile-accordion-btn">
                                        <span>Kuchnia</span>
                                        <Icons :name="openProfileSections.kitchen ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.kitchen" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" v-model="clientForm.profile.kitchen.own_production" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                <span class="text-sm text-foreground">Własna produkcja</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" v-model="clientForm.profile.kitchen.uses_semi_finished" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                <span class="text-sm text-foreground">Używa półproduktów</span>
                                            </label>
                                            <div>
                                                <label class="form-label">Dostawcy</label>
                                                <input v-model="clientForm.profile.kitchen.suppliers" class="form-input" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Potencjał -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('potential')" class="profile-accordion-btn">
                                        <span>Potencjał</span>
                                        <Icons :name="openProfileSections.potential ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.potential" class="profile-accordion-body">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="form-label">Działania promocyjne</label>
                                                <input v-model="clientForm.profile.potential.promo_activities" class="form-input" />
                                            </div>
                                            <div>
                                                <label class="form-label">Jakość mediów</label>
                                                <input v-model="clientForm.profile.potential.media_quality" class="form-input" />
                                            </div>
                                            <div class="flex gap-6">
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" v-model="clientForm.profile.potential.menu_changes" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                    <span class="text-sm text-foreground">Zmiany w menu</span>
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" v-model="clientForm.profile.potential.open_to_tests" class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                                                    <span class="text-sm text-foreground">Otwarty na testy</span>
                                                </label>
                                            </div>
                                            <div>
                                                <label class="form-label">Notatki</label>
                                                <textarea v-model="clientForm.profile.potential.notes" rows="2" class="form-textarea w-full"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Co u nas kupują -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('current_products')" class="profile-accordion-btn">
                                        <span>Co u nas kupują</span>
                                        <Icons :name="openProfileSections.current_products ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.current_products" class="profile-accordion-body">
                                        <div>
                                            <label class="form-label">Obecne produkty</label>
                                            <input v-model="clientForm.profile.potential.current_products" class="form-input" placeholder="np. filety, skrzydełka" />
                                        </div>
                                    </div>
                                </div>

                                <!-- Urodziny lokalu -->
                                <div class="profile-accordion">
                                    <button type="button" @click="toggleProfileSection('venue_birthday')" class="profile-accordion-btn">
                                        <span>Urodziny lokalu</span>
                                        <Icons :name="openProfileSections.venue_birthday ? 'chevron-up' : 'chevron-down'" class="w-4 h-4" />
                                    </button>
                                    <div v-if="openProfileSections.venue_birthday" class="profile-accordion-body">
                                        <div>
                                            <label class="form-label">Data urodzin lokalu (rocznica otwarcia)</label>
                                            <input type="date" v-model="clientForm.profile.venue.venue_birthday" class="form-input" />
                                            <p class="text-xs text-foreground-muted mt-1">Handlowiec otrzyma przypomnienie 30 dni przed tą datą</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Przycisk zapisu -->
                        <div class="mt-6 flex items-center gap-3">
                            <button
                                @click="saveClientCard"
                                :disabled="clientCardSaving"
                                class="btn-save"
                            >
                                <Icons name="check" class="w-4 h-4 mr-1" />
                                {{ clientCardSaving ? 'Zapisywanie...' : 'Zapisz dane klienta' }}
                            </button>
                            <a
                                :href="route('clients.show', visit.client.id)"
                                target="_blank"
                                class="btn-link"
                            >
                                Otwórz pełną kartę klienta
                                <Icons name="external-link" class="w-4 h-4" />
                            </a>
                        </div>
                    </div>
                </div>

                <!-- POŁĄCZENIA -->
                <div v-if="activeTab === 'calls'" class="tab-content">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="section-title mb-0">Połączenia z klientem</h4>
                        <ClickToCall v-if="orderClient?.phone" :phone="orderClient.phone" size="md" />
                    </div>

                    <div v-if="loadingCalls" class="loading-state">Ładowanie połączeń...</div>

                    <div v-else-if="clientCalls.length === 0" class="empty-state">
                        <p>Brak zarejestrowanych połączeń z tym klientem</p>
                        <p class="text-xs mt-1 text-foreground-muted">Połączenia są synchronizowane z Ringostat</p>
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="call in clientCalls" :key="call.id"
                            class="surface-2/50 rounded-lg overflow-hidden"
                        >
                            <div class="flex items-center gap-3 p-3">
                                <div class="flex-shrink-0">
                                    <Icons
                                        :name="call.call_type === 'out' ? 'phone-outgoing' : 'phone-incoming'"
                                        :class="['h-5 w-5', call.disposition === 'ANSWERED' ? 'text-green-500' : 'text-red-500']"
                                    />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-foreground">{{ call.call_date }}</span>
                                        <span :class="[
                                            'px-1.5 py-0.5 rounded text-xs font-medium',
                                            call.disposition === 'ANSWERED' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                        ]">
                                            {{ call.disposition_label }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-foreground-muted">
                                        {{ call.call_type_label }} · {{ call.formatted_duration }}
                                        <span v-if="call.employee_name"> · {{ call.employee_name }}</span>
                                    </div>
                                </div>
                                <button
                                    v-if="call.has_recording"
                                    @click="toggleCallPlay(call)"
                                    :class="[
                                        'p-1.5 rounded-lg text-xs transition',
                                        playingId === call.id ? 'gradient-brand text-white' : 'surface-elevated text-foreground-muted hover:text-foreground'
                                    ]"
                                >
                                    <Icons :name="playingId === call.id ? 'pause' : 'play'" class="h-4 w-4" />
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer z przyciskami -->
            <div class="modal-footer">
                <div
                    v-if="Object.keys(form.errors).length || visitSaveErrorRef || visitSaveErrorMessage"
                    class="w-full mb-3 p-3 bg-destructive/10 border border-destructive/30 rounded-lg text-sm text-red-700 dark:text-red-300"
                >
                    <p class="font-medium mb-1">Nie udało się zapisać wizyty</p>
                    <p v-if="visitSaveErrorMessage" class="mb-2">{{ visitSaveErrorMessage }}</p>
                    <ul v-if="Object.keys(form.errors).length" class="list-disc list-inside mb-2">
                        <li v-for="(msg, key) in form.errors" :key="key">{{ msg }}</li>
                    </ul>
                    <p
                        v-if="visitSaveErrorRef"
                        class="mt-1 text-xs font-mono text-red-800/90 dark:text-red-300/90 border-t border-red-200/80 dark:border-red-800/80 pt-2"
                    >
                        Kod zgłoszenia (np. do logów / supportu):
                        <strong class="select-all">{{ visitSaveErrorRef }}</strong>
                    </p>
                </div>
                <button @click="saveVisit" :disabled="isSaving" class="btn-save">
                    <Icons name="check" class="w-4 h-4 mr-1" />
                    {{ isSaving ? 'Zapisywanie...' : 'Zapisz' }}
                </button>
                <button @click="saveAndStay" :disabled="isSaving" class="btn-save-stay">
                    <Icons name="check" class="w-4 h-4 mr-1" />
                    {{ isSaving ? 'Zapisywanie...' : 'Zapisz i zostań' }}
                </button>
                <template v-if="trashed">
                    <button @click="restoreVisit" :disabled="isDeleting" class="btn-restore">
                        <Icons name="refresh" class="w-4 h-4 mr-1" />
                        {{ isDeleting ? 'Przywracanie...' : 'Przywróć' }}
                    </button>
                    <button @click="confirmForceDelete" :disabled="isDeleting" class="btn-delete">
                        <Icons name="trash" class="w-4 h-4 mr-1" />
                        Usuń trwale
                    </button>
                </template>
                <template v-else>
                    <button @click="confirmDelete" class="btn-delete">
                        <Icons name="trash" class="w-4 h-4 mr-1" />
                        Do kosza
                    </button>
                </template>
                <button @click="emit('close')" class="btn-cancel">
                    <Icons name="close" class="w-4 h-4 mr-1" />
                    Anuluj
                </button>
            </div>
        </div>

    </div>

    <ConfirmModal
        :show="showDeleteModal"
        title="Przenieś do kosza"
        message="Czy na pewno chcesz przenieść to spotkanie do kosza? Będziesz mógł je później przywrócić."
        confirm-text="Tak, przenieś do kosza"
        :processing="isDeleting"
        @confirm="deleteVisit"
        @cancel="showDeleteModal = false"
    />
    <ConfirmModal
        :show="showForceDeleteModal"
        title="Usuń trwale"
        message="Czy na pewno chcesz trwale usunąć to spotkanie? Tej operacji nie można cofnąć."
        confirm-text="Tak, usuń trwale"
        :processing="isDeleting"
        @confirm="forceDeleteVisit"
        @cancel="showForceDeleteModal = false"
    />

    <!-- CORE: Modal "Nowe zamówienie" -->
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="coreOrderModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4"
                 style="background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);" @click.self="coreOrderModalOpen = false">
                <div class="glass-card rounded-xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col" style="background: var(--color-surface);">
                    <header class="px-6 py-4 border-b border-border flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-foreground">Nowe zamówienie</h2>
                            <p class="text-xs text-foreground-muted">Klient: {{ visit?.client?.name }}</p>
                        </div>
                        <button type="button" @click="coreOrderModalOpen = false" class="p-1 rounded hover:bg-surface-elevated text-foreground-muted hover:text-foreground">
                            <Icons name="close" class="w-5 h-5" />
                        </button>
                    </header>

                    <form @submit.prevent="submitCoreOrder" class="p-6 space-y-4 overflow-y-auto">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Data wystawienia</label>
                                <input type="date" v-model="coreOrderForm.order_date" required
                                       class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Termin realizacji</label>
                                <input type="date" v-model="coreOrderForm.delivery_date"
                                       class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Status</label>
                                <select v-model="coreOrderForm.status" class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground">
                                    <option value="draft">Szkic</option>
                                    <option value="new">Nowe</option>
                                    <option value="in_progress">W realizacji</option>
                                    <option value="completed">Zrealizowane</option>
                                </select>
                            </div>
                        </div>

                        <!-- Pozycje -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="text-sm font-medium text-foreground">Pozycje</label>
                                <button type="button" @click="addCoreItem" class="text-xs text-brand-primary hover:underline">+ Dodaj pozycję</button>
                            </div>
                            <div class="space-y-3">
                                <div v-for="(item, i) in coreOrderForm.items" :key="i"
                                     class="surface-elevated rounded-md p-3 space-y-2">
                                    <!-- Wiersz 1: picker z magazynu + delete -->
                                    <div class="flex items-center gap-2">
                                        <select :value="item.product_id" @change="pickCoreProduct(i, $event.target.value)"
                                                class="flex-1 min-w-0 h-9 rounded border border-border-bright px-2 text-sm bg-surface text-foreground">
                                            <option value="">— Wybierz z magazynu lub wpisz nazwę poniżej —</option>
                                            <option v-for="p in coreProductOptions" :key="p.id" :value="p.id">{{ p.name }}{{ p.sku ? ' (' + p.sku + ')' : '' }}</option>
                                        </select>
                                        <button type="button" @click="removeCoreItem(i)" :disabled="coreOrderForm.items.length === 1"
                                                class="h-9 w-9 rounded text-foreground-muted hover:text-destructive hover:bg-destructive/10 disabled:opacity-30 shrink-0">
                                            <Icons name="trash" class="w-4 h-4 mx-auto" />
                                        </button>
                                    </div>

                                    <input v-model="item.name" placeholder="Nazwa pozycji" required
                                           class="w-full h-9 rounded border border-border-bright px-3 text-sm bg-surface text-foreground" />

                                    <!-- Wiersz 2: ilość, jedn., cena, VAT — z labelkami -->
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        <label class="space-y-1">
                                            <span class="text-[10px] text-foreground-muted uppercase tracking-wide block">Ilość</span>
                                            <input v-model.number="item.quantity" type="number" step="0.001" min="0.001" required
                                                   class="w-full h-9 rounded border border-border-bright px-2 text-sm bg-surface text-foreground text-right" />
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[10px] text-foreground-muted uppercase tracking-wide block">Jedn.</span>
                                            <select v-model="item.unit"
                                                    class="w-full h-9 rounded border border-border-bright px-2 text-sm bg-surface text-foreground">
                                                <option v-for="u in ['szt','kg','l','godz','m','m2','m3','opak']" :key="u" :value="u">{{ u }}</option>
                                            </select>
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[10px] text-foreground-muted uppercase tracking-wide block">Cena netto</span>
                                            <input v-model.number="item.price_net" type="number" step="0.01" min="0" required
                                                   class="w-full h-9 rounded border border-border-bright px-2 text-sm bg-surface text-foreground text-right" />
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[10px] text-foreground-muted uppercase tracking-wide block">VAT</span>
                                            <select v-model.number="item.vat_rate"
                                                    class="w-full h-9 rounded border border-border-bright px-2 text-sm bg-surface text-foreground">
                                                <option v-for="r in [23,8,5,0]" :key="r" :value="r">{{ r }}%</option>
                                            </select>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Totale live -->
                        <div class="surface-elevated rounded-md p-3 grid grid-cols-3 gap-3 text-sm">
                            <div><span class="text-foreground-muted">Netto:</span> <strong class="text-foreground font-mono">{{ formatPlnAmount(coreOrderTotals.net) }}</strong></div>
                            <div><span class="text-foreground-muted">VAT:</span> <strong class="text-foreground font-mono">{{ formatPlnAmount(coreOrderTotals.vat) }}</strong></div>
                            <div><span class="text-foreground-muted">Brutto:</span> <strong class="text-brand-primary font-mono text-base">{{ formatPlnAmount(coreOrderTotals.gross) }}</strong></div>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-foreground">Uwagi</label>
                            <textarea v-model="coreOrderForm.notes" rows="2" maxlength="5000"
                                      class="w-full rounded-md border border-border-bright px-3 py-2 text-sm bg-surface-elevated text-foreground"></textarea>
                        </div>
                    </form>

                    <footer class="px-6 py-4 border-t border-border flex justify-end gap-3 bg-surface-2">
                        <button type="button" @click="coreOrderModalOpen = false" class="btn-secondary">Anuluj</button>
                        <button type="button" @click="submitCoreOrder" :disabled="coreOrderSubmitting" class="btn-primary">
                            <Icons name="check" class="w-4 h-4" />
                            {{ coreOrderSubmitting ? 'Tworzę…' : 'Utwórz zamówienie' }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-overlay {
    @apply fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 dark:bg-black/70;
}

.client-modal-embedded {
    @apply block w-full h-full min-h-0 overflow-hidden;
}

.client-modal {
    @apply bg-white rounded-lg shadow-2xl w-full max-w-5xl max-h-[95vh] overflow-hidden flex flex-col
           dark:bg-slate-800;
}

.modal-header {
    @apply flex items-center justify-between px-6 py-4 border-b bg-gray-50
           dark:bg-slate-800 dark:border-slate-700;
}

.close-btn {
    @apply p-2 rounded hover:bg-gray-200 text-gray-500 transition-colors
           dark:hover:bg-slate-700 dark:text-slate-400;
}

/* Zakładki */
.tabs {
    @apply flex border-b dark:border-slate-700 shrink-0;
}

.tabs-scrollable {
    @apply overflow-x-auto;
}

.tabs-scrollable .tab-btn {
    @apply shrink-0;
}

.tab-btn {
    @apply px-6 py-3 text-sm font-medium text-gray-600 hover:text-brand-primary hover:bg-gray-50 border-b-2 border-transparent transition-all
           dark:text-slate-400 hover:text-brand-primary dark:hover:bg-slate-700;
}

.tab-btn.active {
    @apply text-amber-600 border-amber-500 bg-white
           dark:bg-slate-800 dark:text-amber-400;
}

/* Zawartość */
.modal-content {
    @apply flex-1 overflow-y-auto surface;
}

.tab-content {
    @apply p-6;
}

/* Grid formularza */
.form-grid {
    @apply grid grid-cols-1 lg:grid-cols-2 gap-6;
}

.form-column {
    @apply space-y-4;
}

.form-group {
    @apply space-y-1;
}

.form-label {
    @apply block text-sm font-medium text-foreground;
}

.form-hint {
    @apply block text-xs text-gray-400 mt-1 dark:text-slate-500;
}

.form-input, .form-select, .form-textarea {
    @apply w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-brand-primary focus:border-brand-primary text-sm
           dark:bg-slate-700 dark:border-slate-600 dark:text-slate-200 dark:placeholder-slate-400;
}

.form-textarea {
    @apply resize-none;
}

/* Edytor */
.editor-toolbar {
    @apply flex items-center gap-1 p-2 border border-b-0 border-gray-300 rounded-t bg-gray-50 text-sm
           dark:bg-slate-700 dark:border-slate-600;
}

.editor-toolbar button {
    @apply px-2 py-1 hover:bg-gray-200 rounded text-gray-600
           dark:hover:bg-slate-600 dark:text-slate-300;
}

.toolbar-separator {
    @apply w-px h-5 bg-gray-300 mx-1 dark:bg-slate-600;
}

.editor-toolbar + .form-textarea,
.editor-toolbar + .description-editor {
    @apply rounded-t-none;
}

.description-editor {
    @apply overflow-auto;
}

.description-editor:empty::before {
    content: attr(data-placeholder);
    @apply text-foreground-subtle;
}

.color-picker-popover {
    @apply min-w-[140px] surface border border-slate-200 dark:border-slate-600 rounded-lg shadow-xl;
}

.color-swatch {
    @apply w-8 h-8 rounded-md border border-slate-200 dark:border-slate-600 transition-transform hover:scale-110 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-1;
}

/* Przyciski */
.icon-btn {
    @apply p-2 border border-gray-300 rounded hover:bg-gray-50 text-gray-600
           dark:border-slate-600 dark:hover:bg-slate-700 dark:text-slate-400;
}

.add-client-link {
    @apply flex items-center gap-1 text-sm text-amber-600 hover:text-amber-800 mt-2
           dark:text-amber-400 dark:hover:text-amber-300;
}

.btn-send {
    @apply w-full px-4 py-2 gradient-brand text-white rounded hover:opacity-90 font-medium flex items-center justify-center gap-2;
}

.btn-primary {
    @apply px-4 py-2 gradient-brand text-white rounded hover:opacity-90 font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center;
}

.btn-success {
    @apply px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 font-medium disabled:opacity-50;
}

.btn-sm {
    @apply text-sm py-1.5;
}

.btn-link {
    @apply text-amber-600 hover:text-amber-800 flex items-center gap-1 text-sm
           dark:text-amber-400 dark:hover:text-amber-300;
}

.btn-icon-danger {
    @apply p-2 text-red-500 hover:bg-red-50 rounded dark:hover:bg-red-900/20;
}

/* Status */
.status-select {
    @apply pr-8;
}

.status-badge {
    @apply inline-block px-3 py-1 rounded-full text-xs font-medium;
}

/* Profil - akordeon */
.profile-accordion {
    @apply border rounded-lg dark:border-slate-700;
}

.profile-accordion-btn {
    @apply w-full flex items-center justify-between px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors
           dark:text-slate-300 dark:hover:bg-slate-700;
}

.profile-accordion-body {
    @apply px-4 pb-4 pt-1;
}

/* Mini formularz klienta */
.new-client-mini {
    @apply mt-3 p-3 bg-gray-50 rounded-lg border
           dark:bg-slate-700 dark:border-slate-600;
}

/* Zamówienia */
.section-title {
    @apply text-lg font-semibold text-gray-800 mb-4 dark:text-slate-200;
}

.products-list {
    @apply border rounded-lg dark:border-slate-700;
}

.products-header {
    @apply grid gap-2 p-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase
           dark:bg-slate-700 dark:text-slate-400;
    grid-template-columns: 1fr 80px 100px 40px;
}

.product-row {
    @apply grid gap-2 p-3 border-t items-center dark:border-slate-700;
    grid-template-columns: 1fr 80px 100px 40px;
}

.order-total {
    @apply text-right;
}

.total-value {
    @apply text-xl font-bold text-gray-900 ml-2 dark:text-slate-100;
}

.orders-list, .invoices-list {
    @apply space-y-3;
}

.order-item, .invoice-item {
    @apply p-4 border rounded-lg dark:border-slate-700;
}

.order-header, .invoice-header {
    @apply flex items-center justify-between mb-2;
}

.order-id, .invoice-number {
    @apply font-semibold text-brand-primary;
}

.order-date {
    @apply text-sm text-foreground-muted;
}

.tracking-badge {
    @apply inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium
           bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300;
}

.tracking-badge-clickable {
    @apply cursor-pointer hover:bg-emerald-200 dark:hover:bg-emerald-800/60
           focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1
           disabled:opacity-60 disabled:cursor-not-allowed transition-colors;
}

.order-footer {
    @apply flex items-center justify-between;
}

.invoice-details {
    @apply flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500 mb-2 dark:text-slate-400;
}

.invoice-amount {
    @apply text-lg font-bold text-right dark:text-slate-100;
}

/* Stany */
.loading-state, .empty-state {
    @apply text-center py-8 text-foreground-muted;
}

/* NIP */
.nip-result {
    @apply mt-3 p-4 bg-green-50 rounded-lg border border-green-200
           dark:bg-green-900/20 dark:border-green-800;
}

.nip-error {
    @apply mt-3 p-4 bg-red-50 rounded-lg border border-red-200 text-red-700
           dark:bg-red-900/20 dark:border-red-800 dark:text-red-400;
}

.result-row {
    @apply flex py-1;
}

.result-row .label {
    @apply text-gray-500 w-24 dark:text-slate-400;
}

.result-row .value {
    @apply font-medium dark:text-slate-200;
}

/* Podsumowanie faktur */
.invoices-summary {
    @apply mt-4 p-4 bg-gray-50 rounded-lg dark:bg-slate-700;
}

.summary-row {
    @apply flex justify-between py-1 dark:text-slate-300;
}

/* Footer */
.modal-footer {
    @apply flex items-center justify-end gap-3 px-6 py-4 border-t bg-gray-50
           dark:bg-slate-800 dark:border-slate-700;
}

.btn-save {
    @apply px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 font-medium flex items-center disabled:opacity-50;
}
.btn-save-stay {
    @apply px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 font-medium flex items-center disabled:opacity-50;
}

.btn-restore {
    @apply px-4 py-2 gradient-brand text-white rounded hover:opacity-90 font-medium flex items-center disabled:opacity-50;
}

.btn-delete {
    @apply px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50 font-medium flex items-center
           dark:bg-slate-700 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-600;
}

.btn-cancel {
    @apply px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded hover:bg-gray-50 font-medium flex items-center
           dark:bg-slate-700 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-600;
}

.btn-secondary {
    @apply px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 font-medium flex items-center disabled:opacity-50 disabled:cursor-not-allowed
           dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600;
}

/* Oferta - email templates */
.warning-box {
    @apply flex items-center gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800
           dark:bg-amber-900/20 dark:border-amber-700 dark:text-amber-300;
}

.email-template-section {
    @apply p-5 bg-gray-50 rounded-lg border
           dark:bg-slate-700 dark:border-slate-600;
}

.recipient-box {
    @apply flex items-center gap-2 p-3 bg-white border rounded-lg
           dark:bg-slate-800 dark:border-slate-600;
}

.empty-templates {
    @apply text-center py-8 dark:text-slate-400;
}

/* Modal podglądu emaila */
.email-preview-modal {
    @apply fixed inset-0 bg-black/60 flex items-center justify-center z-[60] p-4 dark:bg-black/80;
}

.email-preview-content {
    @apply bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col
           dark:bg-slate-800;
}

.email-preview-header {
    @apply flex items-center justify-between px-6 py-4 border-b bg-gray-50
           dark:bg-slate-800 dark:border-slate-700;
}

.email-preview-meta {
    @apply px-6 py-3 border-b bg-gray-50 space-y-1
           dark:bg-slate-700 dark:border-slate-700;
}

.meta-row {
    @apply flex items-center text-sm dark:text-slate-300;
}

.meta-label {
    @apply w-16 text-foreground-muted;
}

.email-preview-body {
    @apply flex-1 overflow-auto p-6 surface;
}

.email-preview-footer {
    @apply flex items-center justify-end gap-3 px-6 py-4 border-t bg-gray-50
           dark:bg-slate-800 dark:border-slate-700;
}

/* === Semantic overrides — działają w obu motywach === */
.modal-overlay,
.email-preview-modal {
    background: rgba(0, 0, 0, 0.65) !important;
    backdrop-filter: blur(4px);
}

.client-modal,
.email-preview-content {
    background: var(--color-surface) !important;
    border: 1px solid var(--color-border);
}

.modal-header,
.modal-footer,
.email-preview-header,
.email-preview-footer {
    background: var(--color-surface-2) !important;
    border-color: var(--color-border) !important;
}

.email-preview-meta {
    background: var(--color-surface-2) !important;
    border-color: var(--color-border) !important;
    color: var(--color-foreground);
}

.meta-row {
    color: var(--color-foreground) !important;
}

.close-btn {
    color: var(--color-muted-foreground) !important;
}

.close-btn:hover {
    background: var(--color-surface-elevated) !important;
    color: var(--color-foreground) !important;
}

.tabs {
    border-color: var(--color-border) !important;
}

.tab-btn {
    color: var(--color-muted-foreground) !important;
    background: transparent !important;
}

.tab-btn:hover {
    color: var(--color-foreground) !important;
    background: var(--color-surface-elevated) !important;
}

.tab-btn.active {
    color: var(--brand-primary) !important;
    background: var(--color-surface) !important;
    border-color: var(--brand-primary) !important;
}

.modal-content {
    background: var(--color-surface) !important;
    color: var(--color-foreground);
}

.form-hint {
    color: var(--color-subtle) !important;
}

.form-input,
.form-select,
.form-textarea {
    background: var(--color-surface-elevated) !important;
    border-color: var(--color-border-bright) !important;
    color: var(--color-foreground) !important;
}

.form-input::placeholder,
.form-textarea::placeholder {
    color: var(--color-subtle) !important;
}

.editor-toolbar {
    background: var(--color-surface-2) !important;
    border-color: var(--color-border-bright) !important;
}

.editor-toolbar button {
    color: var(--color-foreground-muted) !important;
}

.editor-toolbar button:hover {
    background: var(--color-surface-elevated) !important;
    color: var(--color-foreground) !important;
}

.toolbar-separator {
    background: var(--color-border) !important;
}

.color-picker-popover {
    background: var(--color-surface) !important;
    border-color: var(--color-border-bright) !important;
}

.color-swatch {
    border-color: var(--color-border) !important;
}

.icon-btn {
    background: var(--color-surface-elevated) !important;
    border-color: var(--color-border-bright) !important;
    color: var(--color-foreground) !important;
}

.icon-btn:hover {
    opacity: 0.85;
}

.add-client-link,
.btn-link {
    color: var(--brand-primary) !important;
}

.add-client-link:hover,
.btn-link:hover {
    color: var(--brand-secondary) !important;
}

.profile-accordion {
    border-color: var(--color-border) !important;
}

.profile-accordion-btn {
    color: var(--color-foreground) !important;
}

.profile-accordion-btn:hover {
    background: var(--color-surface-elevated) !important;
}

.new-client-mini {
    background: var(--color-surface-elevated) !important;
    border-color: var(--color-border) !important;
}

.section-title {
    color: var(--color-foreground) !important;
}

.products-list {
    border-color: var(--color-border) !important;
}

.products-header {
    background: var(--color-surface-2) !important;
    color: var(--color-muted-foreground) !important;
}

.product-row {
    border-color: var(--color-border) !important;
}

.total-value {
    color: var(--color-foreground) !important;
}

.order-item,
.invoice-item {
    border-color: var(--color-border) !important;
    background: var(--color-surface-elevated) !important;
}

.invoice-details {
    color: var(--color-muted-foreground) !important;
}

.invoice-amount {
    color: var(--color-foreground) !important;
}

.result-row .label {
    color: var(--color-muted-foreground) !important;
}

.result-row .value {
    color: var(--color-foreground) !important;
}

.invoices-summary {
    background: var(--color-surface-elevated) !important;
}

.summary-row {
    color: var(--color-foreground) !important;
}

.btn-delete,
.btn-cancel {
    background: var(--color-surface-elevated) !important;
    border-color: var(--color-border-bright) !important;
    color: var(--color-foreground) !important;
}

.btn-delete:hover,
.btn-cancel:hover {
    opacity: 0.85;
}

.btn-secondary {
    background: var(--color-surface-elevated) !important;
    color: var(--color-foreground) !important;
}

.btn-secondary:hover {
    opacity: 0.85;
}

.email-template-section,
.recipient-box {
    background: var(--color-surface-elevated) !important;
    border-color: var(--color-border) !important;
}

.empty-templates {
    color: var(--color-muted-foreground) !important;
}

.email-preview-body {
    background: var(--color-surface) !important;
    color: var(--color-foreground);
}
</style>
