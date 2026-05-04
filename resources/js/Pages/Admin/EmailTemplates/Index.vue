<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

defineProps({
    templates: Array,
    categories: Object,
    availableVariables: Object,
});

function deleteTemplate(template) {
    if (confirm('Czy na pewno chcesz usunąć ten szablon?')) {
        router.delete(route('admin.email-templates.destroy', template.id));
    }
}

function duplicateTemplate(template) {
    router.post(route('admin.email-templates.duplicate', template.id));
}

const categoryColors = {
    offer: 'bg-amber-100 text-amber-800',
    reminder: 'bg-blue-100 text-blue-800',
    notification: 'bg-purple-100 text-purple-800',
    other: 'bg-slate-100 text-slate-800',
};
</script>

<template>
    <Head title="Szablony Email" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold gradient-brand-text">Szablony Email</h1>
                <p class="text-foreground-muted text-sm mt-1">Zarządzaj szablonami wiadomości email</p>
            </div>
            <Link
                :href="route('admin.email-templates.create')"
                class="inline-flex items-center px-4 py-2 gradient-brand text-white rounded-lg hover:opacity-90 transition-colors"
            >
                <Icons name="plus" class="w-5 h-5 mr-2" />
                Nowy szablon
            </Link>
        </div>

        <!-- Lista szablonów -->
        <div class="glass-card overflow-hidden">
            <div v-if="templates.length === 0" class="p-12 text-center text-foreground-muted">
                <Icons name="document" class="w-12 h-12 mx-auto mb-4 text-foreground-muted" />
                <p class="text-lg font-medium">Brak szablonów</p>
                <p class="mt-1">Utwórz pierwszy szablon email</p>
            </div>

            <table v-else class="min-w-full">
                <thead class="bg-surface-2/50 border-b border-border">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Nazwa</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Kategoria</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Temat</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-foreground-muted uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-foreground-muted uppercase tracking-wider">Akcje</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="template in templates" :key="template.id" class="hover:bg-surface-elevated/50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mr-3">
                                    <Icons name="document" class="w-5 h-5 text-amber-600" />
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-foreground">{{ template.name }}</div>
                                    <div class="text-sm text-foreground-muted">{{ template.slug }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="['px-2 py-1 text-xs font-medium rounded-full', categoryColors[template.category]]">
                                {{ categories[template.category] }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-foreground truncate max-w-xs">{{ template.subject }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span v-if="template.is_active" class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                Aktywny
                            </span>
                            <span v-else class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">
                                Nieaktywny
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <Link
                                :href="route('admin.email-templates.edit', template.id)"
                                class="text-amber-600 hover:text-amber-900 mr-3"
                            >
                                Edytuj
                            </Link>
                            <button
                                @click="duplicateTemplate(template)"
                                class="text-blue-600 hover:text-blue-900 mr-3"
                            >
                                Duplikuj
                            </button>
                            <button
                                @click="deleteTemplate(template)"
                                class="text-red-600 hover:text-red-900"
                            >
                                Usuń
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Dostępne zmienne -->
        <div class="glass-card p-6">
            <h3 class="text-lg font-semibold text-foreground mb-4">Dostępne zmienne</h3>
            <p class="text-sm text-foreground-muted mb-4">Możesz użyć poniższych zmiennych w treści szablonu. Zostaną one automatycznie zastąpione danymi.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <div
                    v-for="(description, variable) in availableVariables"
                    :key="variable"
                    class="flex items-center p-3 bg-surface-2/50 rounded-lg"
                >
                    <code class="text-sm font-mono bg-surface-elevated px-2 py-1 rounded text-amber-700">{{ variable }}</code>
                    <span class="ml-3 text-sm text-foreground-muted">{{ description }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
