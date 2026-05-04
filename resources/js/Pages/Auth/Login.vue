<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';
import BrandLogo from '@/Components/UI/BrandLogo.vue';
import ThemeToggle from '@/Components/UI/ThemeToggle.vue';

defineOptions({ layout: null });

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const page = usePage();
const brand = computed(() => page.props.brand || {});
const environmentBanner = computed(() => page.props.environmentBanner || '');
const year = new Date().getFullYear();

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
    <Head :title="`Logowanie — ${brand.name}`" />

    <div class="min-h-screen flex flex-col items-center justify-center px-4 relative">
        <!-- Banner środowiska -->
        <div v-if="environmentBanner" class="fixed top-0 left-0 right-0 z-50 px-3 py-1.5 text-center text-xs font-semibold gradient-brand text-white">
            {{ environmentBanner }}
        </div>

        <!-- Theme toggle w prawym górnym rogu -->
        <div class="absolute top-4 right-4 z-10">
            <ThemeToggle />
        </div>

        <!-- Karta logowania -->
        <div class="w-full max-w-md glass-card rounded-2xl p-8 sm:p-10 animate-fade-in">
            <!-- Logo + nazwa -->
            <div class="flex flex-col items-center mb-8">
                <BrandLogo size="lg" :show-name="false" class="mb-4" />
                <h1 class="text-2xl font-bold gradient-brand-text">{{ brand.name }}</h1>
                <p v-if="brand.company_name && brand.company_name !== brand.name" class="text-sm text-foreground-muted mt-1">
                    {{ brand.company_name }}
                </p>
            </div>

            <!-- Status (np. po reset password) -->
            <div v-if="status" class="mb-4 px-4 py-3 rounded-md bg-success/10 text-success text-sm border border-success/20">
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-foreground mb-1.5">Email</label>
                    <Input
                        id="email"
                        type="email"
                        v-model="form.email"
                        required
                        autofocus
                        placeholder="twoj@email.pl"
                        :invalid="!!form.errors.email"
                    />
                    <p v-if="form.errors.email" class="mt-1.5 text-xs text-destructive">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-foreground mb-1.5">Hasło</label>
                    <Input
                        id="password"
                        type="password"
                        v-model="form.password"
                        required
                        placeholder="••••••••"
                        :invalid="!!form.errors.password"
                    />
                    <p v-if="form.errors.password" class="mt-1.5 text-xs text-destructive">{{ form.errors.password }}</p>
                </div>

                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input
                        type="checkbox"
                        v-model="form.remember"
                        class="form-checkbox h-4 w-4 rounded border-border-bright bg-surface text-brand-primary focus:ring-2 focus:ring-brand-primary focus:ring-offset-0"
                    />
                    <span class="text-sm text-foreground-muted">Zapamiętaj mnie</span>
                </label>

                <Button type="submit" size="lg" :loading="form.processing" class="w-full">
                    {{ form.processing ? 'Logowanie...' : 'Zaloguj się' }}
                </Button>
            </form>

            <p class="mt-8 text-center text-xs text-foreground-subtle">
                © {{ year }} {{ brand.company_name || brand.name }}
            </p>
        </div>
    </div>
</template>
