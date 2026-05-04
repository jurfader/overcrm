<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Bars3Icon,
    BellIcon,
    MagnifyingGlassIcon,
    SunIcon,
    MoonIcon,
    ArrowRightOnRectangleIcon,
    UserCircleIcon,
    CogIcon,
} from '@heroicons/vue/24/outline';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';

const props = defineProps({
    user: Object,
});

const emit = defineEmits(['toggle-sidebar']);

const isDark = ref(document.documentElement.classList.contains('dark'));
const searchQuery = ref('');

const toggleDarkMode = () => {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
};

const logout = () => {
    router.post(route('logout'));
};

// Initialize theme
if (localStorage.getItem('theme') === 'dark' || 
    (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
    isDark.value = true;
}
</script>

<template>
    <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
            <!-- Left side -->
            <div class="flex items-center gap-4">
                <!-- Mobile menu button -->
                <button
                    @click="emit('toggle-sidebar')"
                    class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 lg:hidden"
                >
                    <Bars3Icon class="w-6 h-6" />
                </button>

                <!-- Search -->
                <div class="hidden sm:flex items-center">
                    <div class="relative">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Szukaj..."
                            class="w-64 pl-10 pr-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 border-0 rounded-lg focus:ring-2 focus:ring-primary-500 dark:text-gray-100 dark:placeholder-gray-400"
                        />
                    </div>
                </div>
            </div>

            <!-- Right side -->
            <div class="flex items-center gap-2">
                <!-- Dark mode toggle -->
                <button
                    @click="toggleDarkMode"
                    class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                >
                    <MoonIcon v-if="!isDark" class="w-5 h-5" />
                    <SunIcon v-else class="w-5 h-5" />
                </button>

                <!-- Notifications -->
                <button
                    class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 relative"
                >
                    <BellIcon class="w-5 h-5" />
                    <span class="absolute top-1 right-1 w-2 h-2 bg-primary-500 rounded-full"></span>
                </button>

                <!-- User menu -->
                <Menu as="div" class="relative">
                    <MenuButton class="flex items-center gap-3 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="w-8 h-8 rounded-full bg-primary-600 flex items-center justify-center text-white text-sm font-medium">
                            {{ user?.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </div>
                        <div class="hidden md:block text-left">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                {{ user?.name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ user?.email }}
                            </p>
                        </div>
                    </MenuButton>

                    <transition
                        enter-active-class="transition duration-100 ease-out"
                        enter-from-class="transform scale-95 opacity-0"
                        enter-to-class="transform scale-100 opacity-100"
                        leave-active-class="transition duration-75 ease-in"
                        leave-from-class="transform scale-100 opacity-100"
                        leave-to-class="transform scale-95 opacity-0"
                    >
                        <MenuItems
                            class="absolute right-0 mt-2 w-56 origin-top-right bg-white dark:bg-gray-800 rounded-xl shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none divide-y divide-gray-100 dark:divide-gray-700"
                        >
                            <div class="px-4 py-3">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ user?.name }}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                                    {{ user?.email }}
                                </p>
                            </div>

                            <div class="py-1">
                                <MenuItem v-slot="{ active }">
                                    <Link
                                        :href="route('dashboard')"
                                        class="flex items-center gap-3 px-4 py-2 text-sm"
                                        :class="[
                                            active
                                                ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100'
                                                : 'text-gray-700 dark:text-gray-300'
                                        ]"
                                    >
                                        <UserCircleIcon class="w-5 h-5" />
                                        Mój profil
                                    </Link>
                                </MenuItem>
                                <MenuItem v-slot="{ active }">
                                    <Link
                                        :href="route('dashboard')"
                                        class="flex items-center gap-3 px-4 py-2 text-sm"
                                        :class="[
                                            active
                                                ? 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100'
                                                : 'text-gray-700 dark:text-gray-300'
                                        ]"
                                    >
                                        <CogIcon class="w-5 h-5" />
                                        Ustawienia
                                    </Link>
                                </MenuItem>
                            </div>

                            <div class="py-1">
                                <MenuItem v-slot="{ active }">
                                    <button
                                        @click="logout"
                                        class="flex w-full items-center gap-3 px-4 py-2 text-sm"
                                        :class="[
                                            active
                                                ? 'bg-gray-100 dark:bg-gray-700 text-red-600'
                                                : 'text-red-600'
                                        ]"
                                    >
                                        <ArrowRightOnRectangleIcon class="w-5 h-5" />
                                        Wyloguj się
                                    </button>
                                </MenuItem>
                            </div>
                        </MenuItems>
                    </transition>
                </Menu>
            </div>
        </div>
    </header>
</template>
