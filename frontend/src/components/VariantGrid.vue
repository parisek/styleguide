<script setup>
// Variant grid core (styleguide 2.0): every discovered variant as its own
// preview screen (default fixture first), tiled to fit the canvas. Rendered
// by PreviewPane.vue whenever `viewport.gridActive` is true (has discovered
// variants, no `?variant=` selected -- a deep link to a specific variant
// still gets the classic single, resizable preview).
//
// Two things distinguish this from the original prototype (commit 91c0524):
//   1. The shared viewport preset (the SAME toolbar dropdown that drives the
//      classic single preview) now applies to every tile -- fixed-width/
//      fixed-height presets render each tile's iframe at exactly the
//      preset's logical size, then scale the whole tile down (never up) to
//      fit that tile's own measured cell width. Full stays fluid (100% cell
//      width, auto content height, no scaling) -- see lib/tileGeometry.js
//      for the (unit-tested) pure math.
//   2. A "rows" vs "grid" layout toggle (ui.variantLayout, persisted) --
//      tiles stacked one per row vs the original side-by-side auto-fit
//      columns.
import { inject, computed, reactive, onBeforeUnmount } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';
import { computeTileGeometry, formatTileScaleLabel } from '../lib/tileGeometry.js';

const i18n = useI18nStore();
const ui = useUiStore();
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

// Keyed by slug + variant id (not variant id alone) so navigating to a
// DIFFERENT entry that happens to share a variant id/the default tile
// (e.g. two entries both have a "dark-bg" variant) never reuses the
// previous entry's measured height/cell-width bookkeeping below --
// otherwise the new tile would render at the old entry's content height
// for a moment (the exact "problikne" flash PreviewPane.vue's iframe
// keying also fixes) before its own onTileLoad measurement corrects it.
function tileKey(tile) {
    return `${viewport.slug.value}:${tile.id ?? '__default__'}`;
}

// Per-tile auto-height, same technique as PreviewPane.vue's
// fitIframeToContent() (same-origin iframe -> read contentDocument directly,
// keep a ResizeObserver so post-load DOM changes feed back into height) but
// keyed per tile since every grid cell owns an independent iframe/document.
// Stores the RAW (unscaled) measured content height -- lib/tileGeometry.js's
// computeTileGeometry() is what turns this into a scaled wrapper height when
// a fixed-width preset with no canonical height (or Custom width) is active.
// `heights`/`cellWidths` are plain reactive objects (not Maps) so template
// access stays a normal reactive read; the ResizeObserver registries
// themselves don't need to be reactive, so they're bare Maps.
const heights = reactive({});
const heightObservers = new Map();

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
    const previous = heightObservers.get(key);
    if (previous) previous.disconnect();
    const ro = new ResizeObserver(measure);
    if (doc.documentElement) ro.observe(doc.documentElement);
    if (doc.body) ro.observe(doc.body);
    heightObservers.set(key, ro);
}

// Per-tile cell width -- the measured width of the tile's own content area
// (independent of every other tile, and of the shared preset's logical
// width), fed into computeTileGeometry()'s fit-to-cell zoom. Registered via
// a function `:ref` on the (layout-stable) content-area wrapper, so it keeps
// working across a fluid<->scaled toggle (only the wrapper's CHILDREN swap
// via v-if, not the wrapper itself).
const cellWidths = reactive({});
const cellObservers = new Map();

function registerCell(key, el) {
    const previous = cellObservers.get(key);
    if (previous) { previous.disconnect(); cellObservers.delete(key); }
    if (!el) return;
    cellWidths[key] = el.clientWidth ?? 0;
    const ro = new ResizeObserver((entries) => {
        for (const entry of entries) cellWidths[key] = entry.contentRect.width;
    });
    ro.observe(el);
    cellObservers.set(key, ro);
}

// Small pre-measure floor (not the visibly over-tall 320px the original
// prototype used) -- shown only until the first onload/ResizeObserver tick
// resolves a real content height for a Full/no-canonical-height tile;
// fixed-height presets never consult this at all (their tile height is the
// scaled preset height, exactly).
const PRE_MEASURE_MIN_HEIGHT = 96;

// One combined per-tile view-model: geometry (lib/tileGeometry.js's pure
// fit-to-cell math) plus the formatted scale readout, recomputed whenever
// the shared preset, this tile's measured cell width, or its measured
// content height changes.
const renderTiles = computed(() => tiles.value.map((tile) => {
    const key = tileKey(tile);
    const geometry = computeTileGeometry({
        presetWidth: viewport.effective.value.width,
        presetHeight: viewport.effective.value.height,
        cellWidth: cellWidths[key] ?? 0,
        rawContentHeight: heights[key] ?? null,
        minHeight: PRE_MEASURE_MIN_HEIGHT,
    });
    return {
        ...tile,
        key,
        geometry,
        scaleLabel: formatTileScaleLabel(geometry),
        // The Default tile has no isolated single-preview URL of its own to
        // navigate to: gridActive's own activation rule (useViewportPreset.js)
        // is precisely "no `?variant=` selected", so there is no route that
        // shows the default fixture alone in the classic single preview
        // without ALSO satisfying gridActive and landing back on this same
        // grid. Every other tile isolates cleanly to `?variant=<id>`.
        clickable: tile.id !== null,
    };
}));

function isolateTile(tile) {
    if (!tile.clickable) return;
    viewport.setVariant(tile.id);
}

onBeforeUnmount(() => {
    heightObservers.forEach((ro) => ro.disconnect());
    heightObservers.clear();
    cellObservers.forEach((ro) => ro.disconnect());
    cellObservers.clear();
});
</script>

<template>
    <div data-testid="variant-grid" class="w-full p-6">
        <!-- "grid": `auto-fit` + `minmax(min(420px, 100%), 1fr)` puts as many
             tiles side by side as fit at >=420px each, then wraps -- a narrow
             canvas (mobile preset width, small window) collapses to a single
             column via the `min(420px, 100%)` clamp instead of overflowing.
             `items-start` keeps each row's tiles aligned to the top instead
             of CSS grid's default stretch-to-tallest-in-row.
             "rows": one tile per row, full width -- `align-items: stretch`
             (flex-col's own default) is what's wanted there, so each tile
             fills the row; the per-tile canvas area centers itself within
             that width via its own `justify-center` wrapper below. -->
        <div data-testid="variant-grid-tiles"
             class="gap-6"
             :class="ui.variantLayout === 'rows' ? 'flex flex-col' : 'grid items-start'"
             :style="ui.variantLayout === 'rows' ? '' : 'grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr));'">
            <!-- Staggered entrance on first render: opacity/translateY tween
                 driven by the .sg-tile-enter keyframe (styleguide.css),
                 30ms further delayed per tile via the --i custom property
                 (index-based, set inline since Tailwind has no per-index
                 arbitrary-value hook). Gated behind
                 prefers-reduced-motion:no-preference in the CSS itself. -->
            <div v-for="(tile, i) in renderTiles" :key="tile.key"
                 data-testid="variant-tile"
                 class="sg-tile-enter flex flex-col rounded-lg overflow-hidden ring-1 ring-zinc-200 dark:ring-zinc-800 bg-white dark:bg-zinc-900 shadow-sm"
                 :style="{ '--i': i }">
                <!-- Slim SPA-chrome header -- variant label (muted) plus an
                     optional description underneath, plus a per-tile scale
                     readout whenever the shared preset isn't Full. `v-html`
                     for the description: same dev-authored-YAML trust model
                     as App.vue's description bar (content originates in the
                     project's own .twig front-comment, never visitor input).
                     Clickable (mouse + keyboard) for every tile EXCEPT the
                     Default one -- see `clickable`'s comment above. -->
                <div data-testid="variant-tile-header"
                     class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-800 shrink-0"
                     :class="tile.clickable ? 'cursor-pointer group' : ''"
                     :role="tile.clickable ? 'button' : undefined"
                     :tabindex="tile.clickable ? 0 : undefined"
                     :aria-label="tile.clickable ? `${i18n.t('toolbar.variant_isolate_prefix')} ${tile.label}` : undefined"
                     @click="isolateTile(tile)"
                     @keydown.enter="isolateTile(tile)"
                     @keydown.space.prevent="isolateTile(tile)">
                    <div data-testid="variant-tile-label"
                         class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                         :class="tile.clickable ? 'group-hover:text-zinc-900 dark:group-hover:text-zinc-100 group-hover:underline' : ''">{{ tile.label }}</div>
                    <div v-if="tile.description" data-testid="variant-tile-description" class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500 leading-relaxed" v-html="tile.description"></div>
                    <div v-if="tile.scaleLabel" data-testid="variant-tile-scale" class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500 font-mono tabular-nums">{{ tile.scaleLabel }}</div>
                </div>
                <!-- Content-area wrapper: stable across a fluid<->scaled swap
                     (only its CHILDREN toggle via v-if below) so the
                     ResizeObserver registered on it keeps reporting this
                     tile's cell width regardless of which mode is active. -->
                <div :ref="(el) => registerCell(tile.key, el)"
                     class="bg-zinc-50 dark:bg-zinc-950/40"
                     :class="tile.geometry.fluid ? '' : 'flex justify-center p-3'">
                    <!-- Full preset: fluid tile, no scaling -- iframe width
                         tracks the cell via `w-full`, height is content-fit. -->
                    <iframe v-if="tile.geometry.fluid"
                            :src="tile.src"
                            class="w-full border-0 block bg-white"
                            :style="{ height: tile.geometry.iframeHeight + 'px' }"
                            @load="onTileLoad(tile, $event)"></iframe>
                    <!-- Fixed-width preset (device or Custom): iframe renders
                         at the preset's own logical size, then the whole
                         wrapper is scaled down (never up) to fit this tile's
                         cell -- same wrapper/transform-scale pairing as the
                         classic single preview (PreviewPane.vue). -->
                    <div v-else
                         class="overflow-hidden bg-white ring-1 ring-zinc-200 dark:ring-zinc-800 rounded shadow-sm"
                         :style="{ width: tile.geometry.wrapperWidth + 'px', height: tile.geometry.wrapperHeight + 'px' }">
                        <iframe :src="tile.src"
                                class="border-0 block"
                                :style="{ width: tile.geometry.iframeWidth + 'px', height: tile.geometry.iframeHeight + 'px', transform: `scale(${tile.geometry.zoom})`, transformOrigin: '0 0' }"
                                @load="onTileLoad(tile, $event)"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
