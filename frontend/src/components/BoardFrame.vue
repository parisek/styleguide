<script setup>
// One page on the board (BoardView.vue): a live iframe at the preset width,
// placed at a fixed spot of the surface. The surface scales, so the frame
// does not scale itself.
//
// Loading follows GridTile.vue: the iframe `src` is set only when the frame
// is near the scrolling element and the shared load queue (lib/loadQueue.js)
// gave it a slot. The slot goes back on `load`, `error`, a timeout or unmount.
//
// An idle frame takes no pointer events, so a wheel or a drag over a page
// reaches the board, not the page. A click on it is a `pick`: the board
// selects the frame, and a pick of the selected frame activates it. An active
// frame's iframe takes pointer events and the page works as it does anywhere
// (menus, accordions, links, forms). The board's own gestures keep working over an
// active page: the frame forwards Ctrl or Cmd with the wheel, and Esc, from
// the page's document to the board, which owns the zoom and the activation.
//
// The render is same-origin, so the frame measures its own document and tells
// the view, which lays the rows out again. A page that sizes itself with `vh`
// would feed that height back into itself: a `render: chrome` entry keeps the
// pinned demo height (lib/previewHeight.js) and every height has a ceiling.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { resolveContentHeight, entryScrolls } from '../lib/previewHeight.js';

const props = defineProps({
    entry: { type: Object, required: true },
    src: { type: String, required: true },
    x: { type: Number, required: true },
    y: { type: Number, required: true },
    width: { type: Number, required: true },
    height: { type: Number, required: true },
    queue: { type: Object, required: true },
    scrollRoot: { type: Object, default: null },
    href: { type: String, required: true },
    // The board selects one frame, and makes one frame active, at a time.
    selected: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
    // The mark's width in surface pixels, so it is the same on screen at any zoom.
    ringWidth: { type: Number, default: 3 },
});
const emit = defineEmits(['pick', 'measure', 'zoomwheel', 'escape']);

// The marks use the palette's own variables: indigo selects (the link colour),
// red is a page you can use.
const mark = computed(() => {
    if (props.active) return `0 0 0 ${props.ringWidth}px var(--color-red-600)`;
    if (props.selected) return `0 0 0 ${props.ringWidth}px var(--color-indigo-500)`;
    return null;
});

const NEAR_MARGIN = '800px';
const LOAD_TIMEOUT_MS = 15000;

const root = ref(null);
const frame = ref(null);
// idle -> queued -> loading -> loaded
const state = ref('idle');
const loadedSrc = ref(null);
let near = false;
let timer = null;
let observer = null;
let resizeObserver = null;

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

function measure() {
    const doc = frame.value?.contentDocument;
    if (!doc) return;
    const raw = Math.max(doc.documentElement?.scrollHeight ?? 0, doc.body?.scrollHeight ?? 0);
    if (raw <= 0) return;
    emit('measure', props.entry.key, resolveContentHeight({ rawContentHeight: raw, scrolls: entryScrolls(props.entry.item) }));
}

// The page's own document swallows these, so it hands them to the board, with
// the cursor in the board's coordinates (the surface is scaled).
let forwardedDoc = null;
function forwardWheel(event) {
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    const rect = frame.value.getBoundingClientRect();
    const scale = rect.width / frame.value.offsetWidth || 1;
    emit('zoomwheel', { deltaY: event.deltaY, x: rect.left + event.clientX * scale, y: rect.top + event.clientY * scale });
}
function forwardKey(event) {
    if (event.key === 'Escape') emit('escape');
}
function unforward() {
    forwardedDoc?.removeEventListener('wheel', forwardWheel);
    forwardedDoc?.removeEventListener('keydown', forwardKey);
    forwardedDoc = null;
}

function onLoad() {
    finish();
    measure();
    resizeObserver?.disconnect();
    unforward();
    const doc = frame.value?.contentDocument;
    if (doc && typeof doc.addEventListener === 'function') {
        doc.addEventListener('wheel', forwardWheel, { passive: false });
        doc.addEventListener('keydown', forwardKey);
        forwardedDoc = doc;
    }
    if (doc?.documentElement && typeof ResizeObserver === 'function') {
        resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(doc.documentElement);
    }
}

// A new theme or content locale changes the render URL: load it again.
watch(() => props.src, () => {
    if (state.value === 'idle') return;
    clearTimeout(timer);
    timer = null;
    props.queue.release(props.entry.key);
    resizeObserver?.disconnect();
    unforward();
    loadedSrc.value = null;
    state.value = 'idle';
    if (near) ask();
});

onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        for (const e of entries) {
            near = e.isIntersecting;
            if (near) ask();
            else withdraw();
        }
    }, { root: props.scrollRoot, rootMargin: NEAR_MARGIN });
    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    resizeObserver?.disconnect();
    unforward();
    clearTimeout(timer);
    props.queue.release(props.entry.key);
});
</script>

<template>
    <article
        ref="root"
        data-testid="board-frame"
        :data-state="state"
        :data-key="entry.key"
        class="absolute bg-white shadow-md"
        :class="active ? '' : 'select-none cursor-pointer'"
        :style="{ left: `${x}px`, top: `${y}px`, width: `${width}px`, height: `${height}px`, boxShadow: mark ?? '0 0 0 1px var(--color-zinc-400)' }"
        @click="!active && emit('pick', entry.key)"
    >
        <iframe
            v-if="loadedSrc"
            ref="frame"
            :src="loadedSrc"
            :title="entry.name"
            tabindex="-1"
            class="absolute inset-0 size-full border-0 bg-white"
            :class="!active && 'pointer-events-none'"
            @load="onLoad"
            @error="finish"
        ></iframe>
        <div v-if="state !== 'loaded'" class="absolute inset-0 animate-pulse bg-zinc-100 dark:bg-zinc-800 motion-reduce:animate-none" :class="state === 'loading' && 'opacity-60'"></div>
    </article>
</template>
