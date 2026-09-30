<script setup>
import { detectLocale, chromeStringsLocaleFor } from '../stores/i18n.js';

// Covers the interface (App.vue) until the strings and the catalogue have
// loaded, so the visitor never sees it with raw keys and no data. The
// cover is opaque from the first frame; only the ring fades in after a
// short delay, so a fast load shows a blank page instead of a flash of
// loader. The strings are not there yet, so the one label follows the
// locale the interface is about to load: the same precedence the i18n
// store uses (?lang=, the stored choice, the default), not the still
// unset <html lang>.
const label = chromeStringsLocaleFor(detectLocale()) === 'cs' ? 'Načítám\u2026' : 'Loading\u2026';
</script>

<template>
    <div
        data-testid="boot-splash"
        role="status"
        class="sg-boot fixed inset-0 z-[100] flex items-center justify-center bg-white dark:bg-zinc-950"
    >
        <span class="sg-boot-ring size-8 rounded-full border-2 border-zinc-200 border-t-red-600 dark:border-zinc-800 dark:border-t-red-400" aria-hidden="true"></span>
        <span class="sr-only">{{ label }}</span>
    </div>
</template>

<style scoped>
.sg-boot-ring {
    animation: sg-boot-in 200ms ease-out 250ms backwards, sg-boot-spin 0.8s linear infinite;
}
@keyframes sg-boot-in {
    from { opacity: 0; }
}
@keyframes sg-boot-spin {
    to { transform: rotate(360deg); }
}
@media (prefers-reduced-motion: reduce) {
    .sg-boot-ring { animation: sg-boot-in 200ms ease-out 250ms backwards; }
}
</style>
