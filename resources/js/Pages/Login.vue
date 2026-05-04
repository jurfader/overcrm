<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const appSettings = computed(() => usePage().props.appSettings || {});
const appLogo = computed(() => appSettings.value.app_logo);
const appName = computed(() => appSettings.value.app_name || 'Planner');
const companyName = computed(() => appSettings.value.company_name || 'Planner');
</script>

<template>
    <Head title="Logowanie" />

    <div class="min-h-screen flex">
        <!-- Left side -->
        <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-red-600 via-red-700 to-red-900 items-center justify-center p-12">
            <div class="text-center">
                <div v-if="appLogo" class="mb-6">
                    <img :src="appLogo" :alt="appName" class="h-20 mx-auto" />
                </div>
                <div v-else class="w-20 h-20 bg-white/20 backdrop-blur rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <span class="text-4xl">{{ companyName.substring(0, 2) }}</span>
                </div>
                <h1 class="text-4xl font-bold text-white mb-4">{{ appName }}</h1>
                <p class="text-xl text-white/80">Nowoczesny system zarządzania</p>
            </div>
        </div>

        <!-- Right side - Login form -->
        <div class="flex-1 flex items-center justify-center p-8 bg-gray-50">
            <div class="w-full max-w-md">
                <div class="lg:hidden text-center mb-8">
                    <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-12 mx-auto mb-2" />
                    <span v-else class="text-4xl">{{ companyName.substring(0, 2) }}</span>
                    <h1 class="text-2xl font-bold text-gray-900 mt-2">{{ appName }}</h1>
                </div>

                <h2 class="text-2xl font-bold text-gray-900 mb-2">Zaloguj się</h2>
                <p class="text-gray-600 mb-8">Wprowadź dane, aby kontynuować</p>

                <form action="/dashboard" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input
                            type="email"
                            value="demo@chickenking.co"
                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-red-500 focus:border-transparent"
                            placeholder="twoj@email.pl"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hasło</label>
                        <input
                            type="password"
                            value="password"
                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-red-500 focus:border-transparent"
                            placeholder="••••••••"
                        />
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="flex items-center">
                            <input type="checkbox" class="rounded border-gray-300 text-red-600 focus:ring-red-500" />
                            <span class="ml-2 text-sm text-gray-600">Zapamiętaj mnie</span>
                        </label>
                        <a href="#" class="text-sm text-red-600 hover:text-red-700">Zapomniałeś hasła?</a>
                    </div>
                    <button
                        type="submit"
                        class="w-full py-3 bg-red-600 text-white font-semibold rounded-lg hover:bg-red-700 transition-colors"
                    >
                        Zaloguj się
                    </button>
                </form>

                <p class="mt-8 text-center text-sm text-gray-500">
                    © 2026 {{ companyName }}
                </p>
            </div>
        </div>
    </div>
</template>
