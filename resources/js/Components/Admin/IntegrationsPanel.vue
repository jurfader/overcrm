<script setup>
import { reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';

const props = defineProps({
    providers: {
        type: Object,
        required: true,
        // shape: { product: { active, options[] }, order: {...}, invoice: {...} }
    },
});

const form = reactive({
    provider_product: props.providers.product?.active ?? 'local',
    provider_order:   props.providers.order?.active   ?? 'local',
    provider_invoice: props.providers.invoice?.active ?? 'none',
});

const saving = computed(() => router.processing);

const sections = [
    {
        key:         'product',
        title:       'Magazyn produktów',
        description: 'Skąd CRM pobiera produkty — używane w pickerze pozycji zamówień, w magazynie admin /products oraz w autocomplete formularzy.',
        icon:        'shopping-cart',
        formKey:     'provider_product',
    },
    {
        key:         'order',
        title:       'Zamówienia',
        description: 'Gdzie CRM zapisuje nowe zamówienia tworzone z modala klienta. Lokalny generuje PDF lokalnie, moduły zewnętrzne tworzą w swoim systemie.',
        icon:        'document-text',
        formKey:     'provider_order',
    },
    {
        key:         'invoice',
        title:       'Faktury',
        description: 'Czym wystawiać faktury z zamówień. Domyślnie wyłączone — generuje się tylko PDF zamówienia. Aktywuj moduł żeby automatycznie tworzyć faktury w zewnętrznym systemie.',
        icon:        'mail',
        formKey:     'provider_invoice',
    },
];

function activeMeta(category, key) {
    return props.providers[category]?.options?.find(o => o.key === key);
}

function save() {
    router.post(route('admin.integrations.update'), { ...form }, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <div class="rounded-lg p-3 bg-info/10 border border-info/30 flex gap-3">
            <Icons name="info" class="w-5 h-5 text-info shrink-0 mt-0.5" />
            <div class="text-xs text-foreground">
                <p class="font-medium mb-1">Jak działają providery?</p>
                <p class="text-foreground-muted">
                    Każda kategoria ma <strong>aktywnego providera</strong> — controller (np. dodawanie zamówienia z modala klienta) automatycznie używa wybranej opcji.
                    Lokalny provider to domyślny CORE (zapisuje w bazie OVERCRM, generuje PDF). Po włączeniu modułu (np. Apilo) jego provider pojawi się w wyborze
                    i admin może go aktywować — wtedy zamówienia trafią do systemu zewnętrznego zamiast lokalnej bazy.
                </p>
            </div>
        </div>

        <section v-for="s in sections" :key="s.key" class="surface-elevated rounded-lg p-5">
            <div class="flex items-start gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg gradient-brand flex items-center justify-center shrink-0">
                    <Icons :name="s.icon" class="w-5 h-5 text-white" />
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-foreground">{{ s.title }}</h3>
                    <p class="text-xs text-foreground-muted mt-0.5">{{ s.description }}</p>
                </div>
            </div>

            <div class="space-y-2">
                <label v-for="opt in providers[s.key]?.options || []" :key="opt.key"
                       :class="['flex items-start gap-3 p-3 rounded-md border-2 cursor-pointer transition-colors',
                                 form[s.formKey] === opt.key
                                     ? 'border-brand-primary bg-brand-primary/5'
                                     : 'border-border hover:border-border-bright bg-surface']">
                    <input type="radio" :value="opt.key" v-model="form[s.formKey]"
                           class="mt-0.5 text-brand-primary focus:ring-brand-primary" />
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium text-foreground">{{ opt.label }}</span>
                            <span v-if="opt.key === 'local' || opt.key === 'none'" class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-foreground-muted/15 text-foreground-muted">
                                CORE
                            </span>
                            <span v-else class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-info/15 text-info">
                                MODUŁ
                            </span>
                            <span v-if="opt.error" class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-destructive/15 text-destructive">
                                BŁĄD
                            </span>
                            <span v-if="opt.meta?.supports_management === false" class="text-[10px] tracking-wider px-1.5 py-0.5 rounded bg-warning/15 text-warning">
                                read-only
                            </span>
                        </div>
                        <div v-if="opt.error" class="text-xs text-destructive mt-1 font-mono">{{ opt.error }}</div>
                        <div v-if="opt.meta?.supports_pdf === false && s.key === 'order'" class="text-xs text-foreground-muted mt-0.5">
                            Ten provider nie obsługuje generowania PDF — link „PDF" w liście zamówień nie pojawi się.
                        </div>
                        <div v-if="opt.meta?.available === false" class="text-xs text-warning mt-0.5">
                            Provider zarejestrowany ale nieskonfigurowany (sprawdź ustawienia modułu).
                        </div>
                    </div>
                </label>

                <p v-if="!providers[s.key]?.options?.length" class="text-sm text-foreground-muted py-2">
                    Brak dostępnych providerów. Tylko default CORE jest zarejestrowany.
                </p>
            </div>
        </section>

        <div class="flex justify-end">
            <Button @click="save" :loading="saving">
                <Icons name="check" class="w-4 h-4" />
                Zapisz konfigurację providerów
            </Button>
        </div>
    </div>
</template>
