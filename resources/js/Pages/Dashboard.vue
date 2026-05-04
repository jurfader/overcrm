<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import WidgetBase from '@/Components/Dashboard/WidgetBase.vue';
import { getWidget } from '@/Components/Dashboard/widgets';

const props = defineProps({
    layout:     { type: Array,  default: () => [] }, // [{ key, width, visible }]
    widgetMeta: { type: Array,  default: () => [] }, // [{ key, title, icon, component, default_width, min_width, description, module }]
    widgetData: { type: Object, default: () => ({}) }, // { [key]: data }
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || '');

// Lokalna kopia layoutu — edytujemy bez API roundtripa, zapisujemy na koniec
const localLayout = ref([...props.layout]);
const editMode = ref(false);
const saving = ref(false);
const showPicker = ref(false);

// Mapa widgetMeta po kluczu — szybki lookup
const metaByKey = computed(() => Object.fromEntries(props.widgetMeta.map(m => [m.key, m])));

// Widoczne widgety w aktualnej kolejności (do renderu)
const visibleWidgets = computed(() => localLayout.value.filter(w => w.visible));

// Widgety do dodania (są w meta, ale ukryte/nieobecne w layoucie)
const availableToAdd = computed(() => {
    const inLayout = new Set(localLayout.value.filter(w => w.visible).map(w => w.key));
    return props.widgetMeta.filter(m => !inLayout.has(m.key));
});

function getComponent(key) {
    const meta = metaByKey.value[key];
    if (!meta) return null;
    return getWidget(meta.component);
}

function widgetTitle(key) { return metaByKey.value[key]?.title || key; }
function widgetIcon(key)  { return metaByKey.value[key]?.icon || 'puzzle'; }
function widgetData(key)  { return props.widgetData?.[key] ?? null; }

function removeWidget(key) {
    const idx = localLayout.value.findIndex(w => w.key === key);
    if (idx === -1) return;
    localLayout.value[idx] = { ...localLayout.value[idx], visible: false };
}

function resizeWidget(key, width) {
    const idx = localLayout.value.findIndex(w => w.key === key);
    if (idx === -1) return;
    localLayout.value[idx] = { ...localLayout.value[idx], width };
}

function addWidget(meta) {
    const existing = localLayout.value.find(w => w.key === meta.key);
    if (existing) {
        const idx = localLayout.value.findIndex(w => w.key === meta.key);
        localLayout.value[idx] = { ...existing, visible: true };
    } else {
        localLayout.value.push({ key: meta.key, width: meta.default_width, visible: true });
    }
    showPicker.value = false;
}

function saveLayout() {
    saving.value = true;
    router.post(route('dashboard.save-layout'), { layout: localLayout.value }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            editMode.value = false;
        },
    });
}

function cancelEdit() {
    localLayout.value = [...props.layout];
    editMode.value = false;
    showPicker.value = false;
}

function resetLayout() {
    if (!confirm('Przywrócić domyślny układ dashboardu? Twoje zmiany zostaną utracone.')) return;
    router.delete(route('dashboard.reset-layout'), {
        preserveScroll: true,
        onSuccess: () => { editMode.value = false; },
    });
}

// CSS class dla width (1-12 col)
function widthClass(width) {
    return `col-span-12 md:col-span-${Math.max(3, width)} xl:col-span-${width}`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="p-6 space-y-6 animate-fade-in">
        <!-- Header -->
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Witaj, {{ userName }}!</h1>
                <p class="text-sm text-foreground-muted mt-0.5">Oto Twój dashboard — dostosuj go pod siebie</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <template v-if="editMode">
                    <Button variant="outline" @click="showPicker = !showPicker">
                        <Icons name="plus" class="w-4 h-4" />
                        Dodaj widget
                    </Button>
                    <Button variant="ghost" @click="resetLayout" class="text-destructive">
                        Przywróć domyślny
                    </Button>
                    <Button variant="secondary" @click="cancelEdit">Anuluj</Button>
                    <Button @click="saveLayout" :loading="saving">
                        <Icons name="check" class="w-4 h-4" />
                        Zapisz układ
                    </Button>
                </template>
                <template v-else>
                    <Button variant="outline" @click="editMode = true">
                        <Icons name="edit" class="w-4 h-4" />
                        Edytuj układ
                    </Button>
                </template>
            </div>
        </div>

        <!-- Widget picker (edit mode) -->
        <div v-if="editMode && showPicker" class="glass-card rounded-lg p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-foreground">Dodaj widget</h2>
                <button @click="showPicker = false" class="p-1 rounded hover:bg-surface-elevated text-foreground-muted">
                    <Icons name="close" class="w-4 h-4" />
                </button>
            </div>
            <div v-if="!availableToAdd.length" class="text-center py-6 text-foreground-subtle text-sm">
                Wszystkie dostępne widgety są już na dashboardzie.
            </div>
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <button v-for="meta in availableToAdd" :key="meta.key"
                        type="button"
                        @click="addWidget(meta)"
                        class="surface-elevated rounded-lg p-4 text-left hover:border-brand-primary/50 transition-colors group">
                    <div class="flex items-center gap-2 mb-2">
                        <Icons :name="meta.icon" class="w-4 h-4 text-brand-primary" />
                        <span class="text-sm font-medium text-foreground">{{ meta.title }}</span>
                        <span v-if="meta.module" class="ml-auto text-[10px] uppercase tracking-wider text-foreground-subtle">{{ meta.module }}</span>
                    </div>
                    <p v-if="meta.description" class="text-xs text-foreground-muted">{{ meta.description }}</p>
                    <p class="text-[10px] text-foreground-subtle mt-2">+ Dodaj</p>
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!visibleWidgets.length" class="glass-card rounded-lg p-12 text-center">
            <Icons name="dashboard" class="w-12 h-12 mx-auto text-foreground-subtle opacity-50 mb-4" />
            <h2 class="text-lg font-semibold text-foreground mb-2">Dashboard jest pusty</h2>
            <p class="text-sm text-foreground-muted mb-4">Wszystkie widgety są ukryte. Dodaj coś, żeby zobaczyć dane.</p>
            <Button @click="editMode = true; showPicker = true">
                <Icons name="plus" class="w-4 h-4" />
                Dodaj widget
            </Button>
        </div>

        <!-- Grid widgetów -->
        <draggable
            v-else
            v-model="localLayout"
            :item-key="'key'"
            handle=".widget-drag-handle"
            :animation="200"
            ghost-class="opacity-50"
            class="grid grid-cols-12 gap-4 auto-rows-min"
            tag="div"
            :disabled="!editMode"
        >
            <template #item="{ element: widget }">
                <div v-show="widget.visible" :class="widthClass(widget.width)">
                    <WidgetBase
                        :title="widgetTitle(widget.key)"
                        :icon="widgetIcon(widget.key)"
                        :width="widget.width"
                        :edit-mode="editMode"
                        @remove="removeWidget(widget.key)"
                        @resize="(w) => resizeWidget(widget.key, w)"
                    >
                        <component
                            :is="getComponent(widget.key) || 'div'"
                            v-if="getComponent(widget.key)"
                            :data="widgetData(widget.key)"
                        />
                        <div v-else class="p-6 text-center text-foreground-subtle text-sm">
                            Brak komponentu Vue dla "{{ widget.key }}".
                            <p class="text-xs mt-1">Moduł może być wyłączony lub nie zarejestrował komponentu.</p>
                        </div>
                    </WidgetBase>
                </div>
            </template>
        </draggable>
    </div>
</template>

