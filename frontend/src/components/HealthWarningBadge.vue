<script setup>
import { computed } from 'vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

// Unobtrusive operator signal for GET /styleguide/api/health: a template
// that throws while parsing no longer 500s the whole catalogue (see
// ComponentParser::parseAll()) — it's skipped and recorded instead. Most
// projects never populate `catalog.warnings`, so this renders nothing by
// default; when it does have entries, a small badge appears near the
// sidebar header rather than a modal or a whole new UI surface — this is
// diagnostics for whoever maintains the templates, not end-user chrome.
const catalog = useCatalogStore();
const i18n = useI18nStore();

const count = computed(() => catalog.warnings.length);
const visible = computed(() => count.value > 0);

const title = computed(() => {
    const lines = catalog.warnings.map((w) => `${w.file}: ${w.error}`);
    return `${i18n.t('health.warnings_title')}\n${lines.join('\n')}`;
});

// Minimal by design (see brief) — full detail goes to the console for
// whoever's investigating, rather than a bespoke drawer/modal.
function logWarnings() {
    console.warn('[styleguide] parser warnings', catalog.warnings);
}
</script>

<template>
    <button
        v-if="visible"
        type="button"
        @click="logWarnings"
        :title="title"
        :aria-label="i18n.t('health.warnings_title')"
        class="shrink-0 inline-flex items-center gap-1 h-9 px-2 rounded-full border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
    >
        <svg aria-hidden="true" focusable="false" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2 22 20H2z"/>
            <path d="M12 9v5M12 17.5v.01"/>
        </svg>
        <span class="text-xs font-semibold">{{ count }}</span>
    </button>
</template>
