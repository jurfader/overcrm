<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    users: Array,
});

const emit = defineEmits(['close', 'created']);

const form = ref({
    name: '',
    company_name: '',
    email: '',
    phone: '',
    nip: '',
    website: '',
    address: '',
    city: '',
    source: 'manual',
    assigned_to: '',
    notes: '',
});

const errors = ref({});
const submitting = ref(false);

function submit() {
    submitting.value = true;
    errors.value = {};

    router.post(route('leads.store'), form.value, {
        onSuccess: () => emit('created'),
        onError: (errs) => { errors.value = errs; },
        onFinish: () => { submitting.value = false; },
    });
}
</script>

<template>
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black/50 z-40" @click="emit('close')" />

    <!-- Modal -->
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
            <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Nowy lead</h2>
                <button @click="emit('close')" class="text-slate-400 hover:text-slate-600">
                    <Icons name="close" class="w-5 h-5" />
                </button>
            </div>

            <form class="px-6 py-4 space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Osoba kontaktowa *</label>
                        <input v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                        <p v-if="errors.name" class="text-xs text-red-500 mt-1">{{ errors.name }}</p>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Firma</label>
                        <input v-model="form.company_name" type="text" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email</label>
                        <input v-model="form.email" type="email" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Telefon</label>
                        <input v-model="form.phone" type="text" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">NIP</label>
                        <input v-model="form.nip" type="text" maxlength="10" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Miasto</label>
                        <input v-model="form.city" type="text" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Strona www</label>
                        <input v-model="form.website" type="text" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Źródło</label>
                        <select v-model="form.source" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            <option value="manual">Ręczny</option>
                            <option value="google_maps">Google Maps</option>
                            <option value="gus">GUS</option>
                            <option value="csv_import">Import CSV</option>
                            <option value="other">Inny</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Przypisz do</label>
                        <select v-model="form.assigned_to" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                            <option value="">— Nieprzypisany —</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Notatki</label>
                        <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="emit('close')" class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-800">Anuluj</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium hover:bg-amber-600 disabled:opacity-50 transition-colors">
                        {{ submitting ? 'Zapisywanie...' : 'Dodaj leada' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
