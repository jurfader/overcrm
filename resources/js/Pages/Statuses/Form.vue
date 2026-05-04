<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    status: Object,
    types: Object,
    colors: Object,
    nextOrder: Number,
});

const isEditing = !!props.status;

const form = useForm({
    name: props.status?.name || '',
    slug: props.status?.slug || '',
    type: props.status?.type || 'new',
    color: props.status?.color || '#3B82F6',
    order: props.status?.order ?? props.nextOrder ?? 0,
    is_default: props.status?.is_default || false,
    is_visible: props.status?.is_visible ?? true,
    is_final: props.status?.is_final || false,
    description: props.status?.description || '',
});

function generateSlug() {
    form.slug = form.name
        .toLowerCase()
        .replace(/[ąàáâãäå]/g, 'a')
        .replace(/[ćç]/g, 'c')
        .replace(/[ęèéêë]/g, 'e')
        .replace(/[ìíîï]/g, 'i')
        .replace(/[łl]/g, 'l')
        .replace(/[ńñ]/g, 'n')
        .replace(/[óòôõö]/g, 'o')
        .replace(/[śš]/g, 's')
        .replace(/[ùúûü]/g, 'u')
        .replace(/[ýÿ]/g, 'y')
        .replace(/[źżž]/g, 'z')
        .replace(/[^a-z0-9]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');
}

function submit() {
    if (isEditing) {
        form.put(route('statuses.update', props.status.id));
    } else {
        form.post(route('statuses.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edytuj status' : 'Nowy status'" />

    <div class="max-w-2xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edytuj status' : 'Nowy status' }}</h1>
                <p class="text-gray-600">{{ isEditing ? 'Zaktualizuj dane statusu' : 'Dodaj nowy status zadań' }}</p>
            </div>
            <Link :href="route('statuses.index')">
                <Button variant="secondary">
                    <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                    Powrót
                </Button>
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <Card title="Dane statusu">
                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nazwa *</label>
                            <Input v-model="form.name" @blur="!isEditing && generateSlug()" />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Identyfikator (slug) *</label>
                            <Input v-model="form.slug" />
                            <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Typ *</label>
                            <Select v-model="form.type" :options="types" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Kolor *</label>
                            <div class="flex gap-2">
                                <input type="color" v-model="form.color" class="h-10 w-14 rounded border border-gray-300 cursor-pointer" />
                                <Input v-model="form.color" class="flex-1" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Kolejność</label>
                            <Input v-model="form.order" type="number" min="0" />
                        </div>
                    </div>

                    <!-- Predefiniowane kolory -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Szybki wybór koloru</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="(name, hex) in colors"
                                :key="hex"
                                type="button"
                                @click="form.color = hex"
                                class="w-8 h-8 rounded-full border-2 transition-transform hover:scale-110"
                                :class="form.color === hex ? 'border-gray-900 ring-2 ring-offset-2 ring-gray-400' : 'border-transparent'"
                                :style="{ backgroundColor: hex }"
                                :title="name"
                            />
                        </div>
                    </div>

                    <!-- Podgląd -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Podgląd</label>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" :style="{ backgroundColor: form.color + '20', color: form.color }">
                            {{ form.name || 'Nazwa statusu' }}
                        </span>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Opis</label>
                        <Textarea v-model="form.description" :rows="2" placeholder="Opcjonalny opis statusu..." />
                    </div>
                </div>
            </Card>

            <Card title="Opcje">
                <div class="space-y-4">
                    <label class="flex items-center">
                        <input type="checkbox" v-model="form.is_default" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <span class="ml-2 text-sm text-gray-700">
                            <strong>Domyślny status</strong> - automatycznie przypisywany do nowych zadań
                        </span>
                    </label>

                    <label class="flex items-center">
                        <input type="checkbox" v-model="form.is_visible" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <span class="ml-2 text-sm text-gray-700">
                            <strong>Widoczny</strong> - status pojawia się na listach wyboru
                        </span>
                    </label>

                    <label class="flex items-center">
                        <input type="checkbox" v-model="form.is_final" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <span class="ml-2 text-sm text-gray-700">
                            <strong>Status końcowy</strong> - oznacza zamknięcie zadania (np. Wykonane, Anulowane)
                        </span>
                    </label>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('statuses.index')">
                    <Button variant="secondary" type="button">Anuluj</Button>
                </Link>
                <Button :loading="form.processing">
                    {{ isEditing ? 'Zapisz zmiany' : 'Dodaj status' }}
                </Button>
            </div>
        </form>
    </div>
</template>
