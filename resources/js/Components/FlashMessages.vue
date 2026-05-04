<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';

const page = usePage();

const successMessage = computed(() => page.props.flash?.success);
const errorMessage = computed(() => page.props.flash?.error);
const infoMessage = computed(() => page.props.flash?.message);
</script>

<template>
    <div class="space-y-4 mb-6" v-if="successMessage || errorMessage || infoMessage">
        <!-- Success Message -->
        <div v-if="successMessage" class="rounded-lg bg-green-50 p-4 border border-green-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <Icons name="check-circle" class="h-5 w-5 text-green-400" />
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800">{{ successMessage }}</p>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <div v-if="errorMessage" class="rounded-lg bg-red-50 p-4 border border-red-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <Icons name="x-circle" class="h-5 w-5 text-red-400" />
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800">{{ errorMessage }}</p>
                </div>
            </div>
        </div>

        <!-- Info Message -->
        <div v-if="infoMessage" class="rounded-lg bg-blue-50 p-4 border border-blue-200">
            <div class="flex">
                <div class="flex-shrink-0">
                    <Icons name="info" class="h-5 w-5 text-blue-400" />
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-blue-800">{{ infoMessage }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
