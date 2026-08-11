<script setup>
import { computed, provide } from 'vue';
import { useRoute } from 'vue-router';
import { useUiStore } from './stores/ui.js';
import { useCatalogStore } from './stores/catalog.js';
import { routeInfo } from './lib/routeInfo.js';
import { useViewportPreset } from './composables/useViewportPreset.js';
import { useVariant } from './composables/useVariant.js';
import { useContentLocale } from './composables/useContentLocale.js';
import Sidebar from './components/Sidebar.vue';
import ViewportToolbar from './components/ViewportToolbar.vue';
import FieldsDrawer from './components/FieldsDrawer.vue';
import UsagePanel from './components/UsagePanel.vue';
import LinkBar from './components/LinkBar.vue';
import SearchPalette from './components/SearchPalette.vue';

const ui = useUiStore();
const catalog = useCatalogStore();
const route = useRoute();

const routeType = computed(() => routeInfo(route).type);
const routeSlug = computed(() => routeInfo(route).slug);
// Computed independently from useViewportPreset()'s own internal
// currentItem lookup (same catalog.find(type, slug) call, cheap and pure) —
// useVariant() needs the entry's discovered `variants` list *before*
// useViewportPreset() exists, purely to validate/whitelist an incoming
// `?variant=` query id (Task 1: ComponentParser.discoverVariants()).
const currentEntry = computed(() => (routeSlug.value ? catalog.find(routeType.value, routeSlug.value) : null));
// useVariant() calls useRoute()/useRouter() internally, which only work
// inside a mounted component's setup() — App.vue is that component, so the
// resulting refs are threaded into useViewportPreset() as plain params
// (mirrors how type/slug are already passed in rather than sourced
// internally), keeping useViewportPreset.spec.js's router-free construction
// working unchanged.
const { variant, setVariant } = useVariant(currentEntry);
// Same useRoute()-inside-setup() constraint as useVariant() above — computed
// here and threaded into useViewportPreset() as a plain ref, not called
// from inside it, so useViewportPreset.spec.js's router-free construction
// keeps working. `setContentLocale` is exposed via `viewport` below (not
// consumed by useViewportPreset() itself) so Sidebar.vue's switcher can call
// it without needing its own useRoute() access.
const { contentLocale, setContentLocale } = useContentLocale();
// Provided one level above <RouterView/>, not inside PreviewView.vue — the
// legacy DOM's toolbar/description/usage/link/fields chrome are siblings of
// the route-specific body inside the SAME `x-data="preview"` scope, not
// nested inside it, so they render identically on every route (including
// /overview and /foundations). See Task 7 brief Step 9 for the full
// rationale. PreviewPane.vue/FieldsDrawer.vue/UsagePanel.vue/LinkBar.vue
// (Tasks 8-10) inject this same instance through <RouterView/>.
const viewport = useViewportPreset({
    type: routeType, slug: routeSlug, variant, setVariant, contentLocale,
});
provide('viewport', { ...viewport, setContentLocale });
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <!-- Backdrop: always mounted (not v-show) below lg so opacity can
             transition instead of snapping between display:none/block --
             pointer-events-none while hidden keeps it inert exactly like the
             old display:none did. motion-safe: gates the fade to users who
             haven't asked for reduced motion; everyone else gets an instant
             show/hide via the same class toggle. -->
        <div
            class="fixed inset-0 z-40 bg-black/40 lg:hidden motion-safe:transition-opacity motion-safe:duration-200"
            :class="ui.sidebarOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'"
            @click="ui.toggleSidebar()"
        ></div>

        <Sidebar />

        <!-- Global command palette (⌘K/Ctrl+K). Mounted once at this level
             (not inside Sidebar) since it's an app-wide overlay, not sidebar
             chrome -- same reasoning as FieldsDrawer/UsagePanel/LinkBar
             sitting here rather than nested inside a specific view. -->
        <SearchPalette />

        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950" :class="viewport.isDragging.value && 'cursor-ew-resize select-none'">
            <ViewportToolbar />
            <!-- Description bar — surfaces the component / page `description`
                 from its YAML metadata between the toolbar and the usage
                 panel. Renders via v-html so authors can embed real anchor
                 tags — content originates in dev-authored .twig YAML
                 headers, not user input, matching the rest of the
                 styleguide chrome's trust model.

                 Once a `?variant=` isolates the classic single preview,
                 `descriptionBarText` (useViewportPreset.js) REPLACES the
                 component's general description with the isolated variant's
                 own one -- deliberate, not additive: the variant's
                 description is strictly more specific, so showing both
                 would be redundant at best. A small uppercase label (same
                 treatment as VariantGrid.vue's own tile-header labels)
                 identifies which variant the description belongs to; it's
                 gated on the SAME v-if as the description itself, so it
                 never appears with nothing underneath it. -->
            <div v-if="viewport.descriptionBarText.value && routeSlug" class="sg-description-bar px-4 py-2 bg-zinc-100/60 border-b border-zinc-200 dark:bg-zinc-900/40 dark:border-zinc-800 text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                <div v-if="viewport.variant.value" data-testid="description-bar-variant-label" class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-0.5">{{ viewport.currentVariantLabel.value }}</div>
                <span v-html="viewport.descriptionBarText.value"></span>
            </div>
            <UsagePanel />
            <LinkBar />
            <FieldsDrawer v-if="viewport.fieldsCount.value > 0 && routeSlug" :fields="viewport.currentItem.value?.fields" />
            <RouterView />
        </main>
    </div>
</template>
