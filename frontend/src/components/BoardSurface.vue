<script setup>
// The board mode of the overview grid (GridView.vue): the filtered entries on
// one large surface, one row per group, each a live iframe at the grid's
// width.
//
// Moving: scroll or drag pans, Ctrl or Cmd with the wheel (a trackpad pinch
// too) zooms around the cursor. Pan is the browser's own scroll; zoom is one
// `transform: scale()` on the surface, with a sizing box around it so the
// scrollbars match the scaled size. That keeps scrollbars, keyboard scroll and
// IntersectionObserver geometry working without a pan library.
//
// Selecting: a click selects a frame (arrows walk between frames, F jumps to
// it); a second click, or Enter, makes its page interactive; Esc steps back.
//
// The view is a link: once the reader has moved, zoomed or selected, the
// zoom, the middle point and the selection go to `viewchange`, and
// `initialView` brings them back.
//
// Layout is lib/boardLayout.js, zoom maths lib/boardZoom.js, view state and
// keyboard walk lib/boardView.js, and loading rules are in BoardFrame.vue.
import { computed, ref, reactive, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { gridPreviewSize } from '../lib/catalogGrid.js';
import {
    boardLayout, BOARD_FIT_HEIGHT_SLACK, BOARD_GROUP_BAND_PX, BOARD_NAME_BAND_PX,
} from '../lib/boardLayout.js';
import { clampZoom, wheelZoom, stepZoom, fitAllZoom, zoomAround } from '../lib/boardZoom.js';
import {
    encodeView, centerOn, viewCenter, neighbour, scrollToShow, zoomToFrame,
} from '../lib/boardView.js';
import { PILL_BUTTON } from '../lib/pillClasses.js';
import BoardFrame from './BoardFrame.vue';

const props = defineProps({
    // The filtered entries as rows: [{ key, label, items }] (lib/pageGroups.js
    // boardGroups), each item a grid entry (lib/catalogGrid.js gridEntries).
    groups: { type: Array, required: true },
    // The width every frame renders at (the grid's width toggle).
    previewWidth: { type: Number, required: true },
    // The grid's load queue, shared so both modes cap renders together.
    queue: { type: Object, required: true },
    srcFor: { type: Function, required: true },
    hrefFor: { type: Function, required: true },
    // A view brought back from a link (lib/boardView.js decodeView), or null.
    initialView: { type: Object, default: null },
});
const emit = defineEmits(['open', 'viewchange']);

const i18n = useI18nStore();
const items = computed(() => props.groups.flatMap((g) => g.items));
const itemKeys = computed(() => items.value.map((i) => i.key).join('|'));

// Measured heights, by frame key. A frame has the grid's preview height until
// its page has loaded.
const heights = reactive({});
function heightOf(key) {
    return heights[key] ?? gridPreviewSize(props.previewWidth).height;
}
function onMeasure(key, height) {
    if (heights[key] !== height) heights[key] = height;
}
// A new width is a new layout: forget the heights measured at the old one.
watch(() => props.previewWidth, () => {
    for (const key of Object.keys(heights)) delete heights[key];
});

// The space between the scrolling element's edge and the surface, in screen
// pixels: the board lines up with the page's own padding (the filter chips
// above it), not with the window edge.
const PAD_X = 40;
const PAD_TOP = 16;
const PAD_BOTTOM = 96;

const layout = computed(() => boardLayout({ groups: props.groups, width: props.previewWidth, heightOf }));
const zoom = ref(1);
const scroller = ref(null);

function viewSize() {
    const el = scroller.value;
    return { viewWidth: el?.clientWidth ?? 0, viewHeight: el?.clientHeight ?? 0 };
}
function frameOf(key) {
    return layout.value.frames.find((f) => f.key === key) ?? null;
}

// ---- The link to the view -------------------------------------------------

let touched = false;
let emitTimer = null;
function scheduleEmit() {
    if (!touched) return;
    clearTimeout(emitTimer);
    emitTimer = setTimeout(() => {
        const el = scroller.value;
        if (!el) return;
        emit('viewchange', encodeView({
            zoom: zoom.value,
            at: viewCenter({ zoom: zoom.value, scrollLeft: el.scrollLeft, scrollTop: el.scrollTop, ...viewSize(), padX: PAD_X, padTop: PAD_TOP }),
            sel: selectedKey.value,
        }));
    }, 300);
}

// ---- Selection ------------------------------------------------------------

// `selectedKey` is the frame the arrows and F act on; `activeKey` is the one
// whose page takes the pointer (BoardFrame.vue). The active frame is always
// the selected one.
const selectedKey = ref(null);
const activeKey = ref(null);

function select(key) {
    selectedKey.value = key;
    if (activeKey.value !== key) activeKey.value = null;
    scheduleEmit();
}
function activate(key) {
    selectedKey.value = key;
    activeKey.value = key;
    scheduleEmit();
}
// Esc steps back: an interactive page becomes a selected frame, and a selected
// frame becomes nothing. The board takes the keyboard back from the page.
function stepBack() {
    if (activeKey.value) {
        activeKey.value = null;
        scroller.value?.focus({ preventScroll: true });
    } else {
        selectedKey.value = null;
    }
    scheduleEmit();
}
function onPick(key) {
    touched = true;
    if (selectedKey.value === key) activate(key);
    else select(key);
}
// A new filter or width ends the interaction.
watch(() => [props.previewWidth, itemKeys.value], () => {
    activeKey.value = null;
    if (selectedKey.value && !items.value.some((i) => i.key === selectedKey.value)) selectedKey.value = null;
});

// ---- Zoom -------------------------------------------------------------------

async function applyZoom(next, px, py) {
    touched = true;
    const el = scroller.value;
    if (!el) { zoom.value = clampZoom(next); return; }
    const out = zoomAround({
        zoom: zoom.value, next, scrollLeft: el.scrollLeft, scrollTop: el.scrollTop,
        px: px ?? el.clientWidth / 2, py: py ?? el.clientHeight / 2, offsetX: PAD_X, offsetY: PAD_TOP,
    });
    zoom.value = out.zoom;
    // The sizing box takes its new size on the next render; scroll after it.
    await nextTick();
    el.scrollLeft = out.scrollLeft;
    el.scrollTop = out.scrollTop;
    scheduleEmit();
}

// Zoom so a frame fills the width of the window, and bring it into view.
async function jumpTo(key) {
    const frame = frameOf(key);
    const el = scroller.value;
    if (!frame || !el) return;
    const out = zoomToFrame({ frame, ...viewSize(), padX: PAD_X, padTop: PAD_TOP });
    zoom.value = out.zoom;
    await nextTick();
    el.scrollLeft = out.scrollLeft;
    el.scrollTop = out.scrollTop;
}
async function jumpToSelection() {
    if (!selectedKey.value) return;
    touched = true;
    await jumpTo(selectedKey.value);
    scheduleEmit();
}

// A Ctrl or Cmd wheel inside an active page: the page forwards it with the
// cursor in viewport coordinates.
function onFrameZoomwheel({ deltaY, x, y }) {
    const rect = scroller.value.getBoundingClientRect();
    applyZoom(wheelZoom(zoom.value, deltaY), x - rect.left, y - rect.top);
}

function onWheel(event) {
    touched = true;
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    const rect = scroller.value.getBoundingClientRect();
    applyZoom(wheelZoom(zoom.value, event.deltaY), event.clientX - rect.left, event.clientY - rect.top);
}

// ---- Fit, restore and the settling of the view ---------------------------

// The view opens fitted, or as the link says. Pages change size as they load,
// so it settles again after each change until the reader takes over.
let settleTimer = null;
let target = props.initialView;

function fitAll() {
    const el = scroller.value;
    if (!el) return;
    zoom.value = fitAllZoom({
        contentWidth: layout.value.width,
        contentHeight: layout.value.height,
        viewWidth: el.clientWidth - 2 * PAD_X,
        viewHeight: el.clientHeight - PAD_TOP - PAD_BOTTOM,
        heightSlack: BOARD_FIT_HEIGHT_SLACK,
    });
    el.scrollLeft = 0;
    el.scrollTop = 0;
}

async function applyTarget() {
    const el = scroller.value;
    if (!el || !target) return;
    if (target.sel && frameOf(target.sel) && selectedKey.value !== target.sel) selectedKey.value = target.sel;
    if (target.zoom !== null && target.at) {
        zoom.value = target.zoom;
        await nextTick();
        const pos = centerOn({ at: target.at, zoom: zoom.value, ...viewSize(), padX: PAD_X, padTop: PAD_TOP });
        el.scrollLeft = pos.scrollLeft;
        el.scrollTop = pos.scrollTop;
    } else if (target.sel && frameOf(target.sel)) {
        await jumpTo(target.sel);
    } else {
        fitAll();
    }
}

function settle() {
    if (touched || layout.value.frames.length === 0) return;
    if (target) applyTarget();
    else fitAll();
}
function scheduleSettle() {
    if (touched || layout.value.frames.length === 0) return;
    clearTimeout(settleTimer);
    settleTimer = setTimeout(settle, 150);
}
function fitNow() {
    touched = false;
    target = null;
    fitAll();
}
watch(() => [layout.value.width, layout.value.height], scheduleSettle, { immediate: true });
onMounted(scheduleSettle);
// A new filter is a new surface: fit it again, taking over from the reader.
// Before the reader has acted, more entries arriving (the catalogue loads in
// parts) only settle the view again, so a link's view survives them.
watch(itemKeys, () => {
    if (touched) fitNow();
    else scheduleSettle();
});
onBeforeUnmount(() => {
    clearTimeout(settleTimer);
    clearTimeout(emitTimer);
});

// ---- Pan by dragging --------------------------------------------------------

// A drag on the empty surface or on an idle frame pans, as with a hand tool.
// Below DRAG_PX it stays a click. A page that is active takes its own events.
const DRAG_PX = 4;
const dragging = ref(false);
let drag = null;
let swallowClick = false;

function onPointerdown(event) {
    touched = true;
    if (event.button !== 0 && event.button !== 1) return;
    drag = { x: event.clientX, y: event.clientY, left: scroller.value.scrollLeft, top: scroller.value.scrollTop, id: event.pointerId };
    if (event.button === 1) event.preventDefault();
}
function onPointermove(event) {
    if (!drag) return;
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    if (!dragging.value) {
        if (Math.hypot(dx, dy) < DRAG_PX) return;
        dragging.value = true;
        // From here the scroller gets the pointer, so the drag survives
        // leaving the window and no page under it reacts.
        scroller.value.setPointerCapture?.(drag.id);
    }
    scroller.value.scrollLeft = drag.left - dx;
    scroller.value.scrollTop = drag.top - dy;
}
function onPointerup(event) {
    if (!drag) return;
    const wasDragging = dragging.value;
    drag = null;
    dragging.value = false;
    if (wasDragging) {
        swallowClick = true;
        setTimeout(() => { swallowClick = false; }, 0);
        scheduleEmit();
        return;
    }
    // A click on the empty surface clears the selection.
    if (event.button === 0 && !event.target.closest('[data-testid="board-frame"]')) {
        selectedKey.value = null;
        activeKey.value = null;
        scheduleEmit();
    }
}
function onClickCapture(event) {
    if (!swallowClick) return;
    event.stopPropagation();
    event.preventDefault();
}

// ---- Keyboard ---------------------------------------------------------------

const ARROWS = { ArrowLeft: 'left', ArrowRight: 'right', ArrowUp: 'up', ArrowDown: 'down' };

function walk(direction) {
    const key = neighbour(layout.value.frames, selectedKey.value, direction);
    if (!key) return;
    select(key);
    const el = scroller.value;
    const frame = frameOf(key);
    if (!el || !frame) return;
    const out = scrollToShow({
        frame, zoom: zoom.value, scrollLeft: el.scrollLeft, scrollTop: el.scrollTop, ...viewSize(), padX: PAD_X, padTop: PAD_TOP,
    });
    el.scrollLeft = out.scrollLeft;
    el.scrollTop = out.scrollTop;
}

function onKeydown(event) {
    touched = true;
    if (event.key === 'Escape') { stepBack(); return; }
    if (event.key === 'Enter' && selectedKey.value) {
        event.preventDefault();
        if (event.ctrlKey || event.metaKey) emit('open', items.value.find((i) => i.key === selectedKey.value));
        else activate(selectedKey.value);
        return;
    }
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (ARROWS[event.key]) { event.preventDefault(); walk(ARROWS[event.key]); }
    else if (event.key === '+' || event.key === '=') { event.preventDefault(); applyZoom(stepZoom(zoom.value, 1)); }
    else if (event.key === '-') { event.preventDefault(); applyZoom(stepZoom(zoom.value, -1)); }
    else if (event.key === '0') { event.preventDefault(); fitNow(); }
    else if (event.key === '1') { event.preventDefault(); applyZoom(1); }
    else if (event.key === 'f' || event.key === 'F') { event.preventDefault(); jumpToSelection(); }
}

// A wheel listener that calls preventDefault must not be passive, and Vue's
// `@wheel` is passive on some browsers; bind it by hand.
onMounted(() => scroller.value?.addEventListener('wheel', onWheel, { passive: false }));
onBeforeUnmount(() => scroller.value?.removeEventListener('wheel', onWheel));

// ---- Text and marks that keep their size on screen --------------------------

// A page name keeps one size on screen, 12 px, at any zoom, with a 6 px gap
// above its frame, and is cut at the frame's width.
const nameSize = computed(() => Math.min(BOARD_NAME_BAND_PX * 0.5, 12 / zoom.value));
const nameGap = computed(() => Math.min(BOARD_NAME_BAND_PX * 0.2, 6 / zoom.value));
// A heading is 16 screen pixels, within its band.
const headSize = computed(() => Math.min(BOARD_GROUP_BAND_PX * 0.55, 16 / zoom.value));
// A selection mark is 3 screen pixels wide at any zoom.
const ringWidth = computed(() => 3 / zoom.value);
// Below this frame width on screen a name would only overlap its neighbours.
const NAME_MIN_SCREEN_PX = 90;
const namedFrames = computed(() => (props.previewWidth * zoom.value >= NAME_MIN_SCREEN_PX ? layout.value.frames : []));
const percent = computed(() => `${Math.round(zoom.value * 100)} %`);
function dimensionsOf(frame) {
    return heights[frame.key] === undefined ? '' : `${frame.width} × ${heights[frame.key]}`;
}

const buttonClass = PILL_BUTTON;
</script>

<template>
    <div class="relative flex-1 min-h-0 flex flex-col" data-testid="board-view">
        <div
            ref="scroller"
            tabindex="0"
            data-testid="board-scroller"
            :aria-label="i18n.t('board.label')"
            class="flex-1 min-h-0 overflow-auto bg-zinc-200 dark:bg-zinc-900 outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-red-600"
            :class="dragging ? 'cursor-grabbing select-none' : 'cursor-grab'"
            @keydown="onKeydown"
            @pointerdown="onPointerdown"
            @pointermove="onPointermove"
            @pointerup="onPointerup"
            @pointercancel="onPointerup"
            @click.capture="onClickCapture"
            @scroll.passive="scheduleEmit"
        >
            <!-- The sizing box has the scaled size, so the scrollbars fit it. -->
            <div :style="{ width: `${layout.width * zoom + 2 * PAD_X}px`, height: `${layout.height * zoom + PAD_TOP + PAD_BOTTOM}px`, padding: `${PAD_TOP}px ${PAD_X}px ${PAD_BOTTOM}px` }">
                <div
                    data-testid="board-surface"
                    class="relative origin-top-left"
                    :style="{ width: `${layout.width}px`, height: `${layout.height}px`, transform: `scale(${zoom})` }"
                >
                    <h2
                        v-for="head in layout.heads"
                        :key="`head-${head.key}`"
                        data-testid="board-group"
                        class="absolute m-0 truncate font-semibold text-zinc-900 dark:text-zinc-100"
                        :style="{ left: `${head.x}px`, top: `${head.y}px`, width: `${layout.width}px`, fontSize: `${headSize}px`, lineHeight: 1.2 }"
                    >{{ head.label }}</h2>
                    <BoardFrame
                        v-for="frame in layout.frames"
                        :key="frame.key"
                        :entry="frame.item"
                        :src="props.srcFor(frame.item)"
                        :href="props.hrefFor(frame.item)"
                        :x="frame.x"
                        :y="frame.y"
                        :width="frame.width"
                        :height="frame.height"
                        :queue="props.queue"
                        :selected="selectedKey === frame.key"
                        :active="activeKey === frame.key"
                        :ring-width="ringWidth"
                        :scroll-root="scroller"
                        @pick="onPick"
                        @zoomwheel="onFrameZoomwheel"
                        @escape="stepBack"
                        @measure="onMeasure"
                    />
                    <div
                        v-for="frame in namedFrames"
                        :key="`name-${frame.key}`"
                        class="absolute flex items-baseline gap-[0.6em] whitespace-nowrap"
                        :style="{ left: `${frame.x}px`, top: `${frame.y - nameSize * 1.3 - nameGap}px`, width: `${frame.width}px`, fontSize: `${nameSize}px`, lineHeight: 1.3 }"
                    >
                        <a
                            :href="props.hrefFor(frame.item)"
                            data-testid="board-frame-link"
                            class="min-w-0 truncate font-medium hover:underline"
                            :class="selectedKey === frame.key
                                ? 'text-zinc-900 dark:text-zinc-100'
                                : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
                            @click.prevent="emit('open', frame.item)"
                        >{{ frame.item.name }}</a>
                        <span
                            v-if="dimensionsOf(frame)"
                            data-testid="board-frame-dimensions"
                            class="shrink-0 tabular-nums text-zinc-500"
                            style="font-size: 0.85em"
                        >{{ dimensionsOf(frame) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zoom controls float above the board, outside the scrolling box. -->
        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex flex-wrap items-center justify-center gap-2 rounded-full bg-white/90 dark:bg-zinc-950/90 px-3 py-2 shadow-lg ring-1 ring-zinc-300 dark:ring-zinc-700 backdrop-blur" role="group" :aria-label="i18n.t('board.controls')">
            <button type="button" data-testid="board-zoom-out" :class="buttonClass" :title="i18n.t('board.zoom_out')" :aria-label="i18n.t('board.zoom_out')" @click="applyZoom(stepZoom(zoom, -1))">−</button>
            <button type="button" data-testid="board-zoom-reset" :class="buttonClass" :title="i18n.t('board.zoom_reset')" @click="applyZoom(1)">{{ percent }}</button>
            <button type="button" data-testid="board-zoom-in" :class="buttonClass" :title="i18n.t('board.zoom_in')" :aria-label="i18n.t('board.zoom_in')" @click="applyZoom(stepZoom(zoom, 1))">+</button>
            <button type="button" data-testid="board-fit" :class="buttonClass" @click="fitNow">{{ i18n.t('board.fit') }}</button>
            <button v-if="selectedKey" type="button" data-testid="board-zoom-selection" :class="buttonClass" :title="i18n.t('board.zoom_selection_title')" @click="jumpToSelection">{{ i18n.t('board.zoom_selection') }}</button>
            <span v-if="activeKey" data-testid="board-active-hint" class="px-2 text-xs font-medium text-red-700 dark:text-red-400">{{ i18n.t('board.active_hint') }}</span>
            <span v-else-if="selectedKey" data-testid="board-selected-hint" class="px-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ i18n.t('board.selected_hint') }}</span>
        </div>
    </div>
</template>
