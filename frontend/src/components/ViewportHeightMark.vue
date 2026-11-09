<script setup>
// The mark on a preview whose height follows its viewport: the preview is
// pinned at CHROME_VIEWPORT_HEIGHT_PX instead of sized to its content, and
// this says so, so a wrong pin gets reported rather than read as a clipped
// component. `label` is the toolbar form, `chip` the grid-tile form; both
// carry the measured deltas in `title` and `aria-label`. The chip shows only
// an icon, so its `title` carries the label text too.
import { computed } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { CHROME_VIEWPORT_HEIGHT_PX } from '../lib/previewHeight.js';

const props = defineProps({
    pin: { type: Object, default: null },
    form: { type: String, default: 'label' },
});

const i18n = useI18nStore();

const text = computed(() => i18n.t('preview.viewport_height'));
const detail = computed(() => {
    if (!props.pin) return '';
    return i18n.t('preview.viewport_height_detail')
        .replace('{viewport}', String(props.pin.viewportDeltaPx))
        .replace('{content}', String(props.pin.contentDeltaPx))
        .replace('{pinned}', String(CHROME_VIEWPORT_HEIGHT_PX));
});
const accessibleName = computed(() => `${text.value}. ${detail.value}`);
</script>

<template>
    <span v-if="pin"
          :data-testid="form === 'chip' ? 'viewport-height-chip' : 'viewport-height-label'"
          role="note"
          :title="form === 'chip' ? accessibleName : detail"
          :aria-label="accessibleName"
          class="shrink-0 inline-flex items-center gap-1 rounded-ui-pill border border-ui-control-border text-ui-muted whitespace-nowrap"
          :class="form === 'chip' ? 'h-5 px-1.5 text-[11px] font-semibold' : 'h-ui-control px-2.5 text-xs font-medium'">
        <svg aria-hidden="true" focusable="false" class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3v18M8 7l4-4 4 4M8 17l4 4 4-4"/>
        </svg>
        <span :class="form === 'chip' ? 'sr-only' : ''">{{ text }}</span>
    </span>
</template>
