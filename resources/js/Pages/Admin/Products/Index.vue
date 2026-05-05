<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Textarea from '@/Components/Textarea.vue';
import Switch from '@/Components/UI/Switch.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    products:   { type: Object, required: true },
    categories: { type: Array,  default: () => [] },
    units:      { type: Array,  required: true },
    vatRates:   { type: Array,  required: true },
    filters:    { type: Object, default: () => ({}) },
});

const filters = reactive({
    q:           props.filters.q || '',
    category:    props.filters.category || '',
    only_active: !!props.filters.only_active,
});

let searchTimeout;
function applyFilters() {
    router.get(route('admin.products.index'), {
        q:           filters.q || undefined,
        category:    filters.category || undefined,
        only_active: filters.only_active ? 1 : undefined,
    }, { preserveState: true, replace: true });
}
function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
}

// === Form modal ===
const showModal = ref(false);
const editing = ref(null);
const form = useForm({
    sku: '', name: '', description: '', category: '',
    unit: 'szt', price_net: 0, vat_rate: 23,
    stock: 0, track_stock: false, active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.unit = 'szt'; form.vat_rate = 23; form.active = true;
    showModal.value = true;
}
function openEdit(product) {
    editing.value = product;
    form.sku         = product.sku ?? '';
    form.name        = product.name;
    form.description = product.description ?? '';
    form.category    = product.category ?? '';
    form.unit        = product.unit;
    form.price_net   = parseFloat(product.price_net);
    form.vat_rate    = product.vat_rate;
    form.stock       = parseFloat(product.stock);
    form.track_stock = product.track_stock;
    form.active      = product.active;
    showModal.value  = true;
}
function submit() {
    if (editing.value) {
        form.put(route('admin.products.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        form.post(route('admin.products.store'), {
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; },
        });
    }
}

// === Delete ===
const showDelete = ref(false);
const toDelete = ref(null);
function confirmDelete(p) { toDelete.value = p; showDelete.value = true; }
function doDelete() {
    if (!toDelete.value) return;
    router.delete(route('admin.products.destroy', toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => { showDelete.value = false; toDelete.value = null; },
    });
}

const previewGross = computed(() => {
    return (parseFloat(form.price_net) || 0) * (1 + (parseInt(form.vat_rate) || 0) / 100);
});
function fmt(n) {
    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(n || 0);
}
</script>

<template>
    <Head title="Produkty" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Produkty</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Magazyn produktów i usług używanych w zamówieniach klientów</p>
            </div>
            <Button @click="openCreate">
                <Icons name="plus" class="w-4 h-4" />
                Nowy produkt
            </Button>
        </div>

        <!-- Filtry -->
        <div class="glass-card rounded-lg p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <Input v-model="filters.q" @input="onSearch" placeholder="Szukaj po nazwie, SKU, kategorii..." />
            </div>
            <select v-model="filters.category" @change="applyFilters"
                class="h-9 rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground">
                <option value="">Wszystkie kategorie</option>
                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
            </select>
            <label class="flex items-center gap-2 px-3 surface-elevated rounded-md cursor-pointer">
                <input type="checkbox" v-model="filters.only_active" @change="applyFilters"
                    class="rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                <span class="text-sm text-foreground">Tylko aktywne</span>
            </label>
        </div>

        <!-- Tabela -->
        <div class="glass-card rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-surface-2/50 border-b border-border">
                        <tr class="text-xs uppercase text-foreground-muted">
                            <th class="px-4 py-3 text-left font-medium tracking-wider">SKU</th>
                            <th class="px-4 py-3 text-left font-medium tracking-wider">Nazwa</th>
                            <th class="px-4 py-3 text-left font-medium tracking-wider">Kategoria</th>
                            <th class="px-4 py-3 text-left font-medium tracking-wider">Jedn.</th>
                            <th class="px-4 py-3 text-right font-medium tracking-wider">Cena netto</th>
                            <th class="px-4 py-3 text-right font-medium tracking-wider">VAT</th>
                            <th class="px-4 py-3 text-right font-medium tracking-wider">Cena brutto</th>
                            <th class="px-4 py-3 text-right font-medium tracking-wider">Stan</th>
                            <th class="px-4 py-3 text-right font-medium tracking-wider"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="p in products.data" :key="p.id" class="hover:bg-surface-elevated/50 transition-colors" :class="{ 'opacity-50': !p.active }">
                            <td class="px-4 py-3 text-sm text-foreground-muted font-mono">{{ p.sku || '—' }}</td>
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-foreground">{{ p.name }}</p>
                                <p v-if="p.description" class="text-xs text-foreground-muted truncate max-w-xs">{{ p.description }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-foreground-muted">{{ p.category || '—' }}</td>
                            <td class="px-4 py-3 text-sm text-foreground-muted">{{ p.unit }}</td>
                            <td class="px-4 py-3 text-sm text-right text-foreground font-mono">{{ fmt(p.price_net) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-foreground-muted">{{ p.vat_rate }}%</td>
                            <td class="px-4 py-3 text-sm text-right text-foreground font-mono font-semibold">{{ fmt(p.price_gross ?? (p.price_net * (1 + p.vat_rate/100))) }}</td>
                            <td class="px-4 py-3 text-sm text-right">
                                <span v-if="p.track_stock" :class="p.stock > 0 ? 'text-success' : 'text-destructive'">{{ Number(p.stock).toFixed(p.unit === 'szt' ? 0 : 3) }}</span>
                                <span v-else class="text-foreground-subtle">—</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openEdit(p)" class="p-1.5 rounded text-foreground-muted hover:text-brand-primary hover:bg-surface-elevated transition-colors">
                                        <Icons name="edit" class="w-4 h-4" />
                                    </button>
                                    <button @click="confirmDelete(p)" class="p-1.5 rounded text-foreground-muted hover:text-destructive hover:bg-destructive/10 transition-colors">
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!products.data?.length">
                            <td colspan="9" class="px-4 py-12 text-center text-foreground-subtle">
                                <Icons name="shopping-cart" class="w-12 h-12 mx-auto mb-3 opacity-40" />
                                <p>Brak produktów. Dodaj pierwszy klikając „Nowy produkt".</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="products.links" />
        </div>
    </div>

    <!-- Form modal -->
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100"
                    leave-active-class="transition duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 style="background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);" @click.self="showModal = false">
                <div class="glass-card rounded-xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col"
                     style="background: var(--color-surface);">
                    <header class="px-6 py-4 border-b border-border flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-foreground">{{ editing ? 'Edytuj produkt' : 'Nowy produkt' }}</h2>
                        <button type="button" @click="showModal = false" class="p-1 rounded hover:bg-surface-elevated text-foreground-muted hover:text-foreground transition-colors">
                            <Icons name="close" class="w-5 h-5" />
                        </button>
                    </header>

                    <form @submit.prevent="submit" class="p-6 space-y-4 overflow-y-auto">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Nazwa <span class="text-destructive">*</span></label>
                                <Input v-model="form.name" required maxlength="200" />
                                <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">SKU</label>
                                <Input v-model="form.sku" maxlength="60" placeholder="opcjonalnie" />
                                <p v-if="form.errors.sku" class="text-xs text-destructive">{{ form.errors.sku }}</p>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-foreground">Opis</label>
                            <Textarea v-model="form.description" :rows="2" maxlength="2000" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Kategoria</label>
                                <Input v-model="form.category" maxlength="80" list="cat-list" placeholder="np. Napoje" />
                                <datalist id="cat-list">
                                    <option v-for="c in categories" :key="c" :value="c" />
                                </datalist>
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Jednostka</label>
                                <select v-model="form.unit" class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground">
                                    <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">VAT (%)</label>
                                <select v-model.number="form.vat_rate" class="h-9 w-full rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground">
                                    <option v-for="r in vatRates" :key="r" :value="r">{{ r }}%</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Cena netto (PLN)</label>
                                <Input v-model.number="form.price_net" type="number" step="0.01" min="0" required />
                                <p class="text-xs text-foreground-muted">Brutto: <strong class="text-foreground">{{ fmt(previewGross) }}</strong></p>
                            </div>
                            <div class="space-y-1">
                                <label class="text-sm font-medium text-foreground">Stan magazynowy</label>
                                <Input v-model.number="form.stock" type="number" step="0.001" min="0" :disabled="!form.track_stock" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="flex items-center gap-3 surface-elevated rounded-md p-3 cursor-pointer">
                                <Switch v-model="form.track_stock" />
                                <div>
                                    <p class="text-sm font-medium text-foreground">Śledź stan magazynowy</p>
                                    <p class="text-xs text-foreground-muted">Wyłącz dla usług / produktów bez limitu</p>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 surface-elevated rounded-md p-3 cursor-pointer">
                                <Switch v-model="form.active" />
                                <div>
                                    <p class="text-sm font-medium text-foreground">Aktywny</p>
                                    <p class="text-xs text-foreground-muted">Wyłączone nie pojawią się w wyborze pozycji zamówienia</p>
                                </div>
                            </label>
                        </div>
                    </form>

                    <footer class="px-6 py-4 border-t border-border flex justify-end gap-3 bg-surface-2">
                        <Button type="button" variant="secondary" @click="showModal = false">Anuluj</Button>
                        <Button type="button" @click="submit" :loading="form.processing" :disabled="!form.name.trim()">
                            <Icons name="check" class="w-4 h-4" />
                            {{ editing ? 'Zapisz zmiany' : 'Dodaj produkt' }}
                        </Button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>

    <ConfirmModal
        :show="showDelete"
        title="Usuń produkt"
        :message="`Czy na pewno usunąć produkt '${toDelete?.name}'? Soft-delete — można go odzyskać z bazy.`"
        confirm-text="Tak, usuń"
        @confirm="doDelete"
        @cancel="showDelete = false"
    />
</template>
