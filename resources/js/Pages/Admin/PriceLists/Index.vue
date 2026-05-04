<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    priceLists: {
        type: Array,
        default: () => [],
    },
});

const syncingId = ref(null);

function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
        return new Date(dateStr).toLocaleDateString('pl-PL', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return '—';
    }
}

function deletePriceList(pl) {
    if (confirm(`Czy na pewno chcesz usunąć cennik „${pl.name}"?`)) {
        router.delete(route('admin.price-lists.destroy', pl.id));
    }
}

async function syncPriceList(pl) {
    if (syncingId.value) return;
    syncingId.value = pl.id;
    try {
        // Pobierz świeży CSRF z cookie (Axios bootstrap ustawia go)
        const xsrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1];
        const meta = document.querySelector('meta[name="csrf-token"]')?.content;
        const headers = { 'Accept': 'application/json' };
        if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
        if (meta) headers['X-CSRF-TOKEN'] = meta;

        const res = await fetch(route('admin.price-lists.sync', pl.id), {
            method: 'POST',
            headers,
            credentials: 'include',
        });
        const data = await res.json();
        if (!res.ok) {
            alert(data.error || 'Błąd synchronizacji.');
        } else {
            router.reload({ preserveScroll: true });
        }
    } catch {
        alert('Błąd połączenia z serwerem.');
    } finally {
        syncingId.value = null;
    }
}
</script>

<template>
    <Head title="Cenniki — Admin" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Cenniki</h1>
                <p class="text-foreground-muted text-sm mt-1">Zarządzaj cennikami produktów</p>
            </div>
            <Link
                :href="route('admin.price-lists.create')"
                class="inline-flex items-center px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors"
            >
                <Icons name="plus" class="w-5 h-5 mr-2" />
                Nowy cennik
            </Link>
        </div>

        <div class="glass-card overflow-hidden">
            <div v-if="priceLists.length === 0" class="p-12 text-center text-foreground-muted">
                <Icons name="price-list" class="w-12 h-12 mx-auto mb-4 text-foreground-muted" />
                <p class="text-lg font-medium">Brak cenników</p>
                <p class="mt-1 text-sm">Utwórz pierwszy cennik</p>
            </div>

            <table v-else class="min-w-full">
                <thead class="bg-surface-2/50 border-b border-border">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Nazwa</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Slug</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Ostatnia synchron.</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-foreground-muted uppercase tracking-wider">Akcje</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="pl in priceLists" :key="pl.id" class="hover:bg-surface-elevated/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-medium text-foreground">{{ pl.name }}</div>
                            <div v-if="pl.description" class="text-sm text-foreground-muted truncate max-w-xs">{{ pl.description }}</div>
                        </td>
                        <td class="px-6 py-4 text-sm text-foreground-muted font-mono">{{ pl.slug }}</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-1">
                                <span v-if="pl.is_active" class="px-2 py-0.5 text-xs rounded-md bg-emerald-100 text-emerald-700">Aktywny</span>
                                <span v-else class="px-2 py-0.5 text-xs rounded-md surface-elevated text-foreground-muted">Nieaktywny</span>
                                <span v-if="pl.is_public" class="px-2 py-0.5 text-xs rounded-md bg-blue-100 text-blue-700">Publiczny</span>
                                <span v-if="pl.sync_from_fakturownia" class="px-2 py-0.5 text-xs rounded-md bg-purple-100 text-purple-700">Sync FK</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-foreground-muted">{{ formatDate(pl.last_synced_at) }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <a
                                    v-if="pl.is_public"
                                    :href="`/cennik/${pl.slug}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="p-1.5 text-foreground-muted hover:text-blue-500 rounded"
                                    title="Podgląd publiczny"
                                >
                                    <Icons name="eye" class="w-4 h-4" />
                                </a>
                                <button
                                    v-if="pl.sync_from_fakturownia"
                                    :disabled="syncingId === pl.id"
                                    class="p-1.5 text-foreground-muted hover:text-emerald-500 rounded disabled:opacity-40"
                                    title="Synchronizuj ceny z Fakturownią"
                                    @click="syncPriceList(pl)"
                                >
                                    <Icons name="refresh" class="w-4 h-4" :class="{ 'animate-spin': syncingId === pl.id }" />
                                </button>
                                <Link
                                    :href="route('admin.price-lists.edit', pl.id)"
                                    class="p-1.5 text-foreground-muted hover:text-brand-primary rounded"
                                    title="Edytuj"
                                >
                                    <Icons name="edit" class="w-4 h-4" />
                                </Link>
                                <button
                                    class="p-1.5 text-foreground-muted hover:text-red-500 rounded"
                                    title="Usuń"
                                    @click="deletePriceList(pl)"
                                >
                                    <Icons name="trash" class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
