<script setup>
import { computed } from 'vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    data: { type: Object, required: true },
});

const deadlines = computed(() => props.data?.deadlines ?? []);

function fmtAmount(grosze) {
    return ((grosze ?? 0) / 100).toLocaleString('pl-PL', { style: 'currency', currency: 'PLN' });
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('pl-PL', { day: '2-digit', month: 'short' });
}

function daysLeft(iso) {
    if (!iso) return null;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const target = new Date(iso); target.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

const urgencyColor = (days) => {
    if (days === null) return 'text-foreground-muted';
    if (days <= 3) return 'text-destructive';
    if (days <= 7) return 'text-warning';
    return 'text-foreground-muted';
};

const kindIcon = (kind) => {
    if (kind?.startsWith('ZUS')) return 'shield';
    if (kind?.startsWith('PIT')) return 'document-text';
    if (kind?.startsWith('JPK')) return 'file-text';
    return 'calendar';
};
</script>

<template>
    <div class="space-y-2">
        <div v-if="deadlines.length === 0" class="text-sm text-foreground-muted text-center py-6">
            Brak nadchodzących terminów płatności
        </div>
        <div v-for="(item, idx) in deadlines" :key="idx"
             class="flex items-center gap-3 px-3 py-2 rounded-md surface-elevated">
            <Icons :name="kindIcon(item.kind)" class="w-4 h-4 text-brand-primary shrink-0" />
            <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-foreground truncate">{{ item.kind }}</div>
                <div class="text-xs text-foreground-muted">{{ item.period }}</div>
            </div>
            <div class="text-right shrink-0">
                <div class="text-sm font-mono">{{ fmtAmount(item.amount) }}</div>
                <div :class="['text-xs', urgencyColor(daysLeft(item.payment_date))]">
                    {{ fmtDate(item.payment_date) }}
                    <template v-if="daysLeft(item.payment_date) !== null">
                        ({{ daysLeft(item.payment_date) >= 0 ? `za ${daysLeft(item.payment_date)} dni` : `${-daysLeft(item.payment_date)} dni po terminie` }})
                    </template>
                </div>
            </div>
        </div>
        <a v-if="deadlines.length > 0" href="https://app.infakt.pl/ksiegowosc" target="_blank"
           class="block text-center text-xs text-brand-primary hover:underline pt-1">
            Otwórz księgowość w inFakt →
        </a>
    </div>
</template>
