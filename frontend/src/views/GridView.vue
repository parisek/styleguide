<script setup>
// The overview grid (route /grid, and the landing with
// `overview.default: grid`): every component and page as a tile with a
// scaled live preview. A filter bar narrows it by sidebar section and by
// text; a tile opens the entry. Loading rules live in GridTile.vue and
// lib/loadQueue.js; data and layout rules in lib/catalogGrid.js.
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';
import { useContentLocale } from '../composables/useContentLocale.js';
import {
    gridEntries, filterGridEntries, sectionCounts, gridLayout, gridWidthOptions, resolveGridWidth, widthClass, GRID_GAP_PX,
} from '../lib/catalogGrid.js';
import { compareWidths } from '../lib/runtimeConfig.js';
import { createLoadQueue } from '../lib/loadQueue.js';
import { boardGroups } from '../lib/pageGroups.js';
import { pillState } from '../lib/pillClasses.js';
import { PAGE_PAD, PAGE_PAD_BOTTOM, PAGE_TITLE } from '../lib/pageLayout.js';
import { readSpaConfig } from '../lib/config.js';
import { buildRenderSrc } from '../lib/renderSrc.js';
import GridTile from '../components/GridTile.vue';
import BoardSurface from '../components/BoardSurface.vue';
import { decodeView } from '../lib/boardView.js';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const ui = useUiStore();
const router = useRouter();
const route = useRoute();
const { contentLocale } = useContentLocale();

// One queue per visit of the view: 6 renders at a time across all tiles.
const queue = createLoadQueue();

// The filter is remembered across visits (localStorage). A stored section the
// catalogue no longer has reads as "all", so a stale choice cannot hide
// everything.
const section = computed({
    get: () => (chips.value.some((c) => c.section === ui.gridSection) ? ui.gridSection : null),
    set: (value) => { ui.gridSection = value; },
});
const query = computed({
    get: () => ui.gridQuery,
    set: (value) => { ui.gridQuery = value; },
});

// Tiles or the board. A `?view=` in the address wins, so a link can open
// either; a choice is written back to the address and remembered.
const VIEWS = ['grid', 'board'];
const view = computed(() => (VIEWS.includes(route.query.view) ? route.query.view : (VIEWS.includes(ui.gridView) ? ui.gridView : 'grid')));
// The board's own parameters in the address (lib/boardView.js): the zoom, the
// middle point and the selection. They belong to one filtered surface, so a
// new view or a new filter drops them.
function withoutBoardState(query) {
    const { zoom, at, sel, ...rest } = query;
    return rest;
}
function setView(next) {
    ui.gridView = next;
    router.replace({ query: { ...withoutBoardState(route.query), view: next } });
}
// The board reads it once, when it appears (after the catalogue and the first
// navigation are in), so a link opens as it says; later changes come from the
// board itself.
const boardInitialView = computed(() => decodeView(route.query));
function onBoardViewChange(state) {
    router.replace({ query: { ...route.query, ...state } });
}

// The width every tile renders at: one of the project's widths, remembered
// in localStorage. A stored width the project no longer offers falls back
// to the widest.
const widthOptions = computed(() => gridWidthOptions(compareWidths()));
const previewWidth = computed(() => resolveGridWidth(ui.gridWidth, widthOptions.value));
// A stored width the project no longer offers is dropped, so it cannot
// come back if a later config offers that width again.
watch(widthOptions, (options) => {
    if (ui.gridWidth !== null && !options.includes(ui.gridWidth)) ui.gridWidth = null;
}, { immediate: true });
// Named by device class when the class is unique among the options, else by
// pixels, so two "tablet" widths never read alike.
function widthLabel(width) {
    const kind = widthClass(width);
    const same = widthOptions.value.filter((w) => widthClass(w) === kind);
    return same.length === 1 ? i18n.t(`grid.width_${kind}`) : `${width}`;
}

const order = computed(() => [...catalog.componentSectionKeys, 'pages']);
const entries = computed(() => gridEntries(
    { items: catalog.items, pages: catalog.pages },
    (item, type) => catalog.sectionOf(item, type),
    order.value,
));
const chips = computed(() => sectionCounts(entries.value, order.value));
watch([() => section.value, () => query.value], () => {
    if (route.query.zoom || route.query.at || route.query.sel) router.replace({ query: withoutBoardState(route.query) });
});
const visible = computed(() => filterGridEntries(entries.value, { section: section.value, query: query.value }));
// The board shows what is visible as one row per sidebar section; with
// `pages.group_by: category` the pages get one row per category.
const pagesGroupBy = (() => { try { return readSpaConfig().pagesGroupBy; } catch { return null; } })();
const boardRows = computed(() => boardGroups(visible.value, {
    groupBy: pagesGroupBy,
    sectionLabel: (section) => i18n.t(`sections.${section}`),
    defaultLabel: i18n.t('sections.pages_other'),
}));

// One measurement of the grid's width decides the columns and the width of
// every tile (all columns are equal), instead of one observer per tile.
const container = ref(null);
// The scrolling element, handed to every tile as its IntersectionObserver
// root (see GridTile.vue).
const scroller = ref(null);
const containerWidth = ref(0);
let resizeObserver = null;
// The tiles' container exists only in the grid view: measure it whenever it
// appears, and stop watching the one that went.
watch(container, (el) => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    if (!el) return;
    containerWidth.value = el.clientWidth ?? 0;
    resizeObserver = new ResizeObserver((observed) => {
        for (const e of observed) containerWidth.value = e.contentRect.width;
    });
    resizeObserver.observe(el);
}, { flush: 'post' });
onBeforeUnmount(() => resizeObserver?.disconnect());
const layout = computed(() => gridLayout(containerWidth.value));

function srcFor(entry) {
    return buildRenderSrc({
        type: entry.type,
        slug: entry.id,
        variant: entry.variant,
        theme: ui.iframeTheme,
        contentLocale: contentLocale.value,
        defaultLocale: document.documentElement.dataset.defaultLocale || '',
    });
}

function pathFor(entry) {
    return `/${entry.type}/${entry.id}`;
}

function hrefFor(entry) {
    return router.resolve(pathFor(entry)).href;
}

function open(entry) {
    router.push(pathFor(entry));
}

const chipClass = pillState;
</script>

<template>
    <div
        ref="scroller"
        class="flex-1 min-h-0 bg-zinc-50 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100"
        :class="view === 'board' ? 'flex flex-col overflow-hidden' : 'overflow-y-auto'"
        data-testid="grid-view"
        :data-view="view"
    >
        <div :class="view === 'board' ? `flex flex-col flex-1 min-h-0 ${PAGE_PAD}` : `${PAGE_PAD} ${PAGE_PAD_BOTTOM}`">
            <header class="mb-6 flex flex-wrap items-end gap-4 justify-between" :class="view === 'board' && 'shrink-0'">
                <div class="min-w-0">
                    <h1 :class="PAGE_TITLE">{{ i18n.t('grid.title') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-zinc-500 leading-relaxed">{{ i18n.t('grid.subtitle') }}</p>
                </div>
            </header>

            <!-- Filter bar: one chip per sidebar section that has entries,
                 plus a text filter that matches like the sidebar's. -->
            <div class="flex flex-wrap items-center gap-2" :class="view === 'board' ? 'mb-4 shrink-0' : 'mb-6'" role="group" :aria-label="i18n.t('grid.filter_label')">
                <button
                    type="button"
                    data-testid="grid-filter-section"
                    :aria-pressed="section === null ? 'true' : 'false'"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors"
                    :class="chipClass(section === null)"
                    @click="section = null"
                >{{ i18n.t('grid.filter_all') }} <span class="opacity-60">{{ entries.length }}</span></button>
                <button
                    v-for="chip in chips"
                    :key="chip.section"
                    type="button"
                    data-testid="grid-filter-section"
                    :aria-pressed="section === chip.section ? 'true' : 'false'"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors"
                    :class="chipClass(section === chip.section)"
                    @click="section = chip.section"
                >{{ i18n.t(`sections.${chip.section}`) }} <span class="opacity-60">{{ chip.count }}</span></button>
                <div class="ml-auto flex items-center gap-2" role="group" :aria-label="i18n.t('grid.width_label')">
                    <button
                        v-for="width in widthOptions"
                        :key="width"
                        type="button"
                        data-testid="grid-width"
                        :aria-pressed="previewWidth === width ? 'true' : 'false'"
                        :title="`${width} px`"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-semibold transition-colors"
                        :class="chipClass(previewWidth === width)"
                        @click="ui.gridWidth = width"
                    >{{ widthLabel(width) }}</button>
                </div>
                <input
                    v-model="query"
                    type="search"
                    data-testid="grid-filter-query"
                    :placeholder="i18n.t('grid.filter_placeholder')"
                    :aria-label="i18n.t('grid.filter_placeholder')"
                    class="h-8 w-full sm:w-64 rounded-full border border-zinc-300 bg-white px-4 text-sm placeholder-zinc-500 dark:border-zinc-700 dark:bg-zinc-800"
                >
                <!-- Tiles or the board, as in a file manager: two joined icon
                     buttons, the same entries and the same filter. -->
                <div class="inline-flex h-8 shrink-0 overflow-hidden rounded-full border border-zinc-300 dark:border-zinc-700" role="group" :aria-label="i18n.t('grid.view_label')">
                    <button
                        v-for="option in VIEWS"
                        :key="option"
                        type="button"
                        data-testid="grid-view-toggle"
                        :aria-pressed="view === option ? 'true' : 'false'"
                        :aria-label="i18n.t(`grid.view_${option}`)"
                        :title="i18n.t(`grid.view_${option}`)"
                        class="inline-flex w-10 items-center justify-center transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-red-600"
                        :class="view === option
                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                            : 'bg-white text-zinc-600 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100'"
                        @click="setView(option)"
                    >
                        <svg v-if="option === 'grid'" aria-hidden="true" focusable="false" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>
                        </svg>
                        <svg v-else aria-hidden="true" focusable="false" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div v-if="view === 'board' && visible.length > 0" class="relative flex flex-col flex-1 min-h-0 -mx-6 lg:-mx-10 border-t border-zinc-200 dark:border-zinc-800" data-testid="grid-board">
                <BoardSurface
                    :groups="boardRows"
                    :preview-width="previewWidth"
                    :queue="queue"
                    :src-for="srcFor"
                    :href-for="hrefFor"
                    :initial-view="boardInitialView"
                    @open="open"
                    @viewchange="onBoardViewChange"
                />
            </div>
            <div
                v-else-if="view === 'grid'"
                ref="container"
                data-testid="grid-tiles"
                class="grid"
                :style="{ gridTemplateColumns: `repeat(${layout.columns}, minmax(0, 1fr))`, gap: `${GRID_GAP_PX}px` }"
            >
                <template v-if="layout.tileWidth > 0">
                    <GridTile
                        v-for="entry in visible"
                        :key="entry.key"
                        :entry="entry"
                        :src="srcFor(entry)"
                        :href="hrefFor(entry)"
                        :tile-width="layout.tileWidth"
                        :preview-width="previewWidth"
                        :queue="queue"
                        :scroll-root="scroller"
                        @open="open"
                    />
                </template>
            </div>
            <p v-if="!catalog.loading && visible.length === 0" data-testid="grid-empty" class="mt-6 text-sm text-zinc-500">{{ i18n.t('grid.empty') }}</p>
        </div>
    </div>
</template>
