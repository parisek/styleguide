<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { usePersistedRef } from '../lib/persistedRef.js';
import { externalLinksFor } from '../lib/externalLinks.js';

// Ported from frontend/components/overview.js + frontend/index.html:881-1070
// (the Components & Pages master index). Renders inside the SPA shell (not
// the iframe) so its visual chrome ships with the package. Pulls everything
// from the already-loaded catalog store; no new API call.
const catalog = useCatalogStore();
const i18n = useI18nStore();
const router = useRouter();

const showUsage = usePersistedRef('sg-overview-show-usage', true);

const pages = computed(() => catalog.pages.filter((p) => p.hasStyleguide !== false));

const componentSections = computed(() => {
    const buckets = {};
    for (const item of catalog.items) {
        if (item.hasStyleguide === false) continue;
        const section = catalog.sectionOf(item, 'component');
        if (!buckets[section]) buckets[section] = [];
        buckets[section].push(item);
    }
    // Reading order: Pages (own section, rendered separately above) -> Blocks
    // -> Gutenberg -> Basic — composite groups before the atomic-element bucket.
    return ['blocks', 'gutenberg', 'basic']
        .filter((section) => buckets[section]?.length > 0)
        .map((section) => ({ section, items: buckets[section] }));
});

// Memoization layer the legacy overview.js component owned itself
// (`_buildForwardMap`/`_buildReverseMap`, invalidated on pages/items count
// change) but catalog.js's reverseUsageFor()/forwardUsageFor() (Task 3) do
// NOT provide — each call rebuilds its lookup from scratch. The template
// below reads forwardUsage(page) twice per page row (once for the `v-if`
// length gate, once in the `v-for` chip list) and reverseUsage(item.id)
// twice per component row for the same reason. Without this computed layer
// every one of those second reads would silently redo the linear scan/CSV
// split the first read already did. Building each map ONCE per computed
// (Vue caches it until catalog.pages/catalog.items change) restores the
// "compute once, look up twice for free" characteristic the legacy code had
// — see Task 11 report for the follow-up note that catalog.js's own
// per-call cost (O(pages) per id) is unchanged; this only removes the
// view-level duplication.
const forwardUsageMap = computed(() => {
    const map = new Map();
    for (const page of pages.value) {
        map.set(page.id, catalog.forwardUsageFor(page));
    }
    return map;
});

const reverseUsageMap = computed(() => {
    const map = new Map();
    for (const section of componentSections.value) {
        for (const item of section.items) {
            map.set(item.id, catalog.reverseUsageFor(item.id));
        }
    }
    return map;
});

function select(item) {
    if (!item.type) return;
    router.push(`/${item.type}/${item.id}`);
}

function linksFor(item) {
    return externalLinksFor(item);
}

function forwardUsage(page) {
    return forwardUsageMap.value.get(page.id) ?? [];
}

function reverseUsage(id) {
    return reverseUsageMap.value.get(id) ?? [];
}
</script>

<template>
    <div class="flex-1 overflow-y-auto bg-zinc-50 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <div class="px-6 py-10 lg:px-10 lg:py-14">

            <!-- Header: title + subtitle + switch toggle -->
            <header class="mb-10 pb-6 border-b border-zinc-200 dark:border-zinc-800 flex flex-wrap items-end gap-4 justify-between">
                <div class="min-w-0">
                    <h1 class="font-bold text-2xl sm:text-3xl tracking-tight text-zinc-900 dark:text-zinc-100">{{ i18n.t('overview.title') }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-zinc-500 leading-relaxed">{{ i18n.t('overview.subtitle') }}</p>
                </div>
                <label class="inline-flex items-center gap-3 cursor-pointer select-none group shrink-0">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 group-hover:text-zinc-900 dark:group-hover:text-zinc-100 transition-colors">{{ i18n.t('overview.show_usage') }}</span>
                    <!-- Checkbox is a sibling of the track (not nested) so the
                         `peer-focus-visible:*` utilities on the track resolve.
                         Tab lands on the sr-only checkbox; the focus ring renders
                         on the visual track instead, so keyboard users get a
                         clear affordance. -->
                    <input type="checkbox" v-model="showUsage" class="peer sr-only">
                    <span class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-zinc-900 dark:peer-focus-visible:outline-zinc-100"
                          :class="showUsage ? 'bg-zinc-900 dark:bg-zinc-100' : 'bg-zinc-300 dark:bg-zinc-700 group-hover:bg-zinc-400 dark:group-hover:bg-zinc-600'">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white dark:bg-zinc-900 shadow-sm transition-transform duration-200"
                              :class="showUsage ? 'translate-x-[18px]' : 'translate-x-1'"></span>
                    </span>
                </label>
            </header>

            <!-- Sections grid: Pages + components, side by side on wide viewports -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6 items-start">

                <!-- Pages section -->
                <section v-show="pages.length > 0">
                    <div class="flex items-center mb-4">
                        <div class="shrink-0 w-9 h-9 mr-3 rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-500/20 dark:text-violet-300 flex items-center justify-center">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                            </svg>
                        </div>
                        <h2 class="text-base font-semibold tracking-tight mr-2">{{ i18n.t('overview.pages') }}</h2>
                        <span class="inline-flex items-center justify-center px-2 h-6 min-w-6 rounded-full bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 text-xs font-mono">{{ pages.length }}</span>
                    </div>
                    <div class="rounded-2xl border border-zinc-200/80 bg-white dark:border-zinc-700/80 dark:bg-zinc-800">
                        <div v-for="(page, idx) in pages" :key="page.id"
                             :class="idx > 0 && 'border-t border-zinc-100 dark:border-zinc-700'" class="px-5 py-3">
                            <div class="flex items-center gap-2 flex-wrap justify-between min-h-7">
                                <div class="flex items-baseline gap-2 flex-wrap min-w-0">
                                    <a href="#" @click.prevent="select({ id: page.id, type: 'page' })"
                                       class="font-semibold text-zinc-900 hover:text-zinc-500 dark:text-zinc-100 dark:hover:text-zinc-400 transition-colors">{{ page.name ?? page.id }}</a>
                                    <code class="text-xs font-mono text-zinc-400">{{ page.id }}</code>
                                </div>
                                <div v-if="linksFor(page).length > 0" class="flex items-center gap-1 shrink-0">
                                    <a v-for="link in linksFor(page)" :key="link.key"
                                       :href="link.url" target="_blank" rel="noopener"
                                       :title="`${link.label} — ${link.url}`"
                                       class="inline-flex items-center justify-center w-7 h-7 rounded bg-zinc-100 text-zinc-700 hover:bg-zinc-200 hover:text-zinc-900 dark:bg-zinc-700/60 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white transition-colors">
                                        <svg v-if="link.key === 'asana'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5 text-rose-500 dark:text-rose-400" viewBox="0 0 24 24" fill="currentColor"><path d="M18.78 12.653c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm-13.56 0c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm11.267-5.14c0 2.478-2.009 4.487-4.487 4.487-2.478 0-4.487-2.009-4.487-4.487 0-2.478 2.009-4.487 4.487-4.487 2.478 0 4.487 2.009 4.487 4.487"/></svg>
                                        <svg v-if="link.key === 'figma'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 15 22" fill="none"><path d="M7.5 11a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0z" fill="#1ABCFE"/><path d="M0 18.25a3.75 3.75 0 0 1 3.75-3.75H7.5V22a3.75 3.75 0 1 1-7.5 0v-3.75z" fill="#0ACF83"/><path d="M7.5 0v7.5h3.75a3.75 3.75 0 1 0 0-7.5H7.5z" fill="#FF7262"/><path d="M0 3.75A3.75 3.75 0 0 0 3.75 7.5H7.5V0H3.75A3.75 3.75 0 0 0 0 3.75z" fill="#F24E1E"/><path d="M0 11a3.75 3.75 0 0 0 3.75 3.75H7.5V7.5H3.75A3.75 3.75 0 0 0 0 11z" fill="#A259FF"/></svg>
                                        <svg v-if="link.key === 'drupal'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="#0678be"><path d="M12 0C10.736 3.256 8.928 4.976 6.624 7.28 3.456 10.448 2 13.12 2 16.064 2 20.448 6.464 24 12 24s10-3.552 10-7.936c0-2.944-1.456-5.616-4.624-8.784C15.072 4.976 13.264 3.256 12 0z"/></svg>
                                        <svg v-if="link.key === 'web'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    </a>
                                </div>
                            </div>
                            <div v-if="showUsage && forwardUsage(page).length > 0" class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-700">
                                <p class="text-[10px] uppercase tracking-[0.12em] font-medium text-zinc-400 mb-2">{{ i18n.t('overview.components') }}</p>
                                <ul class="space-y-1">
                                    <li v-for="chip in forwardUsage(page)" :key="chip.id" class="flex items-center justify-between gap-2">
                                        <button type="button" @click="select(chip)"
                                                :class="chip.type ? 'text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-zinc-50 cursor-pointer' : 'text-zinc-400 cursor-default'"
                                                class="text-sm text-left transition-colors min-w-0 truncate">{{ chip.name }}</button>
                                        <div v-if="linksFor(chip).length > 0" class="flex items-center gap-1 shrink-0">
                                            <a v-for="link in linksFor(chip)" :key="link.key"
                                               :href="link.url" target="_blank" rel="noopener"
                                               :title="`${link.label} — ${link.url}`"
                                               class="inline-flex items-center justify-center w-6 h-6 rounded bg-zinc-100 text-zinc-700 hover:bg-zinc-200 hover:text-zinc-900 dark:bg-zinc-700/60 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white transition-colors">
                                                <svg v-if="link.key === 'asana'" aria-hidden="true" focusable="false" class="w-3 h-3 text-rose-500 dark:text-rose-400" viewBox="0 0 24 24" fill="currentColor"><path d="M18.78 12.653c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm-13.56 0c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm11.267-5.14c0 2.478-2.009 4.487-4.487 4.487-2.478 0-4.487-2.009-4.487-4.487 0-2.478 2.009-4.487 4.487-4.487 2.478 0 4.487 2.009 4.487 4.487"/></svg>
                                                <svg v-if="link.key === 'figma'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 15 22" fill="none"><path d="M7.5 11a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0z" fill="#1ABCFE"/><path d="M0 18.25a3.75 3.75 0 0 1 3.75-3.75H7.5V22a3.75 3.75 0 1 1-7.5 0v-3.75z" fill="#0ACF83"/><path d="M7.5 0v7.5h3.75a3.75 3.75 0 1 0 0-7.5H7.5z" fill="#FF7262"/><path d="M0 3.75A3.75 3.75 0 0 0 3.75 7.5H7.5V0H3.75A3.75 3.75 0 0 0 0 3.75z" fill="#F24E1E"/><path d="M0 11a3.75 3.75 0 0 0 3.75 3.75H7.5V7.5H3.75A3.75 3.75 0 0 0 0 11z" fill="#A259FF"/></svg>
                                                <svg v-if="link.key === 'drupal'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 24 24" fill="#0678be"><path d="M12 0C10.736 3.256 8.928 4.976 6.624 7.28 3.456 10.448 2 13.12 2 16.064 2 20.448 6.464 24 12 24s10-3.552 10-7.936c0-2.944-1.456-5.616-4.624-8.784C15.072 4.976 13.264 3.256 12 0z"/></svg>
                                                <svg v-if="link.key === 'web'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Component sections (basic / blocks / gutenberg) -->
                <section v-for="block in componentSections" :key="block.section">
                    <div class="flex items-center mb-4">
                        <!-- Section icon (color + glyph per section key) -->
                        <div v-if="block.section === 'basic'" class="shrink-0 w-9 h-9 mr-3 rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300 flex items-center justify-center">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/>
                            </svg>
                        </div>
                        <div v-if="block.section === 'blocks'" class="shrink-0 w-9 h-9 mr-3 rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300 flex items-center justify-center">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3"/>
                            </svg>
                        </div>
                        <div v-if="block.section === 'gutenberg'" class="shrink-0 w-9 h-9 mr-3 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300 flex items-center justify-center">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z"/>
                            </svg>
                        </div>
                        <h2 class="text-base font-semibold tracking-tight mr-2">{{ i18n.t(`sections.${block.section}`) }}</h2>
                        <span class="inline-flex items-center justify-center px-2 h-6 min-w-6 rounded-full bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 text-xs font-mono">{{ block.items.length }}</span>
                    </div>
                    <div class="rounded-2xl border border-zinc-200/80 bg-white dark:border-zinc-700/80 dark:bg-zinc-800">
                        <div v-for="(item, idx) in block.items" :key="item.id"
                             :class="idx > 0 && 'border-t border-zinc-100 dark:border-zinc-700'" class="px-5 py-3">
                            <div class="flex items-center gap-2 flex-wrap justify-between min-h-7">
                                <div class="flex items-baseline gap-2 flex-wrap min-w-0">
                                    <a href="#" @click.prevent="select({ id: item.id, type: 'component' })"
                                       class="font-semibold text-zinc-900 hover:text-zinc-500 dark:text-zinc-100 dark:hover:text-zinc-400 transition-colors">{{ item.name ?? item.id }}</a>
                                    <code class="text-xs font-mono text-zinc-400">{{ item.id }}</code>
                                </div>
                                <div v-if="linksFor(item).length > 0" class="flex items-center gap-1 shrink-0">
                                    <a v-for="link in linksFor(item)" :key="link.key"
                                       :href="link.url" target="_blank" rel="noopener"
                                       :title="`${link.label} — ${link.url}`"
                                       class="inline-flex items-center justify-center w-7 h-7 rounded bg-zinc-100 text-zinc-700 hover:bg-zinc-200 hover:text-zinc-900 dark:bg-zinc-700/60 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white transition-colors">
                                        <svg v-if="link.key === 'asana'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5 text-rose-500 dark:text-rose-400" viewBox="0 0 24 24" fill="currentColor"><path d="M18.78 12.653c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm-13.56 0c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm11.267-5.14c0 2.478-2.009 4.487-4.487 4.487-2.478 0-4.487-2.009-4.487-4.487 0-2.478 2.009-4.487 4.487-4.487 2.478 0 4.487 2.009 4.487 4.487"/></svg>
                                        <svg v-if="link.key === 'figma'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 15 22" fill="none"><path d="M7.5 11a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0z" fill="#1ABCFE"/><path d="M0 18.25a3.75 3.75 0 0 1 3.75-3.75H7.5V22a3.75 3.75 0 1 1-7.5 0v-3.75z" fill="#0ACF83"/><path d="M7.5 0v7.5h3.75a3.75 3.75 0 1 0 0-7.5H7.5z" fill="#FF7262"/><path d="M0 3.75A3.75 3.75 0 0 0 3.75 7.5H7.5V0H3.75A3.75 3.75 0 0 0 0 3.75z" fill="#F24E1E"/><path d="M0 11a3.75 3.75 0 0 0 3.75 3.75H7.5V7.5H3.75A3.75 3.75 0 0 0 0 11z" fill="#A259FF"/></svg>
                                        <svg v-if="link.key === 'drupal'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="#0678be"><path d="M12 0C10.736 3.256 8.928 4.976 6.624 7.28 3.456 10.448 2 13.12 2 16.064 2 20.448 6.464 24 12 24s10-3.552 10-7.936c0-2.944-1.456-5.616-4.624-8.784C15.072 4.976 13.264 3.256 12 0z"/></svg>
                                        <svg v-if="link.key === 'web'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    </a>
                                </div>
                            </div>
                            <div v-if="showUsage && reverseUsage(item.id).length > 0" class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-700">
                                <p class="text-[10px] uppercase tracking-[0.12em] font-medium text-zinc-400 mb-2">{{ i18n.t('overview.used_in') }}</p>
                                <ul class="space-y-1">
                                    <li v-for="chip in reverseUsage(item.id)" :key="chip.id" class="flex items-center justify-between gap-2">
                                        <button type="button" @click="select(chip)"
                                                class="text-sm text-left text-zinc-700 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-zinc-50 cursor-pointer transition-colors min-w-0 truncate">{{ chip.name }}</button>
                                        <div v-if="linksFor(chip).length > 0" class="flex items-center gap-1 shrink-0">
                                            <a v-for="link in linksFor(chip)" :key="link.key"
                                               :href="link.url" target="_blank" rel="noopener"
                                               :title="`${link.label} — ${link.url}`"
                                               class="inline-flex items-center justify-center w-6 h-6 rounded bg-zinc-100 text-zinc-700 hover:bg-zinc-200 hover:text-zinc-900 dark:bg-zinc-700/60 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white transition-colors">
                                                <svg v-if="link.key === 'asana'" aria-hidden="true" focusable="false" class="w-3 h-3 text-rose-500 dark:text-rose-400" viewBox="0 0 24 24" fill="currentColor"><path d="M18.78 12.653c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm-13.56 0c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm11.267-5.14c0 2.478-2.009 4.487-4.487 4.487-2.478 0-4.487-2.009-4.487-4.487 0-2.478 2.009-4.487 4.487-4.487 2.478 0 4.487 2.009 4.487 4.487"/></svg>
                                                <svg v-if="link.key === 'figma'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 15 22" fill="none"><path d="M7.5 11a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0z" fill="#1ABCFE"/><path d="M0 18.25a3.75 3.75 0 0 1 3.75-3.75H7.5V22a3.75 3.75 0 1 1-7.5 0v-3.75z" fill="#0ACF83"/><path d="M7.5 0v7.5h3.75a3.75 3.75 0 1 0 0-7.5H7.5z" fill="#FF7262"/><path d="M0 3.75A3.75 3.75 0 0 0 3.75 7.5H7.5V0H3.75A3.75 3.75 0 0 0 0 3.75z" fill="#F24E1E"/><path d="M0 11a3.75 3.75 0 0 0 3.75 3.75H7.5V7.5H3.75A3.75 3.75 0 0 0 0 11z" fill="#A259FF"/></svg>
                                                <svg v-if="link.key === 'drupal'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 24 24" fill="#0678be"><path d="M12 0C10.736 3.256 8.928 4.976 6.624 7.28 3.456 10.448 2 13.12 2 16.064 2 20.448 6.464 24 12 24s10-3.552 10-7.936c0-2.944-1.456-5.616-4.624-8.784C15.072 4.976 13.264 3.256 12 0z"/></svg>
                                                <svg v-if="link.key === 'web'" aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
