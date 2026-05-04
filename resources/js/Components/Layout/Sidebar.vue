<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    HomeIcon,
    ClipboardDocumentListIcon,
    BuildingOfficeIcon,
    UsersIcon,
    EnvelopeIcon,
    ChartBarIcon,
    CogIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    XMarkIcon,
    ListBulletIcon,
    ViewColumnsIcon,
    CalendarIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    open: Boolean,
    collapsed: Boolean,
});

const emit = defineEmits(['close', 'toggle-collapse']);

const page = usePage();

// Icon mapping
const iconMap = {
    HomeIcon,
    ClipboardDocumentListIcon,
    BuildingOfficeIcon,
    UsersIcon,
    EnvelopeIcon,
    ChartBarIcon,
    CogIcon,
    ListBulletIcon,
    ViewColumnsIcon,
    CalendarIcon,
};

const getIcon = (iconName) => iconMap[iconName] || HomeIcon;

// Menu items from modules
const menuItems = computed(() => page.props.menu || []);

const isActive = (routeName) => {
    try {
        return route().current(routeName + '*');
    } catch {
        return false;
    }
};
</script>

<template>
    <!-- Mobile sidebar -->
    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-gray-900 lg:hidden transform transition-transform duration-300"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <!-- Logo -->
        <div class="flex h-16 items-center justify-between px-4 border-b border-gray-800">
            <Link href="/" class="flex items-center gap-3">
                <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold text-lg">P</span>
                </div>
                <span class="text-white font-semibold text-lg">Planner</span>
            </Link>
            <button
                @click="emit('close')"
                class="p-2 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800"
            >
                <XMarkIcon class="w-5 h-5" />
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-4 px-3 scrollbar-thin">
            <ul class="space-y-1">
                <li v-for="item in menuItems" :key="item.route">
                    <Link
                        :href="route(item.route)"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="[
                            isActive(item.route)
                                ? 'bg-primary-600 text-white'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                    >
                        <component :is="getIcon(item.icon)" class="w-5 h-5 flex-shrink-0" />
                        <span>{{ item.name }}</span>
                    </Link>

                    <!-- Children -->
                    <ul v-if="item.children?.length" class="mt-1 ml-8 space-y-1">
                        <li v-for="child in item.children" :key="child.route">
                            <Link
                                :href="route(child.route)"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors"
                                :class="[
                                    isActive(child.route)
                                        ? 'text-primary-400 bg-gray-800'
                                        : 'text-gray-400 hover:text-white hover:bg-gray-800'
                                ]"
                            >
                                <component v-if="child.icon" :is="getIcon(child.icon)" class="w-4 h-4" />
                                <span>{{ child.name }}</span>
                            </Link>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>
    </aside>

    <!-- Desktop sidebar -->
    <aside
        class="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-40 lg:flex lg:flex-col bg-gray-900 border-r border-gray-800 transition-all duration-300"
        :class="collapsed ? 'lg:w-20' : 'lg:w-64'"
    >
        <!-- Logo -->
        <div class="flex h-16 items-center justify-between px-4 border-b border-gray-800">
            <Link href="/" class="flex items-center gap-3">
                <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-white font-bold text-lg">P</span>
                </div>
                <span
                    v-if="!collapsed"
                    class="text-white font-semibold text-lg transition-opacity duration-200"
                >
                    Planner
                </span>
            </Link>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-4 px-3 scrollbar-thin">
            <ul class="space-y-1">
                <li v-for="item in menuItems" :key="item.route">
                    <Link
                        :href="route(item.route)"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors group relative"
                        :class="[
                            isActive(item.route)
                                ? 'bg-primary-600 text-white'
                                : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                        ]"
                        :title="collapsed ? item.name : ''"
                    >
                        <component :is="getIcon(item.icon)" class="w-5 h-5 flex-shrink-0" />
                        <span v-if="!collapsed" class="transition-opacity duration-200">
                            {{ item.name }}
                        </span>

                        <!-- Tooltip for collapsed state -->
                        <div
                            v-if="collapsed"
                            class="absolute left-full ml-2 px-2 py-1 bg-gray-800 text-white text-sm rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all whitespace-nowrap z-50"
                        >
                            {{ item.name }}
                        </div>
                    </Link>

                    <!-- Children (only when not collapsed) -->
                    <ul v-if="item.children?.length && !collapsed" class="mt-1 ml-8 space-y-1">
                        <li v-for="child in item.children" :key="child.route">
                            <Link
                                :href="route(child.route)"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors"
                                :class="[
                                    isActive(child.route)
                                        ? 'text-primary-400 bg-gray-800'
                                        : 'text-gray-400 hover:text-white hover:bg-gray-800'
                                ]"
                            >
                                <component v-if="child.icon" :is="getIcon(child.icon)" class="w-4 h-4" />
                                <span>{{ child.name }}</span>
                            </Link>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>

        <!-- Collapse toggle -->
        <div class="p-3 border-t border-gray-800">
            <button
                @click="emit('toggle-collapse')"
                class="w-full flex items-center justify-center gap-2 px-3 py-2 text-gray-400 hover:text-white hover:bg-gray-800 rounded-lg transition-colors"
            >
                <ChevronLeftIcon v-if="!collapsed" class="w-5 h-5" />
                <ChevronRightIcon v-else class="w-5 h-5" />
                <span v-if="!collapsed" class="text-sm">Zwiń menu</span>
            </button>
        </div>
    </aside>
</template>
