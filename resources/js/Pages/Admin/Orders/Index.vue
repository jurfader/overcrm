<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';

const props = defineProps({
    orders:   { type: Object, required: true },
    stats:    { type: Object, required: true },
    filters:  { type: Object, default: () => ({}) },
    statuses: { type: Object, required: true },
});

const filters = reactive({
    q:      props.filters.q || '',
    status: props.filters.status || '',
    from:   props.filters.from || '',
    to:     props.filters.to || '',
});

let searchTimeout;
function applyFilters() {
    router.get(route('admin.orders.index'), {
        q: filters.q || undefined,
        status: filters.status || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    }, { preserveState: true, replace: true });
}
function onSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
}
function resetFilters() {
    filters.q = ''; filters.status = ''; filters.from = ''; filters.to = '';
    applyFilters();
}

function changeStatus(order, status) {
    router.patch(route('admin.orders.update-status', order.id), { status }, { preserveScroll: true });
}

const showDelete = ref(false);
const toDelete = ref(null);
function confirmDelete(o) { toDelete.value = o; showDelete.value = true; }
function doDelete() {
    if (!toDelete.value) return;
    router.delete(route('admin.orders.destroy', toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => { showDelete.value = false; toDelete.value = null; },
    });
}

const statusBadgeClass = {
    draft:       'bg-foreground-muted/15 text-foreground-muted',
    new:         'bg-info/15 text-info',
    in_progress: 'bg-warning/15 text-warning',
    completed:   'bg-success/15 text-success',
    cancelled:   'bg-destructive/15 text-destructive',
};

function fmt(n) {
    return new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }).format(n || 0);
}
</script>

<template>
    <Head title="Zamówienia" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Zamówienia</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Wszystkie zamówienia ze wszystkich klientów</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="surface-elevated rounded-lg p-4">
                <p class="text-xs text-foreground-muted uppercase tracking-wider">Wszystkie</p>
                <p class="text-2xl font-bold text-foreground mt-1">{{ stats.all }}</p>
            </div>
            <div class="surface-elevated rounded-lg p-4">
                <p class="text-xs text-info uppercase tracking-wider">Nowe</p>
                <p class="text-2xl font-bold text-info mt-1">{{ stats.new }}</p>
            </div>
            <div class="surface-elevated rounded-lg p-4">
                <p class="text-xs text-warning uppercase tracking-wider">W realizacji</p>
                <p class="text-2xl font-bold text-warning mt-1">{{ stats.in_progress }}</p>
            </div>
            <div class="surface-elevated rounded-lg p-4">
                <p class="text-xs text-success uppercase tracking-wider">Zrealizowane</p>
                <p class="text-2xl font-bold text-success mt-1">{{ stats.completed }}</p>
            </div>
            <div class="surface-elevated rounded-lg p-4">
                <p class="text-xs text-foreground-muted uppercase tracking-wider">Brutto / mies.</p>
                <p class="text-xl font-bold text-foreground mt-1 font-mono">{{ fmt(stats.gross_month) }}</p>
            </div>
        </div>

        <!-- Filtry -->
        <div class="glass-card rounded-lg p-4 grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="md:col-span-2">
                <Input v-model="filters.q" @input="onSearch" placeholder="Szukaj po numerze, kliencie, NIPie…" />
            </div>
            <select v-model="filters.status" @change="applyFilters"
                    class="h-9 rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground">
                <option value="">Wszystkie statusy</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <input type="date" v-model="filters.from" @change="applyFilters" placeholder="Od"
                   class="h-9 rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground" />
            <div class="flex gap-2">
                <input type="date" v-model="filters.to" @change="applyFilters" placeholder="Do"
                       class="flex-1 h-9 rounded-md border border-border-bright px-3 text-sm bg-surface-elevated text-foreground" />
                <Button variant="ghost" size="sm" @click="resetFilters" title="Resetuj filtry">
                    <Icons name="close" class="w-4 h-4" />
                </Button>
            </div>
        </div>

        <!-- Tabela -->
        <div class="glass-card rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-surface-2/50 border-b border-border">
                        <tr class="text-xs uppercase text-foreground-muted">
                            <th class="px-4 py-3 text-left tracking-wider font-medium">Numer</th>
                            <th class="px-4 py-3 text-left tracking-wider font-medium">Klient</th>
                            <th class="px-4 py-3 text-left tracking-wider font-medium">Data</th>
                            <th class="px-4 py-3 text-left tracking-wider font-medium">Status</th>
                            <th class="px-4 py-3 text-right tracking-wider font-medium">Pozycje</th>
                            <th class="px-4 py-3 text-right tracking-wider font-medium">Brutto</th>
                            <th class="px-4 py-3 text-left tracking-wider font-medium">Wystawił</th>
                            <th class="px-4 py-3 text-right tracking-wider font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="o in orders.data" :key="o.id" class="hover:bg-surface-elevated/50 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono text-sm font-semibold text-foreground">{{ o.number }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <Link v-if="o.client" :href="route('clients.show', o.client.id)" class="text-sm text-foreground hover:text-brand-primary">
                                    {{ o.client.name }}
                                </Link>
                                <span v-else class="text-foreground-subtle">—</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-foreground font-mono">
                                {{ o.order_date }}
                                <span v-if="o.delivery_date" class="block text-xs text-foreground-muted">→ {{ o.delivery_date }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <select :value="o.status" @change="changeStatus(o, $event.target.value)"
                                        :class="['text-xs px-2 py-1 rounded-full border-0 font-medium cursor-pointer focus:ring-1 focus:ring-brand-primary', statusBadgeClass[o.status]]">
                                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-foreground-muted">{{ o.items_count }}</td>
                            <td class="px-4 py-3 text-sm text-right text-foreground font-mono font-semibold">{{ fmt(o.total_gross) }}</td>
                            <td class="px-4 py-3 text-sm text-foreground-muted">{{ o.user_name || '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a :href="route('orders.pdf', o.id)" target="_blank"
                                       class="p-1.5 rounded text-foreground-muted hover:text-brand-primary hover:bg-surface-elevated transition-colors" title="Drukuj PDF">
                                        <Icons name="document-arrow-down" class="w-4 h-4" />
                                    </a>
                                    <button @click="confirmDelete(o)"
                                            class="p-1.5 rounded text-foreground-muted hover:text-destructive hover:bg-destructive/10 transition-colors" title="Usuń">
                                        <Icons name="trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!orders.data?.length">
                            <td colspan="8" class="px-4 py-12 text-center text-foreground-subtle">
                                <Icons name="document-text" class="w-12 h-12 mx-auto mb-3 opacity-40" />
                                <p>Brak zamówień. Utwórz pierwsze z modala klienta (zakładka "Zamówienia").</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="orders.links" />
        </div>
    </div>

    <ConfirmModal
        :show="showDelete"
        title="Usuń zamówienie"
        :message="`Czy na pewno usunąć zamówienie '${toDelete?.number}'? Soft-delete — można odzyskać z bazy.`"
        confirm-text="Tak, usuń"
        @confirm="doDelete"
        @cancel="showDelete = false"
    />
</template>
