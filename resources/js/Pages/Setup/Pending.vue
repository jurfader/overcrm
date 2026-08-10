<script setup>
import { Head, router } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import BrandLogo from '@/Components/UI/BrandLogo.vue';

defineProps({
    brand: { type: Object, default: () => ({}) },
});

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <Head title="Trwa konfiguracja" />

    <div class="min-h-screen flex items-center justify-center p-6 bg-background">
        <div class="w-full max-w-md text-center space-y-6">
            <div class="flex justify-center">
                <BrandLogo size="lg" />
            </div>

            <div class="glass-card rounded-xl p-8 space-y-4">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl gradient-brand">
                    <Icons name="cog" class="w-7 h-7 text-white" />
                </div>

                <h1 class="text-xl font-semibold text-foreground">Trwa konfiguracja systemu</h1>
                <p class="text-sm text-foreground-muted">
                    Administrator kończy wstępne ustawienia. Zaloguj się ponownie za chwilę —
                    do tego czasu system jest niedostępny.
                </p>

                <p v-if="brand.support_email" class="text-xs text-foreground-subtle">
                    Pytania:
                    <a :href="`mailto:${brand.support_email}`" class="text-brand-primary hover:underline">{{ brand.support_email }}</a>
                </p>

                <div class="flex justify-center gap-2 pt-2">
                    <Button variant="outline" @click="router.reload()">
                        <Icons name="refresh" class="w-4 h-4" />
                        Odśwież
                    </Button>
                    <Button variant="ghost" @click="logout">
                        <Icons name="logout" class="w-4 h-4" />
                        Wyloguj
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
