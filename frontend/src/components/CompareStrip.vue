<script setup>
// Compare mode: one preview at every `viewports.compare` width side by side.
// Used for the single preview (PreviewPane.vue) and inside every grid tile
// (VariantGrid.vue). Each column renders the SAME render URL at its own
// logical width and scales it down to fit (lib/tileGeometry.js); columns are
// sized in proportion to their widths, so all of them share one zoom.
//
// Every iframe loads lazily: a family with dozens of tiles times three
// widths would otherwise start every render at once.
import { computed, reactive, watch, onBeforeUnmount } from 'vue';
import { computeTileGeometry, compareColumnTemplate } from '../lib/tileGeometry.js';

const props = defineProps({
    src: { type: String, required: true },
    widths: { type: Array, required: true },
    // `render: chrome` entries size to their viewport, not their content
    // (lib/previewHeight.js, #116).
    scrolls: { type: Boolean, default: false },
});

const emit = defineEmits(['load']);

const PRE_MEASURE_MIN_HEIGHT = 96;

// Keyed by width, never by position. The figures are keyed by width too, so
// ticking a width in between keeps the other iframes alive: they never load
// again, and a height filed under a position would land on the wrong column
// (it stayed at the pre-measure floor).
const heights = reactive({});
const cellWidths = reactive({});
const heightObservers = new Map();
const cellObservers = new Map();
let loadEmitted = false;

// A new render URL (reload, theme, locale) is a new document: its height is
// not the old one's.
watch(() => props.src, () => {
    heightObservers.forEach((ro) => ro.disconnect());
    heightObservers.clear();
    for (const key of Object.keys(heights)) delete heights[key];
    loadEmitted = false;
});

function registerCell(width, el) {
    const previous = cellObservers.get(width);
    if (previous) { previous.disconnect(); cellObservers.delete(width); }
    if (!el) return;
    cellWidths[width] = el.clientWidth;
    const ro = new ResizeObserver((entries) => {
        for (const entry of entries) cellWidths[width] = entry.contentRect.width;
    });
    ro.observe(el);
    cellObservers.set(width, ro);
}

function onLoad(width, event) {
    if (!loadEmitted) {
        loadEmitted = true;
        emit('load');
    }
    const doc = event.target?.contentDocument;
    if (!doc) return;
    const measure = () => {
        const h = Math.max(doc.documentElement?.scrollHeight ?? 0, doc.body?.scrollHeight ?? 0);
        if (h > 0) heights[width] = h;
    };
    measure();
    heightObservers.get(width)?.disconnect();
    const ro = new ResizeObserver(measure);
    if (doc.documentElement) ro.observe(doc.documentElement);
    if (doc.body) ro.observe(doc.body);
    heightObservers.set(width, ro);
}

// An unticked width takes its observers with it.
watch(() => props.widths, (widths) => {
    for (const [width, ro] of heightObservers) {
        if (widths.includes(width)) continue;
        ro.disconnect();
        heightObservers.delete(width);
        delete heights[width];
    }
});

const columns = computed(() => props.widths.map((width) => {
    const geometry = computeTileGeometry({
        presetWidth: width,
        presetHeight: null,
        cellWidth: cellWidths[width] ?? 0,
        rawContentHeight: heights[width] ?? null,
        minHeight: PRE_MEASURE_MIN_HEIGHT,
        scrolls: props.scrolls,
    });
    const caption = geometry.zoom < 1 ? `${width} px · ${Math.round(geometry.zoom * 100)} %` : `${width} px`;
    return { width, geometry, caption };
}));

const gridTemplateColumns = computed(() => compareColumnTemplate(props.widths));

onBeforeUnmount(() => {
    heightObservers.forEach((ro) => ro.disconnect());
    cellObservers.forEach((ro) => ro.disconnect());
});
</script>

<template>
    <div data-testid="compare-strip" class="grid gap-4 items-start" :style="{ gridTemplateColumns }">
        <figure v-for="column in columns" :key="column.width" data-testid="compare-column" class="m-0 min-w-0">
            <!-- truncate: on a phone a 320 column is a few dozen pixels wide,
                 and a caption running into the next one reads as nonsense.
                 The full caption stays in the title. -->
            <figcaption data-testid="compare-caption" :title="column.caption" class="mb-1.5 font-mono text-xs tabular-nums text-zinc-500 dark:text-zinc-400 truncate">{{ column.caption }}</figcaption>
            <div :ref="(el) => registerCell(column.width, el)" class="min-w-0">
                <div class="overflow-hidden bg-white ring-1 ring-zinc-200 dark:ring-zinc-800 rounded shadow-sm"
                     :style="{ width: column.geometry.wrapperWidth + 'px', height: column.geometry.wrapperHeight + 'px' }">
                    <!-- :key on src: a fresh element per document, as in
                         PreviewPane.vue, so a stale page never shows. -->
                    <iframe :key="src"
                            :src="src"
                            loading="lazy"
                            :title="`${column.width} px`"
                            class="border-0 block"
                            :style="{ width: column.geometry.iframeWidth + 'px', height: column.geometry.iframeHeight + 'px', transform: `scale(${column.geometry.zoom})`, transformOrigin: '0 0' }"
                            @load="onLoad(column.width, $event)"></iframe>
                </div>
            </div>
        </figure>
    </div>
</template>
