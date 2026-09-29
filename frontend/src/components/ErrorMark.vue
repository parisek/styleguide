<script setup>
// The small mark on a preview that reported JavaScript errors: a tile
// header, a compare column. It shares the warning badge's look and opens
// the badge's dialog, so there is one place that lists what went wrong.
// `.stop` on every event: the tile header around it isolates the variant
// on click, Enter and Space.
import { useI18nStore } from '../stores/i18n.js';
import { useRenderErrorsStore } from '../stores/renderErrors.js';

defineProps({
    count: { type: Number, required: true },
});

const i18n = useI18nStore();
const errors = useRenderErrorsStore();
</script>

<template>
    <button v-if="count > 0" type="button"
            data-testid="error-mark"
            @click.stop="errors.requestOpen()"
            @keydown.enter.stop
            @keydown.space.stop
            :title="i18n.t('health.mark_title')"
            :aria-label="`${i18n.t('health.mark_title')}: ${count}`"
            class="shrink-0 inline-flex items-center gap-0.5 h-5 px-1.5 rounded-full border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors">
        <svg aria-hidden="true" focusable="false" class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2 22 20H2z"/>
            <path d="M12 9v5M12 17.5v.01"/>
        </svg>
        <span class="text-[10px] font-semibold tabular-nums">{{ count }}</span>
    </button>
</template>
