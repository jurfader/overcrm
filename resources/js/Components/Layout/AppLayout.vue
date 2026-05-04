<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Sidebar from './Sidebar.vue';
import Navbar from './Navbar.vue';
import FlashMessages from '../UI/FlashMessages.vue';

const page = usePage();
const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);

const user = computed(() => page.props.auth.user);
const appName = computed(() => page.props.app?.name || 'Planner');
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Mobile sidebar backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-gray-900/50 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <Sidebar
            :open="sidebarOpen"
            :collapsed="sidebarCollapsed"
            @close="sidebarOpen = false"
            @toggle-collapse="sidebarCollapsed = !sidebarCollapsed"
        />

        <!-- Main content -->
        <div
            class="transition-all duration-300"
            :class="[
                sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'
            ]"
        >
            <!-- Navbar -->
            <Navbar
                :user="user"
                @toggle-sidebar="sidebarOpen = !sidebarOpen"
            />

            <!-- Page content -->
            <main class="py-6">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <!-- Flash messages -->
                    <FlashMessages class="mb-6" />

                    <!-- Page header slot -->
                    <div v-if="$slots.header" class="mb-6">
                        <slot name="header" />
                    </div>

                    <!-- Main content slot -->
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
