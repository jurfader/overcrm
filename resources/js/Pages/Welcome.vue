<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icons.vue';
import { computed } from 'vue';

const appSettings = computed(() => usePage().props.appSettings || {});
const appLogo = computed(() => appSettings.value.app_logo);
const appName = computed(() => appSettings.value.app_name || 'Planner');
const companyName = computed(() => appSettings.value.company_name || 'Planner');
</script>

<template>
    <Head :title="appName" />

    <div class="min-h-screen bg-gradient-to-br from-red-500 via-red-600 to-red-700 flex items-center justify-center relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-10">
            <svg class="w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none">
                <pattern id="grid" width="8" height="8" patternUnits="userSpaceOnUse">
                    <path d="M 8 0 L 0 0 0 8" fill="none" stroke="white" stroke-width="0.5"/>
                </pattern>
                <rect width="100" height="100" fill="url(#grid)"/>
            </svg>
        </div>

        <!-- Decorative circles -->
        <div class="absolute top-1/4 right-1/4 w-96 h-96 bg-white/5 rounded-full blur-3xl"></div>
        <div class="absolute bottom-1/4 left-1/4 w-64 h-64 bg-white/5 rounded-full blur-2xl"></div>

        <!-- Content -->
        <div class="relative z-10 text-center px-4">
            <!-- Logo -->
            <div v-if="appLogo" class="mb-8">
                <img :src="appLogo" :alt="appName" class="h-24 mx-auto" />
            </div>
            <div v-else class="inline-flex items-center justify-center w-24 h-24 bg-white/10 backdrop-blur-sm rounded-3xl mb-8 shadow-2xl">
                <span class="text-white font-bold text-4xl">{{ companyName.substring(0, 2) }}</span>
            </div>

            <h1 class="text-5xl md:text-6xl font-bold text-white mb-4">
                {{ companyName }}
            </h1>
            <h2 class="text-3xl md:text-4xl font-light text-red-100 mb-6">
                {{ appName }}
            </h2>
            <p class="text-xl text-red-100 mb-12 max-w-lg mx-auto">
                Nowoczesny system zarządzania zadaniami i klientami
            </p>

            <!-- Features -->
            <div class="flex flex-wrap justify-center gap-6 mb-12">
                <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm px-5 py-3 rounded-xl">
                    <Icon name="check" class="w-5 h-5 text-white" />
                    <span class="text-white font-medium">Laravel 11</span>
                </div>
                <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm px-5 py-3 rounded-xl">
                    <Icon name="star" class="w-5 h-5 text-white" />
                    <span class="text-white font-medium">Vue 3</span>
                </div>
                <div class="flex items-center gap-3 bg-white/10 backdrop-blur-sm px-5 py-3 rounded-xl">
                    <Icon name="settings" class="w-5 h-5 text-white" />
                    <span class="text-white font-medium">Modułowy</span>
                </div>
            </div>

            <!-- CTA Button -->
            <a
                href="/login"
                class="inline-flex items-center gap-3 bg-white text-red-600 font-semibold px-8 py-4 rounded-xl hover:bg-red-50 transition-all duration-200 shadow-xl hover:shadow-2xl hover:-translate-y-0.5"
            >
                Rozpocznij
                <Icon name="arrow-right" class="w-5 h-5" />
            </a>

            <!-- Footer -->
            <p class="mt-16 text-red-200 text-sm">
                &copy; 2026 {{ companyName }} &middot; Wersja 2.0.0
            </p>
        </div>
    </div>
</template>
