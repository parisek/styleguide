<script setup>
// Prototype core (styleguide 2.0): replaces both the toolbar variant-pill
// switcher and the server-side stacked view (commit 901e1b8) with a
// responsive grid of independent preview screens -- one tile per variant
// (default fixture first), flowing next to each other / wrapping below as
// they fit the canvas width. Rendered by PreviewPane.vue whenever
// `viewport.gridActive` is true (has discovered variants, no `?variant=`
// selected -- a deep link to a specific variant still gets the classic
// single preview).
import { inject, computed, reactive, onBeforeUnmount } from 'vue';
import { useI18nStore } from '../stores/i18n.js';

const i18n = useI18nStore();
const viewport = inject('viewport');

// Tile list: the implicit default fixture first (no `?variant=` in its
// render URL), then every discovered variant record in the same
// (filename/id) order ComponentParser::discoverVariants() returns them.
const tiles = computed(() => {
    const item = viewport.currentItem.value;
    if (!item) return [];
    const variants = item.variants ?? [];
    return [
        { id: null, label: i18n.t('toolbar.variant_default'), description: '' },
        ...variants.map((v) => ({ id: v.id, label: v.label, description: v.description || '' })),
    ].map((tile) => ({ ...tile, src: viewport.iframeSrcForVariant(tile.id) }));
});

function tileKey(tile) {
    return tile.id ?? '__default__';
}

// Per-tile auto-height, same technique as PreviewPane.vue's
// fitIframeToContent() (same-origin iframe -> read contentDocument directly,
// keep a ResizeObserver so post-load DOM changes feed back into height) but
// keyed per tile since every grid cell owns an independent iframe/document.
// `heights` is a plain reactive object (not a Map) so template access
// (`heights[tileKey(tile)]`) stays a normal reactive read; the ResizeObserver
// registry itself doesn't need to be reactive, so it's a bare Map.
const heights = reactive({});
const observers = new Map();

function onTileLoad(tile, event) {
    const iframe = event.target;
    const doc = iframe?.contentDocument;
    if (!doc) return;
    const key = tileKey(tile);
    const measure = () => {
        const h = Math.max(doc.documentElement?.scrollHeight ?? 0, doc.body?.scrollHeight ?? 0);
        if (h > 0) heights[key] = h;
    };
    measure();
    const previous = observers.get(key);
    if (previous) previous.disconnect();
    const ro = new ResizeObserver(measure);
    if (doc.documentElement) ro.observe(doc.documentElement);
    if (doc.body) ro.observe(doc.body);
    observers.set(key, ro);
}

// Modest default height (below the first onload, or for a tile whose
// content never resolves a positive scrollHeight) with internal scroll via
// overflow-auto on the iframe -- acceptable for the prototype per the brief;
// per-tile observers already give auto-height in the common case.
const FALLBACK_TILE_HEIGHT = 320;
function tileHeight(tile) {
    return heights[tileKey(tile)] ?? FALLBACK_TILE_HEIGHT;
}

onBeforeUnmount(() => {
    observers.forEach((ro) => ro.disconnect());
    observers.clear();
});
</script>

<template>
    <div data-testid="variant-grid" class="w-full p-6">
        <!-- `auto-fit` + `minmax(min(420px, 100%), 1fr)` puts as many tiles
             side by side as fit at >=420px each, then wraps -- a narrow
             canvas (mobile preset width, small window) collapses to a
             single column via the `min(420px, 100%)` clamp instead of
             overflowing. -->
        <div class="grid gap-6" style="grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr));">
            <div v-for="tile in tiles" :key="tileKey(tile)"
                 data-testid="variant-tile"
                 class="flex flex-col rounded-lg overflow-hidden ring-1 ring-zinc-200 dark:ring-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
                <!-- Slim SPA-chrome header -- variant label (muted) plus an
                     optional description underneath. `v-html` for the
                     description: same dev-authored-YAML trust model as
                     App.vue's description bar (content originates in the
                     project's own .twig front-comment, never visitor input). -->
                <div class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-800 shrink-0">
                    <div data-testid="variant-tile-label" class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ tile.label }}</div>
                    <div v-if="tile.description" data-testid="variant-tile-description" class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500 leading-relaxed" v-html="tile.description"></div>
                </div>
                <iframe :src="tile.src"
                        class="w-full border-0 block bg-white"
                        :style="{ height: tileHeight(tile) + 'px' }"
                        @load="onTileLoad(tile, $event)"></iframe>
            </div>
        </div>
    </div>
</template>
