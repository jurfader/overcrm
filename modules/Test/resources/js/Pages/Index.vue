<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    title: String,
});
</script>

<template>
    <Head :title="title" />
    
    <div class="p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">{{ title }}</h1>
        
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-gray-600">
                Witaj w module Test! Edytuj ten plik, aby dostosować moduł.
            </p>
        </div>
    </div>
</template>