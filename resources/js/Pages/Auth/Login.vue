<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';
import { computed } from 'vue';

defineOptions({
    layout: null
});

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const page = usePage();
const appSettings = computed(() => page.props.appSettings || {});
const isTestEnv = computed(() => page.props.isTestEnv ?? false);
const appLogo = computed(() => appSettings.value.app_logo);
const appName = computed(() => appSettings.value.app_name || 'Planner');
const companyName = computed(() => appSettings.value.company_name || 'Planner');

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Logowanie" />

    <div class="min-h-screen flex flex-col bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900">
        <div
            v-if="isTestEnv"
            class="fixed top-0 left-0 right-0 z-[110] bg-amber-500 text-slate-900 px-4 py-2.5 text-center text-base font-bold shadow-lg border-b-2 border-amber-600"
        >
            ⚠️ WERSJA TESTOWA APLIKACJI — test.crm.chickenking.co
        </div>
        <div class="flex-1 flex flex-col justify-center items-center" :class="{ 'pt-12': isTestEnv }">
        <div class="w-full max-w-md px-8 py-10 bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl border border-white/20">
            <!-- Logo -->
            <div class="text-center mb-8">
                <img v-if="appLogo" :src="appLogo" :alt="appName" class="h-16 mx-auto mb-3" />
                <h1 v-else class="text-3xl font-bold text-white mb-2">{{ companyName }}</h1>
                <p class="text-gray-400">{{ appName }}</p>
            </div>

            <div v-if="status" class="mb-4 text-sm text-green-400 bg-green-900/30 px-4 py-3 rounded-lg">
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-2">Email</label>
                    <input
                        id="email"
                        type="email"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="twoj@email.pl"
                        class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                    />
                    <p v-if="form.errors.email" class="mt-2 text-sm text-red-400">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-2">Hasło</label>
                    <input
                        id="password"
                        type="password"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                        class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                    />
                    <p v-if="form.errors.password" class="mt-2 text-sm text-red-400">{{ form.errors.password }}</p>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input
                            type="checkbox"
                            v-model="form.remember"
                            class="rounded bg-white/5 border-white/10 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0"
                        />
                        <span class="ml-2 text-sm text-gray-400">Zapamiętaj mnie</span>
                    </label>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-indigo-500/30 transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span v-if="form.processing">Logowanie...</span>
                    <span v-else>Zaloguj się</span>
                </button>
            </form>

            <div class="mt-8 text-center">
                <p class="text-gray-500 text-sm">© 2026 {{ companyName }}</p>
            </div>
        </div>
        </div>
    </div>
</template>
