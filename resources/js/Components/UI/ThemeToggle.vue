<script setup>
import { ref, onMounted } from 'vue';

const STORAGE_KEY = 'overcrm-theme';
const theme = ref('dark');

function applyTheme(value) {
    document.documentElement.setAttribute('data-theme', value);
    try { localStorage.setItem(STORAGE_KEY, value); } catch {}
    theme.value = value;
}

function toggle() {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark');
}

onMounted(() => {
    const fromHtml = document.documentElement.getAttribute('data-theme');
    theme.value = fromHtml === 'light' ? 'light' : 'dark';
});
</script>

<template>
    <button
        type="button"
        @click="toggle"
        class="h-9 w-9 inline-flex items-center justify-center rounded-md text-foreground-muted hover:text-foreground hover:bg-surface-elevated transition-colors"
        :title="theme === 'dark' ? 'Przełącz na jasny motyw' : 'Przełącz na ciemny motyw'"
    >
        <!-- Sun -->
        <svg v-if="theme === 'dark'" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="4"/>
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        </svg>
        <!-- Moon -->
        <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
    </button>
</template>
