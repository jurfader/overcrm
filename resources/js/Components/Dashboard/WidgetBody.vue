<script setup>
// Standardowy layout body widgetu — scroll-area + opcjonalny footer.
// Module powinny opakowywac swoja zawartosc w <WidgetBody> zeby zachowac
// spojny look: padding, overflow scroll, sticky footer z action link.
//
// Uzycie:
//   <WidgetBody>
//     <div>...content...</div>
//     <template #footer>
//       <Link href="...">Zobacz wszystko →</Link>
//     </template>
//   </WidgetBody>
</script>

<template>
    <div class="flex flex-col h-full">
        <div class="flex-1 p-4 overflow-y-auto">
            <slot />
        </div>
        <div v-if="$slots.footer"
             class="px-4 py-2 border-t border-border bg-surface-2 shrink-0">
            <slot name="footer" />
        </div>
    </div>
</template>
