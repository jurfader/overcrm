<script setup>
import { Link } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import WidgetBody from '@/Components/Dashboard/WidgetBody.vue';

defineProps({
    data: { type: Array, default: () => [] },
});

function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    const today = new Date(); today.setHours(0,0,0,0);
    const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);
    const target = new Date(dt); target.setHours(0,0,0,0);
    if (target.getTime() === today.getTime()) return 'Dziś';
    if (target.getTime() === tomorrow.getTime()) return 'Jutro';
    return dt.toLocaleDateString('pl-PL', { weekday: 'short', day: 'numeric', month: 'short' });
}
</script>

<template>
    <WidgetBody>
        <div v-if="!data?.length" class="text-center py-8 text-foreground-subtle">
            <Icons name="calendar" class="w-10 h-10 mx-auto mb-3 opacity-50" />
            <p class="text-sm">Brak zaplanowanych wizyt.</p>
        </div>
        <ul v-else class="divide-y divide-border -my-3">
            <li v-for="visit in data" :key="visit.id" class="py-3 flex items-start gap-3">
                <div class="w-1 h-12 rounded-full shrink-0" :style="{ backgroundColor: visit.color || 'var(--brand-primary)' }"></div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-foreground truncate">{{ visit.title || 'Wizyta' }}</p>
                        <span class="text-xs font-mono text-foreground-muted shrink-0">
                            {{ formatDate(visit.visit_date) }}<span v-if="visit.visit_time"> · {{ visit.visit_time?.slice(0,5) }}</span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-xs text-foreground-muted truncate">
                        <Link v-if="visit.client" :href="route('clients.show', visit.client.id)" class="hover:text-brand-primary transition-colors truncate">
                            {{ visit.client.name }}
                        </Link>
                        <span v-else>—</span>
                        <span v-if="visit.user" class="ml-auto shrink-0">{{ visit.user.name }}</span>
                    </div>
                </div>
            </li>
        </ul>

        <template v-if="data?.length" #footer>
            <Link :href="route('calendar.index')" class="text-xs text-brand-primary hover:underline font-medium">
                Otwórz kalendarz →
            </Link>
        </template>
    </WidgetBody>
</template>
