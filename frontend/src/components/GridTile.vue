<script setup>
// One tile of the overview grid (GridView.vue): a live, scaled preview of an
// entry, its name, and a variants badge. The whole tile opens the entry.
//
// Loading is the point of this component. A catalogue can hold hundreds of
// entries, and every preview is a full render with the project's CSS and JS.
// So a tile sets its iframe `src` only once both are true:
//   1. it is near the viewport (IntersectionObserver, a margin ahead of the
//      scroll), and
//   2. the shared load queue (lib/loadQueue.js, 6 at a time) gave it a slot.
// It hands the slot back on the iframe's `load`/`error`, after a safety
// timeout, or when it unmounts (a filter change). A tile that scrolls away
// while still queued drops its request. A loaded tile keeps its iframe.
//
// No `loading="lazy"` on the iframe: the queue already decides when a load
// starts, and a lazy iframe could hold a slot without starting at all.
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { computeTileGeometry } from '../lib/tileGeometry.js';
import { previewSizeFor } from '../lib/catalogGrid.js';

const props = defineProps({
    entry: { type: Object, required: true },
    src: { type: String, required: true },
    href: { type: String, required: true },
    tileWidth: { type: Number, required: true },
    queue: { type: Object, required: true },
    // The element that scrolls the grid. The observer must use it as its
    // root: with the default (the browser viewport) a tile clipped by that
    // element's overflow never counts as near, and the margin reads nothing
    // ahead of the scroll.
    scrollRoot: { type: Object, default: null },
});
const emit = defineEmits(['open']);

const i18n = useI18nStore();

// How far ahead of the viewport a tile starts asking for a slot.
const NEAR_MARGIN = '400px';
// A render that never fires `load` must not hold a slot for ever.
const LOAD_TIMEOUT_MS = 15000;

const size = computed(() => previewSizeFor(props.entry.section));
// The shared fit-to-width math of the variant grid, at a fixed logical size:
// scale down to the tile, never up.
const geometry = computed(() => computeTileGeometry({
    presetWidth: size.value.width,
    presetHeight: size.value.height,
    cellWidth: props.tileWidth,
    rawContentHeight: null,
    minHeight: 0,
}));

const root = ref(null);
const near = ref(false);
// idle -> queued -> loading -> loaded
const state = ref('idle');
const loadedSrc = ref(null);
let timer = null;
let observer = null;

function start() {
    state.value = 'loading';
    loadedSrc.value = props.src;
    timer = setTimeout(finish, LOAD_TIMEOUT_MS);
}

function finish() {
    clearTimeout(timer);
    timer = null;
    props.queue.release(props.entry.key);
    if (state.value === 'loading') state.value = 'loaded';
}

function ask() {
    if (state.value !== 'idle') return;
    state.value = 'queued';
    props.queue.request(props.entry.key, start);
}

function withdraw() {
    if (state.value !== 'queued') return;
    props.queue.release(props.entry.key);
    state.value = 'idle';
}

// A new theme or content locale changes the render URL: load it again.
watch(() => props.src, () => {
    if (state.value === 'idle') return;
    clearTimeout(timer);
    timer = null;
    props.queue.release(props.entry.key);
    loadedSrc.value = null;
    state.value = 'idle';
    if (near.value) ask();
});

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        for (const e of entries) {
            near.value = e.isIntersecting;
            if (e.isIntersecting) ask();
            else withdraw();
        }
    }, { root: props.scrollRoot, rootMargin: NEAR_MARGIN });
    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    clearTimeout(timer);
    props.queue.release(props.entry.key);
});

const variantsLabel = computed(() => `${i18n.t('grid.variants')}: ${props.entry.variantCount}`);
</script>

<template>
    <article
        ref="root"
        data-testid="grid-tile"
        :data-state="state"
        class="relative group flex flex-col min-w-0 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden focus-within:ring-2 focus-within:ring-red-600 hover:border-zinc-400 dark:hover:border-zinc-600 transition-colors"
    >
        <div
            class="relative overflow-hidden bg-zinc-100 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-800"
            :style="{ height: `${geometry.wrapperHeight}px` }"
            aria-hidden="true"
        >
            <iframe
                v-if="loadedSrc"
                :src="loadedSrc"
                :title="entry.name"
                tabindex="-1"
                class="absolute top-0 left-0 origin-top-left border-0 pointer-events-none bg-white"
                :style="{ width: `${size.width}px`, height: `${size.height}px`, transform: `scale(${geometry.zoom})` }"
                @load="finish"
                @error="finish"
            ></iframe>
            <div v-if="state !== 'loaded'" class="absolute inset-0 animate-pulse bg-zinc-100 dark:bg-zinc-800 motion-reduce:animate-none" :class="state === 'loading' && 'opacity-60'"></div>
        </div>
        <div class="flex items-center gap-2 px-3 py-2 min-w-0">
            <!-- Stretched link: its ::after covers the whole tile, so the
                 preview is clickable without nesting an iframe (interactive
                 content) inside an <a>. -->
            <a
                :href="href"
                data-testid="grid-tile-link"
                class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 dark:text-zinc-100 outline-none after:absolute after:inset-0 after:content-['']"
                @click.prevent="emit('open', entry)"
            >{{ entry.name }}</a>
            <span
                v-if="entry.variantCount > 0"
                data-testid="grid-tile-variants"
                :title="variantsLabel"
                :aria-label="variantsLabel"
                class="shrink-0 inline-flex items-center gap-1 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
            >
                <svg aria-hidden="true" focusable="false" class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                </svg>
                {{ entry.variantCount }}
            </span>
        </div>
    </article>
</template>
