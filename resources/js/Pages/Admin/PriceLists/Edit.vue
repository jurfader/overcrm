<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    priceList: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => !!props.priceList);

const form = ref({
    name: props.priceList?.name ?? '',
    slug: props.priceList?.slug ?? '',
    description: props.priceList?.description ?? '',
    is_active: props.priceList?.is_active ?? true,
    is_public: props.priceList?.is_public ?? false,
    sync_from_fakturownia: props.priceList?.sync_from_fakturownia ?? false,
    fakturownia_prefix: props.priceList?.fakturownia_prefix ?? '',
    html_content: '',
});

const htmlFile = ref(null);
const errors = ref({});
const submitting = ref(false);
const htmlMode = ref('file'); // 'file' | 'paste'

function slugify(str) {
    return str
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

watch(() => form.value.name, (val) => {
    if (!isEdit.value || !form.value.slug) {
        form.value.slug = slugify(val);
    }
});

function onFileChange(e) {
    htmlFile.value = e.target.files[0] || null;
}

function submit() {
    submitting.value = true;
    errors.value = {};

    const data = {
        name: form.value.name,
        slug: form.value.slug,
        description: form.value.description,
        is_active: form.value.is_active,
        is_public: form.value.is_public,
        sync_from_fakturownia: form.value.sync_from_fakturownia,
        fakturownia_prefix: form.value.fakturownia_prefix,
    };

    if (htmlMode.value === 'file' && htmlFile.value) {
        data.html_file = htmlFile.value;
    } else if (htmlMode.value === 'paste' && form.value.html_content) {
        data.html_content = form.value.html_content;
    }

    const url = isEdit.value
        ? route('admin.price-lists.update', props.priceList.id)
        : route('admin.price-lists.store');

    const method = isEdit.value ? router.put : router.post;

    // Jeśli mamy plik, użyj POST + _method
    if (data.html_file) {
        data._method = isEdit.value ? 'PUT' : undefined;
        router.post(url, data, {
            forceFormData: true,
            onError: (errs) => { errors.value = errs; },
            onFinish: () => { submitting.value = false; },
        });
    } else {
        (isEdit.value ? router.put : router.post)(url, data, {
            onError: (errs) => { errors.value = errs; },
            onFinish: () => { submitting.value = false; },
        });
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Edytuj cennik' : 'Nowy cennik'" />

    <div class="max-w-2xl space-y-6">
        <div class="flex items-center gap-4">
            <Link
                :href="route('admin.price-lists.index')"
                class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
            >
                <Icons name="arrow-left" class="w-5 h-5" />
            </Link>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">
                {{ isEdit ? 'Edytuj cennik' : 'Nowy cennik' }}
            </h1>
        </div>

        <form class="space-y-5 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6" @submit.prevent="submit">
            <!-- Nazwa -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Nazwa *</label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    placeholder="np. Cennik główny"
                />
                <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
            </div>

            <!-- Slug -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Slug (URL)</label>
                <div class="flex items-center rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
                    <span class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-r border-slate-300 dark:border-slate-600">/cennik/</span>
                    <input
                        v-model="form.slug"
                        type="text"
                        class="flex-1 bg-white dark:bg-slate-700 text-slate-900 dark:text-white px-3 py-2 text-sm focus:outline-none"
                        placeholder="cennik-glowny"
                    />
                </div>
                <p v-if="errors.slug" class="mt-1 text-xs text-red-600">{{ errors.slug[0] }}</p>
            </div>

            <!-- Opis -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Opis</label>
                <textarea
                    v-model="form.description"
                    rows="2"
                    class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    placeholder="Krótki opis cennika"
                />
            </div>

            <!-- Przełączniki -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Aktywny</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.is_public" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Publiczny (bez logowania)</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.sync_from_fakturownia" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-amber-500 focus:ring-amber-500" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">Sync z Fakturownią</span>
                </label>
            </div>

            <!-- Prefix Fakturowni -->
            <div v-if="form.sync_from_fakturownia">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Prefix produktów Fakturowni</label>
                <input
                    v-model="form.fakturownia_prefix"
                    type="text"
                    class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    placeholder="np. TH_"
                />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Tylko produkty których nazwa zaczyna się od tego prefiksu zostaną użyte do synchronizacji cen.</p>
            </div>

            <!-- Treść HTML -->
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Treść HTML cennika</label>

                <div class="flex gap-2 mb-3">
                    <button
                        type="button"
                        :class="htmlMode === 'file' ? 'bg-amber-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300'"
                        class="px-3 py-1.5 rounded text-sm font-medium transition-colors"
                        @click="htmlMode = 'file'"
                    >
                        Prześlij plik
                    </button>
                    <button
                        type="button"
                        :class="htmlMode === 'paste' ? 'bg-amber-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300'"
                        class="px-3 py-1.5 rounded text-sm font-medium transition-colors"
                        @click="htmlMode = 'paste'"
                    >
                        Wklej HTML
                    </button>
                </div>

                <div v-if="htmlMode === 'file'">
                    <input
                        type="file"
                        accept=".html,.htm"
                        class="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-medium file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100"
                        @change="onFileChange"
                    />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ isEdit ? 'Prześlij nowy plik, aby zastąpić obecny HTML. Zostaw puste, aby zachować obecny.' : 'Plik HTML cennika (maks. 10 MB).' }}
                    </p>
                </div>

                <div v-else>
                    <textarea
                        v-model="form.html_content"
                        rows="15"
                        class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-amber-500"
                        placeholder="Wklej tutaj pełny kod HTML cennika..."
                    />
                </div>
            </div>

            <!-- Akcje -->
            <div class="flex items-center justify-between pt-2">
                <Link
                    :href="route('admin.price-lists.index')"
                    class="text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
                >
                    Anuluj
                </Link>
                <div class="flex items-center gap-3">
                    <a
                        v-if="isEdit && priceList.slug"
                        :href="`/cennik/${priceList.slug}`"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-sm text-slate-600 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
                    >
                        <Icons name="eye" class="w-4 h-4" />
                        Podgląd
                    </a>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center px-5 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 disabled:opacity-60 transition-colors"
                    >
                        {{ submitting ? 'Zapisywanie…' : (isEdit ? 'Zapisz zmiany' : 'Utwórz cennik') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>
