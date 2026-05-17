<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const now = ref(Math.floor(Date.now() / 1000));
const dismissed = ref(false);
const resetting = ref(false);

let tick = null;
onMounted(() => {
    tick = setInterval(() => { now.value = Math.floor(Date.now() / 1000); }, 1000);
    try { dismissed.value = sessionStorage.getItem('demo-banner-dismissed') === '1'; } catch {}
});
onBeforeUnmount(() => { if (tick) clearInterval(tick); });

const demo = computed(() => page.props.demo || {});
const enabled = computed(() => !!demo.value.enabled);
const expiresAt = computed(() => demo.value.expires_at || null);

const secondsLeft = computed(() => {
    if (!expiresAt.value) return null;
    return Math.max(0, expiresAt.value - now.value);
});

const countdown = computed(() => {
    const s = secondsLeft.value;
    if (s == null) return '—';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    if (h > 0) return `${h}g ${String(m).padStart(2, '0')}m`;
    if (m > 0) return `${m}m ${String(sec).padStart(2, '0')}s`;
    return `${sec}s`;
});

function dismiss() {
    dismissed.value = true;
    try { sessionStorage.setItem('demo-banner-dismissed', '1'); } catch {}
}

function resetNow() {
    if (!confirm('Zresetowac demo? Wszystkie zmiany w tej sesji zostana skasowane.')) return;
    resetting.value = true;
    // Wyczysc cookie zeby middleware wygenerowal nowe UUID i zacial od kopii template
    document.cookie = 'demo_session=; Path=/; Max-Age=0; SameSite=Lax';
    window.location.reload();
}
</script>

<template>
    <div
        v-if="enabled && !dismissed"
        class="sticky top-0 z-50 bg-gradient-to-r from-amber-500 via-orange-500 to-rose-500 text-white shadow-md"
    >
        <div class="max-w-screen-2xl mx-auto px-4 py-2 flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded bg-white/20 font-semibold tracking-wide uppercase text-xs">Demo</span>
                <span class="hidden sm:inline">
                    Pracujesz na izolowanej kopii. Dane sa tylko Twoje i znikna za
                    <strong class="font-mono">{{ countdown }}</strong>.
                </span>
                <span class="sm:hidden font-mono">{{ countdown }}</span>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="resetNow"
                    :disabled="resetting"
                    class="px-3 py-1 rounded bg-white/15 hover:bg-white/25 transition text-xs font-medium disabled:opacity-50"
                >
                    Resetuj teraz
                </button>
                <button
                    type="button"
                    @click="dismiss"
                    class="px-2 py-1 rounded hover:bg-white/15 transition"
                    aria-label="Ukryj"
                    title="Ukryj banner"
                >
                    ×
                </button>
            </div>
        </div>
    </div>
</template>
