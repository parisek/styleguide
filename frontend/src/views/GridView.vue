<script setup>
// The overview grid (route /grid, and the landing with
// `overview.default: grid`): every component and page as a tile with a
// scaled live preview. A filter bar narrows it by sidebar section and by
// text; a tile opens the entry. Loading rules live in GridTile.vue and
// lib/loadQueue.js; data and layout rules in lib/catalogGrid.js.
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';
import { useContentLocale } from '../composables/useContentLocale.js';
import { gridEntries, filterGridEntries, sectionCounts, gridLayout, GRID_GAP_PX } from '../lib/catalogGrid.js';
import { createLoadQueue } from '../lib/loadQueue.js';
import { buildRenderSrc } from '../lib/renderSrc.js';
import GridTile from '../components/GridTile.vue';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const ui = useUiStore();
const router = useRouter();
const { contentLocale } = useContentLocale();

// One queue per visit of the view: 6 renders at a time across all tiles.
const queue = createLoadQueue();

const section = ref(null);
const query = ref('');

const entries = computed(() => gridEntries(
    { items: catalog.items, pages: catalog.pages },
    (item, type) => catalog.sectionOf(item, type),
));
const chips = computed(() => sectionCounts(entries.value));
const visible = computed(() => filterGridEntries(entries.value, { section: section.value, query: query.value }));

// One measurement of the grid's width decides the columns and the width of
// every tile (all columns are equal), instead of one observer per tile.
const container = ref(null);
// The scrolling element, handed to every tile as its IntersectionObserver
// root (see GridTile.vue).
const scroller = ref(null);
const containerWidth = ref(0);
let resizeObserver = null;
onMounted(() => {
    containerWidth.value = container.value?.clientWidth ?? 0;
    resizeObserver = new ResizeObserver((observed) => {
        for (const e of observed) containerWidth.value = e.contentRect.width;
    });
    resizeObserver.observe(container.value);
});
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

function chipClass(active) {
    return active
        ? 'bg-zinc-900 text-white border-zinc-900 dark:bg-zinc-100 dark:text-zinc-900 dark:border-zinc-100'
        : 'bg-white text-zinc-600 border-zinc-300 hover:border-zinc-400 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:text-zinc-100';
}
</script>

<template>
    <div ref="scroller" class="flex-1 overflow-y-auto bg-zinc-50 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100" data-testid="grid-view">
        <div class="px-6 py-8 lg:px-10 lg:py-10">
            <header class="mb-6 flex flex-wrap items-end gap-4 justify-between">
                <div class="min-w-0">
                    <h1 class="font-bold text-2xl sm:text-3xl tracking-tight">{{ i18n.t('grid.title') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-zinc-500 leading-relaxed">{{ i18n.t('grid.subtitle') }}</p>
                </div>
            </header>

            <!-- Filter bar: one chip per sidebar section that has entries,
                 plus a text filter that matches like the sidebar's. -->
            <div class="mb-6 flex flex-wrap items-center gap-2" role="group" :aria-label="i18n.t('grid.filter_label')">
                <button
                    type="button"
                    data-testid="grid-filter-section"
                    :aria-pressed="section === null ? 'true' : 'false'"
                    class="rounded-full border px-3 py-1 text-xs font-semibold transition-colors"
                    :class="chipClass(section === null)"
                    @click="section = null"
                >{{ i18n.t('grid.filter_all') }} <span class="opacity-60">{{ entries.length }}</span></button>
                <button
                    v-for="chip in chips"
                    :key="chip.section"
                    type="button"
                    data-testid="grid-filter-section"
                    :aria-pressed="section === chip.section ? 'true' : 'false'"
                    class="rounded-full border px-3 py-1 text-xs font-semibold transition-colors"
                    :class="chipClass(section === chip.section)"
                    @click="section = chip.section"
                >{{ i18n.t(`sections.${chip.section}`) }} <span class="opacity-60">{{ chip.count }}</span></button>
                <input
                    v-model="query"
                    type="search"
                    data-testid="grid-filter-query"
                    :placeholder="i18n.t('grid.filter_placeholder')"
                    :aria-label="i18n.t('grid.filter_placeholder')"
                    class="ml-auto w-full sm:w-64 rounded-full border border-zinc-300 bg-white px-4 py-1.5 text-sm placeholder-zinc-500 dark:border-zinc-700 dark:bg-zinc-800"
                >
            </div>

            <div
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
