<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: Array,
});
</script>

<template>
    <nav v-if="links && links.length > 3" class="flex items-center justify-between border-t border-gray-200 px-4 sm:px-0 mt-6 pt-4">
        <div class="-mt-px flex w-0 flex-1">
            <Link
                v-if="links[0].url"
                :href="links[0].url"
                class="inline-flex items-center border-t-2 border-transparent pr-1 pt-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700"
            >
                <svg class="mr-3 h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a.75.75 0 01-.75.75H4.66l2.1 1.95a.75.75 0 11-1.02 1.1l-3.5-3.25a.75.75 0 010-1.1l3.5-3.25a.75.75 0 111.02 1.1l-2.1 1.95h12.59A.75.75 0 0118 10z" clip-rule="evenodd" />
                </svg>
                Poprzednia
            </Link>
        </div>
        <div class="hidden md:-mt-px md:flex">
            <template v-for="(link, i) in links.slice(1, -1)" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :class="[
                        link.active
                            ? 'border-indigo-500 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                        'inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium'
                    ]"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="inline-flex items-center border-t-2 border-transparent px-4 pt-4 text-sm font-medium text-gray-500"
                    v-html="link.label"
                />
            </template>
        </div>
        <div class="-mt-px flex w-0 flex-1 justify-end">
            <Link
                v-if="links[links.length - 1].url"
                :href="links[links.length - 1].url"
                class="inline-flex items-center border-t-2 border-transparent pl-1 pt-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700"
            >
                Następna
                <svg class="ml-3 h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M2 10a.75.75 0 01.75-.75h12.59l-2.1-1.95a.75.75 0 111.02-1.1l3.5 3.25a.75.75 0 010 1.1l-3.5 3.25a.75.75 0 11-1.02-1.1l2.1-1.95H2.75A.75.75 0 012 10z" clip-rule="evenodd" />
                </svg>
            </Link>
        </div>
    </nav>
</template>
