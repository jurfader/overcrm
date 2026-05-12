<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';

const props = defineProps({
    calls: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const direction = ref(props.filters.direction || '');
const status = ref(props.filters.status || '');

function applyFilters() {
    router.get(route('ringostat.calls-log'), {
        direction: direction.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
}

function reset() {
    direction.value = '';
    status.value = '';
    applyFilters();
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' });
}

function fmtDuration(seconds) {
    const s = Math.floor(seconds || 0);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

const statusColor = (s) => {
    const v = (s || '').toLowerCase();
    if (['answered', 'connected'].includes(v)) return 'bg-success/15 text-success border-success/30';
    if (['missed', 'no answer', 'rejected'].includes(v)) return 'bg-destructive/15 text-destructive border-destructive/30';
    if (['busy', 'failed'].includes(v)) return 'bg-warning/15 text-warning border-warning/30';
    return 'bg-foreground-muted/15 text-foreground-muted border-foreground-muted/30';
};
</script>

<template>
    <Head title="Ringostat — dziennik połączeń" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Dziennik połączeń</h1>
                <p class="text-sm text-foreground-muted mt-0.5">
                    Połączenia z Ringostat.net zapisywane przez webhook.
                </p>
            </div>
            <Link :href="route('ringostat.config')" class="text-sm text-brand-primary hover:underline">
                Konfiguracja
            </Link>
        </div>

        <!-- Filtry -->
        <section class="glass-card rounded-xl p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="space-y-1">
                    <label class="text-xs font-medium text-foreground-muted">Kierunek</label>
                    <select v-model="direction" @change="applyFilters" class="surface-elevated border border-border rounded-md px-3 py-1.5 text-sm">
                        <option value="">Wszystkie</option>
                        <option value="in">Przychodzące</option>
                        <option value="out">Wychodzące</option>
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium text-foreground-muted">Status</label>
                    <select v-model="status" @change="applyFilters" class="surface-elevated border border-border rounded-md px-3 py-1.5 text-sm">
                        <option value="">Wszystkie</option>
                        <option value="answered">Odebrane</option>
                        <option value="missed">Nieodebrane</option>
                        <option value="busy">Zajęte</option>
                        <option value="failed">Nieudane</option>
                    </select>
                </div>
                <Button variant="outline" size="sm" @click="reset">Wyczyść</Button>
            </div>
        </section>

        <!-- Tabela -->
        <section class="glass-card rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="surface-elevated text-xs uppercase text-foreground-muted">
                        <tr>
                            <th class="text-left px-3 py-2">Data</th>
                            <th class="text-left px-3 py-2">Kierunek</th>
                            <th class="text-left px-3 py-2">Numer</th>
                            <th class="text-left px-3 py-2">Klient</th>
                            <th class="text-left px-3 py-2">Pracownik</th>
                            <th class="text-right px-3 py-2">Czas</th>
                            <th class="text-left px-3 py-2">Status</th>
                            <th class="text-center px-3 py-2">Nagranie</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="call in calls.data" :key="call.id" class="hover:bg-surface-2/40">
                            <td class="px-3 py-2 whitespace-nowrap text-xs">{{ fmtDate(call.started_at) }}</td>
                            <td class="px-3 py-2">
                                <Icons :name="call.direction === 'in' ? 'arrow-down-left' : 'arrow-up-right'"
                                       :class="['w-4 h-4', call.direction === 'in' ? 'text-info' : 'text-success']" />
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ call.direction === 'in' ? call.caller : call.callee }}
                            </td>
                            <td class="px-3 py-2">
                                <Link v-if="call.client" :href="`/clients/${call.client.id}`" class="text-brand-primary hover:underline">
                                    {{ call.client.name }}
                                </Link>
                                <span v-else class="text-foreground-muted text-xs">brak dopasowania</span>
                            </td>
                            <td class="px-3 py-2 text-xs">{{ call.user?.name || '—' }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs">{{ fmtDuration(call.billsec || call.duration) }}</td>
                            <td class="px-3 py-2">
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border', statusColor(call.status)]">
                                    {{ call.status || '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <a v-if="call.recording_url" :href="call.recording_url" target="_blank" class="text-brand-primary hover:underline">
                                    <Icons name="play" class="w-4 h-4 inline" />
                                </a>
                                <span v-else class="text-foreground-muted text-xs">—</span>
                            </td>
                        </tr>
                        <tr v-if="calls.data.length === 0">
                            <td colspan="8" class="px-3 py-8 text-center text-sm text-foreground-muted">
                                Brak połączeń. Sprawdź czy webhook Ringostat jest skonfigurowany.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="calls.last_page > 1" class="flex items-center justify-between px-4 py-3 surface-elevated text-xs">
                <span class="text-foreground-muted">
                    {{ calls.from }}–{{ calls.to }} z {{ calls.total }}
                </span>
                <div class="flex gap-1">
                    <Link v-for="link in calls.links" :key="link.label"
                          :href="link.url || '#'"
                          v-html="link.label"
                          :class="['px-2 py-1 rounded',
                                   link.active ? 'bg-brand-primary text-white' : 'text-foreground hover:bg-surface-2',
                                   !link.url && 'pointer-events-none opacity-40']" />
                </div>
            </div>
        </section>
    </div>
</template>
