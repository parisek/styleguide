<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useThemeStore } from '../stores/theme.js';
import { filterItems } from '../lib/searchMatch.js';
import { usePersistedRef } from '../lib/persistedRef.js';
import { routeInfo } from '../lib/routeInfo.js';
import HealthWarningBadge from './HealthWarningBadge.vue';
// Read directly rather than `import { config } from '../main.js'`: main.js
// -> App.vue -> Sidebar.vue is already an import chain, so pulling `config`
// back out of main.js here would close a circular-import loop. readSpaConfig
// is cheap and idempotent (re-parses the static #sg-config JSON payload), so
// a second independent read costs nothing and keeps this component decoupled
// from main.js's boot-sequencing side effects (app.mount, favicon fallback
// wiring, title sync).
import { readSpaConfig } from '../lib/config.js';
import { GENERIC_FAVICON } from '../lib/documentChrome.js';
import { useContentLocale } from '../composables/useContentLocale.js';

const catalog = useCatalogStore();
const ui = useUiStore();
const i18n = useI18nStore();
const theme = useThemeStore();
// Same switcher click drives both: i18n.load() (chrome UI strings, its own
// closed SUPPORTED set + 'sg-locale' storage key) and setContentLocale()
// (which catalogue the iframe renders, namespaced 'styleguide:locale' key —
// see lib/contentLocale.js for why the two keys are kept separate).
const { setContentLocale } = useContentLocale();
const route = useRoute();
const router = useRouter();

const sections = usePersistedRef('sg-sections', {
    docs: true, basic: true, blocks: true, gutenberg: false, pages: false,
});
const groups = usePersistedRef('sg-groups', {});
const config = readSpaConfig();
// Swapped to GENERIC_FAVICON by the <img> @error handler when the configured
// favicon 404s / isn't a valid image — replaces the DOMContentLoaded +
// getElementById error-listener that main.js used to wire onto an element
// this component renders (fragile ordering across the app mount).
const faviconSrc = ref(config.favicon);

const searchInputRef = ref(null);

// Scoped Escape-to-clear (Task 5 review finding). This behavior used to be
// global (useSearchShortcuts.js, now retired): pressing Escape anywhere
// cleared this filter, regardless of focus. That global reach directly
// fought with the new command palette (SearchPalette.vue), which also wants
// Escape to mean "close me" -- dismissing the palette would blank whatever
// the user had separately typed in here. Binding @keydown.escape directly
// on this <input> narrows the behavior deliberately: the native keydown
// only reaches this handler while the event target is (or bubbles from)
// this specific input, i.e. only when it already has focus, so the two
// Escape behaviors can never collide.
function onFilterEscape() {
    ui.searchQuery = '';
    searchInputRef.value?.blur();
}

function toggleSection(key) {
    sections.value[key] = !sections.value[key];
}

function groupKey(section, prefix) {
    return `${section}/${prefix}`;
}

function isGroupOpen(section, prefix, children) {
    // Group children are components in component sections and pages in the
    // Pages section — check both so a deep-linked active child force-opens
    // its group regardless of kind.
    if (children.some((c) => isActive('component', c.id) || isActive('page', c.id))) return true;
    return groups.value[groupKey(section, prefix)] ?? true;
}

function toggleGroup(section, prefix) {
    const key = groupKey(section, prefix);
    groups.value[key] = !(groups.value[key] ?? true);
}

function isActive(type, slug) {
    // Delegate to routeInfo() instead of comparing route.name/params.slug
    // directly — it already folds 'landing'/'not-found-fallback' into
    // 'foundations' (mirrors legacy router.js's `apply()`), so callers no
    // longer need a separate isActiveMeta() to special-case those routes.
    const info = routeInfo(route);
    return info.type === type && info.slug === slug;
}

function select(type, slug) {
    // Sectionless URLs (`/overview`, `/foundations`) don't carry a slug.
    const path = slug ? `/${type}/${slug}` : `/${type}`;
    router.push(path);
    // On small screens the sidebar is a slide-over overlay covering the
    // preview — close it after a pick so the chosen item is visible.
    // No-op on desktop where the sidebar is a persistent column.
    if (window.matchMedia('(max-width: 1023px)').matches) {
        ui.sidebarOpen = false;
    }
}

function items(section) {
    return filterItems(catalog.bySection(section), ui.searchQuery);
}

const docItems = computed(() => filterItems(catalog.docEntries, ui.searchQuery));
const pageItems = computed(() => filterItems(catalog.pages.filter((p) => p.has_styleguide !== false), ui.searchQuery));

function supportedLocales() {
    return ['cs', 'en'];
}
</script>

<template>
    <!-- Mobile slide-over: translateX(-100%)<->0 over 240ms with an
         iOS-style deceleration curve (cubic-bezier(0.32,0.72,0,1) -- fast
         start, gentle settle), gated behind motion-safe: so a
         reduced-motion user gets an instant show/hide instead. `lg:*`
         overrides keep the desktop persistent-column behavior (no
         transition, no fixed positioning) exactly as before. -->
    <aside
        class="w-72 bg-zinc-50 border-r border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800 flex flex-col fixed inset-y-0 left-0 z-50 motion-safe:transition-transform motion-safe:duration-[240ms] motion-safe:ease-[cubic-bezier(0.32,0.72,0,1)] lg:static lg:z-auto lg:transition-none"
        :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:hidden'"
    >
        <!-- Header. Logo + project name link to the project's real
             homepage at `/` (the consumer's WordPress / Drupal / static
             site root, not the styleguide). `target="_top"` makes the
             click escape any embedding iframe context. The theme toggle
             sits outside the link so it doesn't navigate. -->
        <div class="p-4 border-b border-zinc-200 dark:border-zinc-800 flex items-center gap-3">
            <a
                href="/"
                target="_top"
                :title="i18n.t('nav.homepage_link') || 'Otevřít projekt'"
                class="flex items-center gap-3 min-w-0 flex-1 hover:opacity-80 transition-opacity"
            >
                <!-- iOS-app-icon style: the rounded frame is the WRAPPER, the favicon
                     itself is a plain square img centered inside it with a safe-area
                     margin — so any icon shape sits nicely off the frame's edges. -->
                <span class="w-8 h-8 rounded-lg bg-zinc-200 dark:bg-zinc-50 shrink-0 ring-1 ring-red-500/20 flex items-center justify-center overflow-hidden">
                    <img v-if="config.favicon" :src="faviconSrc" :alt="config.projectName" class="w-6 h-6 object-contain" id="sg-favicon" @error="faviconSrc = GENERIC_FAVICON">
                </span>
                <div class="min-w-0 flex-1">
                    <div class="font-bold text-sm text-zinc-900 dark:text-zinc-50 truncate" id="sg-project-name">{{ config.projectName }}</div>
                    <div class="text-xs text-zinc-500 truncate">{{ i18n.t('nav.styleguide') }}</div>
                </div>
            </a>
            <!-- Parser-warnings badge — hidden by default, appears only when
                 GET /styleguide/api/health reports skipped templates. -->
            <HealthWarningBadge />
            <!-- Theme toggle: cycles light → dark → system → light. The
                 icon swap follows the *chosen* mode (not the resolved
                 theme) so the user sees which mode they're in, including
                 the `system` state which renders the monitor glyph. -->
            <button
                @click="theme.cycle()"
                :title="`${i18n.t('theme.' + theme.mode)} — ${i18n.t('theme.toggle')}`"
                :aria-label="i18n.t('theme.toggle')"
                class="shrink-0 w-9 h-9 flex items-center justify-center rounded-full border border-zinc-300 text-zinc-600 hover:text-zinc-900 hover:border-zinc-400 dark:border-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:border-zinc-600 transition-colors"
            >
                <template v-if="theme.mode === 'light'">
                    <svg aria-hidden="true" focusable="false" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                    </svg>
                </template>
                <template v-if="theme.mode === 'dark'">
                    <svg aria-hidden="true" focusable="false" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </template>
                <template v-if="theme.mode === 'system'">
                    <svg aria-hidden="true" focusable="false" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <path d="M8 21h8M12 17v4"/>
                    </svg>
                </template>
            </button>
        </div>

        <!-- Search: "SEARCH" label over a pill input (birdclaw style). No bottom
             divider — the airier spacing carries the separation. ⌘K/Ctrl+K now
             opens the global command palette (SearchPalette.vue) instead of
             focusing this input; Esc-to-clear survives but is scoped to this
             input via @keydown.escape (see onFilterEscape). -->
        <div class="px-4 pt-4 pb-1">
            <div class="px-1 pb-2 text-[10px] uppercase tracking-wider font-bold text-zinc-500">{{ i18n.t('search.label') }}</div>
            <div class="relative">
                <input
                    ref="searchInputRef"
                    v-model="ui.searchQuery"
                    type="text"
                    class="w-full px-5 py-2.5 pr-14 bg-white border border-zinc-300 dark:bg-zinc-800 dark:border-zinc-700 rounded-full text-sm placeholder-zinc-500"
                    :placeholder="i18n.t('search.placeholder')"
                    @keydown.escape="onFilterEscape"
                >
                <kbd class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 hidden sm:inline-flex items-center text-[11px] font-medium text-zinc-400 dark:text-zinc-500 border border-zinc-200 dark:border-zinc-700 rounded px-1.5 py-0.5 leading-none">{{ i18n.t('search.shortcut_hint') }}</kbd>
            </div>
        </div>

        <!-- Sections + items (scroll) -->
        <nav class="flex-1 overflow-y-auto px-2 py-2 space-y-3">
            <!-- DOKUMENTACE — package meta-views (foundations, overview) pinned
                 first, then consumer doc entries (server-sorted by weight). -->
            <div>
                <button @click="toggleSection('docs')" :aria-expanded="(sections.docs || !!ui.searchQuery) ? 'true' : 'false'" class="w-full flex items-center px-3 py-1.5 text-[10px] uppercase tracking-wider font-bold text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    <span>{{ i18n.t('nav.docs') }}</span>
                </button>
                <!-- Collapse animation: CSS grid-template-rows 0fr<->1fr technique
                     (no JS height measurement) -- the outer div's row size
                     tweens between the two, the inner overflow-hidden div
                     clips the content during the tween. `:inert` mirrors the
                     old v-show's display:none by pulling collapsed content
                     out of tab order / the a11y tree without fighting the
                     height transition the way `visibility: hidden` would. -->
                <div class="grid motion-safe:transition-[grid-template-rows] motion-safe:duration-200 motion-safe:ease-out" :style="{ gridTemplateRows: (sections.docs || ui.searchQuery) ? '1fr' : '0fr' }" :inert="!(sections.docs || ui.searchQuery)">
                <ul class="mt-1 space-y-0.5 overflow-hidden">
                    <li>
                        <a href="#" @click.prevent="select('foundations', null)" class="block px-3.5 py-2 text-sm rounded-lg transition-colors" :class="isActive('foundations', null) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'">
                            <span>{{ i18n.t('nav.foundations') }}</span>
                        </a>
                    </li>
                    <!-- Standalone icon catalog (#87) — gated on the server-side
                         yaml-shape check (sg-config hasIcons) so projects without
                         an icons: block don't get a dead menu entry. -->
                    <li v-if="config.hasIcons">
                        <a href="#" @click.prevent="select('icons', null)" class="block px-3.5 py-2 text-sm rounded-lg transition-colors" :class="isActive('icons', null) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'">
                            <span>{{ i18n.t('nav.icons') }}</span>
                        </a>
                    </li>
                    <!-- Cross-component fields overview (#95) — gated on live
                         catalogue data (catalog.hasFields) rather than a
                         server-injected config flag like Icons above, since
                         "any component declares fields" is only known once
                         the components API response has landed. -->
                    <li v-if="catalog.hasFields">
                        <a href="#" @click.prevent="select('fields', null)" class="block px-3.5 py-2 text-sm rounded-lg transition-colors" :class="isActive('fields', null) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'">
                            <span>{{ i18n.t('nav.fields') }}</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" @click.prevent="select('overview', null)" class="block px-3.5 py-2 text-sm rounded-lg transition-colors" :class="isActive('overview', null) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'">
                            <span>{{ i18n.t('nav.overview') }}</span>
                        </a>
                    </li>
                    <li v-for="item in docItems" :key="item.id">
                        <a href="#" @click.prevent="select('doc', item.id)" class="block px-3.5 py-2 text-sm rounded-lg transition-colors" :class="isActive('doc', item.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'">
                            <span>{{ item.name }}</span>
                        </a>
                    </li>
                </ul>
                </div>
            </div>

            <!-- Hide entire section when search yields zero matches so the
                 sidebar collapses to just what's relevant. `v-show` MUST NOT
                 sit on the same element as `v-for`: Vue 3 only evaluates a
                 v-show binding once, at that element's *creation* patch, and
                 v-for-generated nodes are created exactly once for the
                 lifetime of their key -- later reactive re-renders of that
                 node update its other bindings (e.g. the count badge below)
                 but skip re-checking v-show. Real app boot calls
                 catalog.init() (async) without awaiting it before app.mount(),
                 so the very first render always sees 0 items and v-show
                 freezes every section at display:none forever, even once the
                 fetch resolves. The `<template v-for>` + inner `v-show` split
                 sidesteps this: the template block itself carries no DOM
                 node to freeze, and the inner div's v-show is a normal
                 (non-v-for) binding that re-evaluates on every update, same
                 as the legacy Alpine markup's `x-show` on the section
                 wrapper. -->
            <template v-for="section in ['basic', 'blocks', 'gutenberg']" :key="section">
            <div v-show="items(section).length > 0">
                <button @click="toggleSection(section)" :aria-expanded="(sections[section] || !!ui.searchQuery) ? 'true' : 'false'" class="w-full flex justify-between items-center px-3 py-1.5 text-[10px] uppercase tracking-wider font-bold text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    <span>{{ i18n.t(`sections.${section}`) }}</span>
                    <span class="text-zinc-400 dark:text-zinc-600 font-semibold">{{ items(section).length }}</span>
                </button>
                <!-- Force-open the section when a search is active so the
                     match is visible without an extra click; otherwise
                     respect the persisted collapsed/expanded state.
                     Collapse animates via the CSS grid-template-rows
                     0fr<->1fr technique (see the docs section comment
                     above) rather than v-show, so opening/closing tweens
                     height instead of snapping. -->
                <div class="grid motion-safe:transition-[grid-template-rows] motion-safe:duration-200 motion-safe:ease-out" :style="{ gridTemplateRows: (sections[section] || ui.searchQuery) ? '1fr' : '0fr' }" :inert="!(sections[section] || ui.searchQuery)">
                <ul class="mt-1 space-y-0.5 overflow-hidden">
                    <!-- While searching: flat full-name results (grouping bypassed, spec #38). -->
                    <li v-for="item in (ui.searchQuery ? items(section) : [])" :key="'s:' + item.id">
                        <a
                            href="#"
                            @click.prevent="select('component', item.id)"
                            class="block px-3.5 py-2 text-sm rounded-lg transition-colors"
                            :class="isActive('component', item.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                        >
                            <span>{{ item.name ?? item.id }}</span>
                        </a>
                    </li>
                    <!-- Otherwise: prefix tree (groups >= 3, suffix-only children). -->
                    <li v-for="node in (ui.searchQuery ? [] : catalog.treeOf(section))" :key="node.type === 'group' ? 'g:' + node.label : 'i:' + node.item.id">
                        <!-- Top-level items sit flush-left, aligned with section
                             content; only grouped children (below) indent. -->
                        <a
                            v-if="node.type === 'item'"
                            href="#"
                            @click.prevent="select('component', node.item.id)"
                            class="block px-3.5 py-2 text-sm rounded-lg transition-colors"
                            :class="isActive('component', node.item.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                        >
                            <span>{{ node.item.name ?? node.item.id }}</span>
                        </a>
                        <!-- Group row: no chevron -- the count badge alone signals
                             a group, and dropping the arrow glyph lets the label
                             sit flush at the same left padding (px-3.5) as every
                             flat sibling item above/below it, instead of being
                             indented out of line by the icon + gap. The whole row
                             stays the expand/collapse toggle; aria-expanded keeps
                             the state programmatically discoverable now that the
                             visual chevron cue is gone. -->
                        <div v-else>
                            <button @click="toggleGroup(section, node.label)" :aria-expanded="isGroupOpen(section, node.label, node.children) ? 'true' : 'false'" class="w-full flex items-center px-3.5 py-2 text-sm rounded-lg text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white transition-colors">
                                <span class="font-medium">{{ node.label }}</span>
                                <span class="ml-auto text-xs text-zinc-400 dark:text-zinc-600 font-semibold">{{ node.children.length }}</span>
                            </button>
                            <div class="grid motion-safe:transition-[grid-template-rows] motion-safe:duration-200 motion-safe:ease-out" :style="{ gridTemplateRows: isGroupOpen(section, node.label, node.children) ? '1fr' : '0fr' }" :inert="!isGroupOpen(section, node.label, node.children)">
                            <ul class="mt-0.5 ml-4 pl-3 border-l border-zinc-200 dark:border-zinc-800 space-y-0.5 overflow-hidden">
                                <li v-for="child in node.children" :key="child.id">
                                    <a
                                        href="#"
                                        @click.prevent="select('component', child.id)"
                                        class="block px-3 py-1.5 text-[13px] rounded-lg transition-colors"
                                        :class="isActive('component', child.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                                    >
                                        <span>{{ child.leaf }}</span>
                                    </a>
                                </li>
                            </ul>
                            </div>
                        </div>
                    </li>
                </ul>
                </div>
            </div>
            </template>

            <!-- Pages -->
            <div v-show="pageItems.length > 0">
                <button @click="toggleSection('pages')" :aria-expanded="(sections.pages || !!ui.searchQuery) ? 'true' : 'false'" class="w-full flex justify-between items-center px-3 py-1.5 text-[10px] uppercase tracking-wider font-bold text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    <span>{{ i18n.t('sections.pages') }}</span>
                    <span class="text-zinc-400 dark:text-zinc-600 font-semibold">{{ pageItems.length }}</span>
                </button>
                <div class="grid motion-safe:transition-[grid-template-rows] motion-safe:duration-200 motion-safe:ease-out" :style="{ gridTemplateRows: (sections.pages || ui.searchQuery) ? '1fr' : '0fr' }" :inert="!(sections.pages || ui.searchQuery)">
                <ul class="mt-1 space-y-0.5 overflow-hidden">
                    <!-- While searching: flat full-name results (grouping bypassed). -->
                    <li v-for="page in (ui.searchQuery ? pageItems : [])" :key="'s:' + page.id">
                        <a
                            href="#"
                            @click.prevent="select('page', page.id)"
                            class="block px-3.5 py-2 text-sm rounded-lg transition-colors"
                            :class="isActive('page', page.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                        >
                            <span>{{ page.name ?? page.id }}</span>
                        </a>
                    </li>
                    <!-- Otherwise: prefix tree (groups >= 3 by name, suffix-only children) — same as component sections. -->
                    <li v-for="node in (ui.searchQuery ? [] : catalog.pagesTree)" :key="node.type === 'group' ? 'g:' + node.label : 'i:' + node.item.id">
                        <a
                            v-if="node.type === 'item'"
                            href="#"
                            @click.prevent="select('page', node.item.id)"
                            class="block px-3.5 py-2 text-sm rounded-lg transition-colors"
                            :class="isActive('page', node.item.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                        >
                            <span>{{ node.item.name ?? node.item.id }}</span>
                        </a>
                        <!-- Group row: no chevron, same rationale as the component
                             prefix tree above -- count badge alone signals a
                             group, flush left padding matches sibling items. -->
                        <div v-else>
                            <button @click="toggleGroup('pages', node.label)" :aria-expanded="isGroupOpen('pages', node.label, node.children) ? 'true' : 'false'" class="w-full flex items-center px-3.5 py-2 text-sm rounded-lg text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white transition-colors">
                                <span class="font-medium">{{ node.label }}</span>
                                <span class="ml-auto text-xs text-zinc-400 dark:text-zinc-600 font-semibold">{{ node.children.length }}</span>
                            </button>
                            <div class="grid motion-safe:transition-[grid-template-rows] motion-safe:duration-200 motion-safe:ease-out" :style="{ gridTemplateRows: isGroupOpen('pages', node.label, node.children) ? '1fr' : '0fr' }" :inert="!isGroupOpen('pages', node.label, node.children)">
                            <ul class="mt-0.5 ml-4 pl-3 border-l border-zinc-200 dark:border-zinc-800 space-y-0.5 overflow-hidden">
                                <li v-for="child in node.children" :key="child.id">
                                    <a
                                        href="#"
                                        @click.prevent="select('page', child.id)"
                                        class="block px-3 py-1.5 text-[13px] rounded-lg transition-colors"
                                        :class="isActive('page', child.id) ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white'"
                                    >
                                        <span>{{ child.leaf }}</span>
                                    </a>
                                </li>
                            </ul>
                            </div>
                        </div>
                    </li>
                </ul>
                </div>
            </div>
        </nav>

        <!-- Sidebar footer: Porta credit (left) + language switcher (right). Same baseline,
             single row, so locale switching never shifts vertical layout. -->
        <div class="border-t border-zinc-200 dark:border-zinc-800 px-4 py-2 flex justify-between items-center gap-3 text-xs">
            <a
                href="https://portadesign.cz/?utm_source=parisek-styleguide&utm_medium=sidebar&utm_campaign=made-by"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-1.5 text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors"
                title="Made by Porta Design"
            >
                <!-- Inline PORTA wordmark — vendored from tailwind-base/static/images/icons/ico-porta.svg
                     so the chrome carries the maker brand without depending on consumer assets. -->
                <svg aria-label="Porta Design" role="img" class="h-3 w-auto" viewBox="0.143 0.667 39.714 8.665" xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor" d="M4.773,3.828c0,0.708-0.431,1.063-1.294,1.063H2.406V2.731h1.062c0.871,0,1.306,0.358,1.306,1.073V3.828z M6.116,1.589 C5.509,1.082,4.684,0.83,3.643,0.83h-3.5v8.166h2.263V6.663h1.12c1.051,0,1.89-0.249,2.52-0.748 c0.661-0.527,0.993-1.259,0.993-2.191V3.7C7.038,2.806,6.73,2.103,6.116,1.589 M14.01,4.937c0,0.606-0.19,1.116-0.572,1.528 c-0.396,0.435-0.898,0.654-1.504,0.654c-0.607,0-1.108-0.224-1.505-0.666c-0.389-0.42-0.583-0.934-0.583-1.54V4.891 c0-0.607,0.19-1.117,0.57-1.528c0.389-0.437,0.887-0.655,1.493-0.655c0.608,0,1.113,0.223,1.518,0.667 c0.389,0.419,0.583,0.933,0.583,1.539V4.937z M15.071,1.904c-0.84-0.824-1.886-1.237-3.137-1.237c-1.253,0-2.303,0.415-3.15,1.248 c-0.841,0.816-1.26,1.815-1.26,2.998v0.023c0,1.183,0.417,2.177,1.248,2.986c0.841,0.824,1.887,1.236,3.138,1.236 c1.253,0,2.303-0.416,3.15-1.248c0.839-0.816,1.26-1.815,1.26-2.998V4.891C16.32,3.708,15.903,2.712,15.071,1.904 M31.796,0.83 h-7.163v1.983h2.449v6.183h2.264V2.813h2.45V0.83z M34.352,5.777l0.923-2.323l0.908,2.323H34.352z M36.382,0.771h-2.183 l-3.476,8.225h2.379l0.584-1.459h3.15l0.595,1.459h2.426L36.382,0.771z M19.266,2.791h1.483c0.816,0,1.225,0.322,1.225,0.967v0.023 c0,0.646-0.405,0.969-1.214,0.969h-1.494V2.791z M22.854,6.152l-0.075-0.101c0.979-0.487,1.469-1.291,1.469-2.411V3.618 c0-0.801-0.24-1.436-0.723-1.901c-0.576-0.591-1.462-0.887-2.66-0.887h-3.861v8.166h2.262V6.522h1.176l0.083,0.122 c0.874,1.285,1.782,2.38,3.649,2.688l0.72-1.709C23.874,7.233,23.319,6.784,22.854,6.152"/>
                </svg>
                <!-- Heart icon — pulses via .sg-heartbeat keyframe (styleguide.css). Solid fill,
                     text-red-500 so dark-mode contrast stays high. aria-hidden because the
                     parent <a> already carries the human-readable label. -->
                <svg aria-hidden="true" class="sg-heartbeat h-3 w-3 text-red-500" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 21s-7.2-4.35-9.6-9.6C.93 7.95 3.45 4.5 7.05 4.5c1.95 0 3.6.9 4.95 2.4 1.35-1.5 3-2.4 4.95-2.4 3.6 0 6.12 3.45 4.65 6.9C19.2 16.65 12 21 12 21z"/>
                </svg>
            </a>
            <div class="flex gap-1 font-mono">
                <span v-for="(loc, idx) in supportedLocales()" :key="loc">
                    <span v-show="idx > 0" class="text-zinc-400 dark:text-zinc-600">·</span>
                    <button
                        @click="i18n.load(loc); setContentLocale(loc)"
                        :class="i18n.locale === loc ? 'text-zinc-900 dark:text-zinc-50 font-semibold' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    >{{ loc }}</button>
                </span>
            </div>
        </div>
    </aside>
</template>
