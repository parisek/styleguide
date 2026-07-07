<script setup>
import { inject, ref, onMounted, onUnmounted } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';

const i18n = useI18nStore();
const ui = useUiStore();
const viewport = inject('viewport');

const dropdownOpen = ref(false);
const overflowOpen = ref(false);
const columnsOpen = ref(false);
const dropdownRef = ref(null);
const overflowRef = ref(null);
const columnsRef = ref(null);

const CATEGORY_ICON_PATHS = {
    mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    tablet: '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    desktop: '<rect x="2" y="4" width="20" height="13" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    full: '<polyline points="4 8 4 4 8 4"/><polyline points="20 8 20 4 16 4"/><polyline points="4 16 4 20 8 20"/><polyline points="20 16 20 20 16 20"/>',
};

// Tile density trigger label -- "Auto" or the full "N sloupec/sloupce" /
// "N column(s)" string (the same i18n key VariantGrid.vue's own basis
// comment refers to), matching the viewport trigger's "word + dims" shape
// so the two dropdowns read as one family at a glance.
function columnsLabel() {
    if (ui.variantColumns === 'auto') return i18n.t('toolbar.variant_columns_auto_label');
    return i18n.t(`toolbar.variant_columns_${ui.variantColumns}`);
}

function activeWordLabel() {
    const key = viewport.activePreset.value;
    if (key === 'full') return 'Full';
    if (key === 'custom') return i18n.t('toolbar.custom_width_label');
    return viewport.VIEWPORTS.find((v) => v.key === key)?.label ?? '?';
}

function triggerDims() {
    if (viewport.activePreset.value === 'full') return '100 %';
    if (viewport.activePreset.value === 'custom') {
        // effectiveZoom (not the classic single preview's own zoom)
        // -- while the variant grid is active this is the shared per-tile
        // zoom VariantGrid.vue reports, so a Custom width applied in grid
        // mode shows its real common scale here too.
        const z = viewport.effectiveZoom.value;
        const w = viewport.customWidthInput.value || 0;
        return z < 1 ? `${w} px · ${Math.round(z * 100)} %` : `${w} px`;
    }
    return viewport.dimensionsLabel.value;
}

function openInNewTabHref() {
    return viewport.iframeSrc.value;
}

function canvasHref() {
    const src = viewport.iframeSrc.value;
    return src ? src + (src.includes('?') ? '&' : '?') + 'canvas=1' : null;
}

// Plain navigation (not a router push) matches the legacy canvas-mode click
// handler — canvas mode renders a real server document, not an SPA route.
function goCanvasMode() {
    const href = canvasHref();
    if (href) window.location.href = href;
}

// Iframe content theme — independent of the SPA chrome's own theme toggle
// (see stores/theme.js). Flips ui.iframeTheme, which useViewportPreset's
// iframeSrc computed reads to append `?theme=dark` to the render URL.
function toggleIframeTheme() {
    ui.setIframeTheme(ui.iframeTheme === 'dark' ? 'light' : 'dark');
}

// Vue has no built-in @click.outside directive (unlike Alpine's). Both
// popovers close on any click landing outside their own DOM subtree,
// checked via a single document-level listener — only one popover is ever
// open at a time in practice, so one listener covers both.
function onDocumentClick(event) {
    if (dropdownOpen.value && dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        dropdownOpen.value = false;
    }
    if (overflowOpen.value && overflowRef.value && !overflowRef.value.contains(event.target)) {
        overflowOpen.value = false;
    }
    if (columnsOpen.value && columnsRef.value && !columnsRef.value.contains(event.target)) {
        columnsOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onUnmounted(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <!-- Toolbar. `shrink-0` on each direct child keeps individual buttons
         from being squeezed unreadable. No horizontal overflow — the
         viewport dropdown below xl keeps the row narrow enough to fit
         even on ~720px screens, so the dropdown popover is free to
         break out of the toolbar's flow without getting clipped. -->
    <div class="flex justify-between items-center gap-3 px-4 py-2.5 bg-zinc-50 border-b border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800">
        <div class="flex items-center gap-3 min-w-0">
            <!-- Hamburger <-> close morph: three independent bars (not a single
                 SVG path) so each can be transformed on its own axis -- the
                 top/bottom bars rotate 45deg/-45deg into an X while the
                 middle one fades out. Pure CSS transforms (.sg-hbg-bar*
                 rules, styleguide.css), gated behind
                 prefers-reduced-motion:no-preference so a reduced-motion
                 user gets an instant state swap instead of the ~200ms morph. -->
            <button @click="ui.toggleSidebar()" :aria-expanded="ui.sidebarOpen ? 'true' : 'false'" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 shrink-0" :title="i18n.t('toolbar.toggle_sidebar')" :aria-label="i18n.t('toolbar.toggle_sidebar')">
                <span class="sg-hbg" :class="ui.sidebarOpen && 'sg-hbg-open'" aria-hidden="true">
                    <span class="sg-hbg-bar sg-hbg-bar-top"></span>
                    <span class="sg-hbg-bar sg-hbg-bar-mid"></span>
                    <span class="sg-hbg-bar sg-hbg-bar-bottom"></span>
                </span>
            </button>
            <template v-if="viewport.type.value === 'overview'">
                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ i18n.t('nav.overview') }}</span>
            </template>
            <template v-if="viewport.type.value === 'foundations'">
                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ i18n.t('nav.foundations') }}</span>
            </template>
            <template v-if="viewport.slug.value">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-[10px] uppercase tracking-wider font-bold bg-red-600/10 text-red-700 dark:bg-red-400/15 dark:text-red-400 px-2 py-0.5 rounded-md shrink-0">{{ viewport.type.value === 'page' ? i18n.t('toolbar.type_page') : (viewport.type.value === 'doc' ? i18n.t('nav.docs') : i18n.t('toolbar.type_component')) }}</span>
                    <!-- Breadcrumb: Section / Component name (slug) [/ Variant
                         label]. The section segment hides while the components
                         API is still loading so the toolbar doesn't flash a
                         translated label like `(undefined)` before data
                         arrives. The trailing Variant segment (styleguide 2.0
                         redesign, replaces the earlier "← All" toolbar back
                         control) only appears once a deep-linked `?variant=`
                         has isolated the classic single preview -- and turns
                         the component-name crumb itself into a clickable/
                         keyboard breadcrumb link back to the grid, standard
                         breadcrumb semantics (there's nothing to "go back to"
                         when no variant is isolated, so it stays a plain,
                         non-interactive label in that case). -->
                    <nav class="flex items-center gap-1.5 min-w-0 text-sm" aria-label="Breadcrumb">
                        <template v-if="viewport.currentSectionKey.value">
                            <span class="text-zinc-500 shrink-0">{{ i18n.t(`sections.${viewport.currentSectionKey.value}`) }}</span>
                            <span class="text-zinc-400 dark:text-zinc-600 shrink-0">/</span>
                        </template>
                        <button v-if="viewport.variant.value" type="button"
                                data-testid="breadcrumb-item-name"
                                @click="viewport.setVariant(null)"
                                :title="i18n.t('toolbar.breadcrumb_back_to_grid')"
                                :aria-label="`${i18n.t('toolbar.breadcrumb_back_to_grid')}: ${viewport.currentItemName.value}`"
                                class="font-semibold text-zinc-900 dark:text-zinc-100 truncate rounded-sm transition-colors hover:text-red-600 hover:underline dark:hover:text-red-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">{{ viewport.currentItemName.value }}</button>
                        <span v-else data-testid="breadcrumb-item-name" class="font-semibold text-zinc-900 dark:text-zinc-100 truncate">{{ viewport.currentItemName.value }}</span>
                        <span class="text-zinc-500 font-mono text-xs shrink-0">({{ viewport.slug.value }})</span>
                        <template v-if="viewport.variant.value">
                            <span class="text-zinc-400 dark:text-zinc-600 shrink-0">/</span>
                            <span data-testid="breadcrumb-variant" class="text-zinc-500 dark:text-zinc-400 truncate">{{ viewport.currentVariantLabel.value }}</span>
                        </template>
                    </nav>
                </div>
            </template>
            <template v-if="!viewport.slug.value && viewport.type.value !== 'foundations' && viewport.type.value !== 'overview'">
                <span class="text-zinc-500 text-sm">{{ i18n.t('toolbar.select_prompt') }}</span>
            </template>
        </div>

        <!-- Right-side controls cluster. The toolbar pill variant switcher
             that used to live here (commit dc4715a) is GONE per the
             styleguide-2.0 redesign brief -- variants are now a full-canvas
             grid of independent preview tiles (VariantGrid.vue, rendered by
             PreviewPane.vue whenever viewport.gridActive is true). Its
             toolbar affordance is the density dropdown just below (grid mode
             only) -- the responsive-width preset dropdown stays
             visible too and applies the same shared preset to every tile. -->
        <template v-if="viewport.previewActionsVisible.value">
            <div class="flex items-center gap-2 shrink-0">
                <!-- Tile density -- VariantGrid.vue-only control, so it only
                     makes sense (and only renders) while the grid itself is
                     active. Persisted via ui.variantColumns. "Auto" derives
                     column sizing from the active viewport preset (a Desktop
                     preset packs fewer tiles per row than Mobile on the same
                     canvas -- see lib/tileGeometry.js's
                     autoGridColumnBasis()); 1-4 fixes the column count
                     exactly, ignoring the preset. Replaces the earlier
                     rows/grid toggle -- "1" is the exact visual replacement
                     for the old "rows" stacked layout.
                     Styleguide 2.0 UX fix: the five options used to be a
                     segmented pill row, which read as visual clutter next to
                     the device-preset dropdown right beside it. Rebuilt as a
                     second rounded-full trigger sharing the SAME
                     pill/icon/label/chevron shape and open/close/click-outside
                     mechanics as the viewport dropdown below (columnsOpen/
                     columnsRef mirror dropdownOpen/dropdownRef exactly) --
                     the two now read as one family of controls instead of
                     two different widget styles bolted together. -->
                <template v-if="viewport.gridActive.value">
                    <div class="relative" ref="columnsRef" @keydown.escape="columnsOpen = false">
                        <button type="button"
                                data-testid="variant-columns-trigger"
                                @click="columnsOpen = !columnsOpen"
                                :aria-expanded="columnsOpen"
                                :title="i18n.t('toolbar.variant_columns_label')"
                                :aria-label="i18n.t('toolbar.variant_columns_label')"
                                class="flex items-center gap-2 h-9 pl-3 pr-2.5 rounded-full border text-xs font-medium tabular-nums transition-colors"
                                :class="columnsOpen ? 'bg-zinc-200 border-zinc-300 text-zinc-900 dark:bg-zinc-700 dark:border-zinc-600 dark:text-zinc-100' : 'bg-zinc-100 border-zinc-200 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-700'">
                            <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="5" height="16" rx="1"/>
                                <rect x="9.5" y="4" width="5" height="16" rx="1"/>
                                <rect x="16" y="4" width="5" height="16" rx="1"/>
                            </svg>
                            <span class="font-semibold">{{ columnsLabel() }}</span>
                            <svg aria-hidden="true" focusable="false" class="w-3 h-3 shrink-0 transition-transform text-zinc-400 dark:text-zinc-500" :class="columnsOpen && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        <div v-show="columnsOpen"
                             class="absolute right-0 top-full mt-2 z-50 min-w-[200px] rounded-xl shadow-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-1.5">
                            <button type="button"
                                    data-testid="variant-columns-auto"
                                    @click="ui.setVariantColumns('auto'); columnsOpen = false"
                                    :title="i18n.t('toolbar.variant_columns_auto')"
                                    class="w-full px-3 py-2 flex items-center gap-2.5 text-xs tabular-nums rounded-lg transition-colors"
                                    :class="ui.variantColumns === 'auto' ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700'">
                                <span class="font-medium">{{ i18n.t('toolbar.variant_columns_auto_label') }}</span>
                            </button>
                            <button v-for="n in [1, 2, 3, 4]" :key="n" type="button"
                                    :data-testid="`variant-columns-${n}`"
                                    @click="ui.setVariantColumns(n); columnsOpen = false"
                                    class="w-full px-3 py-2 flex items-center gap-2.5 text-xs tabular-nums rounded-lg transition-colors"
                                    :class="ui.variantColumns === n ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700'">
                                <span class="font-medium">{{ i18n.t(`toolbar.variant_columns_${n}`) }}</span>
                            </button>
                        </div>
                    </div>
                </template>
                <!-- Responsive-width preset dropdown + custom width +
                     orientation toggle. Stays visible AND functional in grid
                     mode -- the same shared preset now applies uniformly to
                     every tile, scaled down per tile to fit (VariantGrid.vue
                     + lib/tileGeometry.js). Only PreviewPane.vue's drag
                     handles/chassis decorations remain single-preview-only
                     (they simply aren't rendered while the grid is active),
                     and only on the foundations route / responsive:false
                     entries does this whole block still disappear. -->
                <template v-if="viewport.toolbarVisible.value">
                <!-- Unified viewport switcher — one labelled dropdown at every width
                     (replaces the old xl segmented bar + separate mobile menu). The
                     trigger always shows the device word + dimensions, so the control
                     reads identically on desktop and mobile. -->
                <div class="relative" ref="dropdownRef" @keydown.escape="dropdownOpen = false">
                    <button type="button"
                            data-testid="viewport-trigger"
                            @click="dropdownOpen = !dropdownOpen"
                            :aria-expanded="dropdownOpen"
                            :title="i18n.t('toolbar.viewport_preset')"
                            class="flex items-center gap-2 h-9 pl-3 pr-2.5 rounded-full border text-xs font-medium tabular-nums transition-colors"
                            :class="dropdownOpen ? 'bg-zinc-200 border-zinc-300 text-zinc-900 dark:bg-zinc-700 dark:border-zinc-600 dark:text-zinc-100' : 'bg-zinc-100 border-zinc-200 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-700'">
                        <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="CATEGORY_ICON_PATHS[viewport.activePresetCategory.value] ?? CATEGORY_ICON_PATHS.desktop"></svg>
                        <span class="font-semibold">{{ activeWordLabel() }}</span>
                        <span class="hidden sm:inline text-zinc-500 dark:text-zinc-400">{{ triggerDims() }}</span>
                        <svg aria-hidden="true" focusable="false" class="w-3 h-3 shrink-0 transition-transform text-zinc-400 dark:text-zinc-500" :class="dropdownOpen && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                    <div v-show="dropdownOpen"
                         class="absolute right-0 top-full mt-2 z-50 min-w-[260px] rounded-xl shadow-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-1.5">

                        <!-- Presets. Tap any row = set width + height. Active = red pill. -->
                        <button v-for="vp in viewport.VIEWPORTS" :key="vp.key"
                                :data-testid="`viewport-preset-${vp.key}`"
                                @click="viewport.setPreset(vp.key); dropdownOpen = false"
                                class="w-full px-3 py-2 flex items-center gap-2.5 text-xs tabular-nums rounded-lg transition-colors"
                                :class="viewport.activePreset.value === vp.key ? 'bg-red-600/10 text-red-700 font-semibold dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700'">
                            <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="CATEGORY_ICON_PATHS[vp.category]"></svg>
                            <span class="font-medium">{{ vp.label }}</span>
                            <span class="ml-auto opacity-70">{{ vp.width ? `${vp.width} × ${vp.height}` : '100%' }}</span>
                        </button>

                        <!-- Custom width input. -->
                        <div class="px-3 py-2 mt-1 border-t border-zinc-200 dark:border-zinc-700 flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400 font-semibold">{{ i18n.t('toolbar.custom_width_label') }}</span>
                            <input type="number"
                                   v-model.number="viewport.customWidthInput.value"
                                   @keydown.enter.prevent="viewport.applyCustomWidth(); $event.target.blur()"
                                   @blur="viewport.applyCustomWidth()"
                                   :min="viewport.CUSTOM_WIDTH_MIN" :max="viewport.CUSTOM_WIDTH_MAX"
                                   :placeholder="i18n.t('toolbar.custom_width_placeholder')"
                                   :aria-label="i18n.t('toolbar.custom_width')"
                                   class="ml-auto w-20 px-2 h-7 text-xs font-mono tabular-nums rounded-lg focus:outline-none focus:ring-1 focus:ring-zinc-500 dark:focus:ring-zinc-400 transition-colors"
                                   :class="viewport.activePreset.value === 'custom' ? 'bg-red-600 text-white placeholder-red-200 dark:bg-red-500 dark:text-white dark:placeholder-red-200' : 'bg-zinc-100 text-zinc-700 placeholder-zinc-500 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200 dark:placeholder-zinc-500 dark:hover:bg-zinc-600'">
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">px</span>
                        </div>

                        <!-- Orientation switch. Disabled when no device preset is active. -->
                        <div class="px-3 py-2 border-t border-zinc-200 dark:border-zinc-700 flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400 font-semibold">{{ i18n.t('toolbar.orientation_label') }}</span>
                            <div role="group" :aria-label="i18n.t('toolbar.rotate')"
                                 class="ml-auto inline-flex gap-px rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-700"
                                 :class="ui.previewHeight === null && 'opacity-30 pointer-events-none'">
                                <button type="button"
                                        @click="viewport.setPortrait(true)"
                                        :title="i18n.t('toolbar.orientation_portrait')"
                                        :aria-label="i18n.t('toolbar.orientation_portrait')"
                                        :aria-pressed="viewport.isPortrait.value ? 'true' : 'false'"
                                        class="h-7 w-7 flex items-center justify-center transition-colors"
                                        :class="viewport.isPortrait.value ? 'bg-red-600 text-white dark:bg-red-500 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-600'">
                                    <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="7" y="3" width="10" height="18" rx="2"/>
                                    </svg>
                                </button>
                                <button type="button"
                                        @click="viewport.setPortrait(false)"
                                        :title="i18n.t('toolbar.orientation_landscape')"
                                        :aria-label="i18n.t('toolbar.orientation_landscape')"
                                        :aria-pressed="!viewport.isPortrait.value ? 'true' : 'false'"
                                        class="h-7 w-7 flex items-center justify-center transition-colors"
                                        :class="!viewport.isPortrait.value ? 'bg-red-600 text-white dark:bg-red-500 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-600'">
                                    <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="7" width="18" height="10" rx="2"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
                <!-- Secondary preview actions: inline on lg+, collapsed into a ⋮
                     overflow below lg so the toolbar doesn't crowd on tablet / phone.
                     These stay available in grid mode too (they act on the grid's
                     default tile / the whole preview area). -->
                <div class="hidden lg:flex items-center gap-2">
                    <button type="button"
                            data-testid="iframe-theme-toggle"
                            @click="toggleIframeTheme()"
                            :aria-pressed="ui.iframeTheme === 'dark' ? 'true' : 'false'"
                            :title="i18n.t('toolbar.iframe_theme')"
                            :aria-label="i18n.t('toolbar.iframe_theme')"
                            class="h-9 w-9 flex items-center justify-center rounded-lg text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg v-if="ui.iframeTheme === 'dark'" aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>
                        </svg>
                        <svg v-else aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4"/>
                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                        </svg>
                    </button>
                    <button type="button"
                            @click="goCanvasMode()"
                            :disabled="!viewport.slug.value"
                            :title="i18n.t('toolbar.canvas_mode')"
                            :aria-label="i18n.t('toolbar.canvas_mode_label')"
                            class="h-9 w-9 flex items-center justify-center rounded-lg text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/>
                            <path d="M9 21h6"/>
                        </svg>
                    </button>
                    <a :href="openInNewTabHref()" target="_blank" rel="noopener"
                       :title="i18n.t('toolbar.open_in_new_tab')"
                       :aria-label="i18n.t('toolbar.open_in_new_tab')"
                       class="h-9 w-9 flex items-center justify-center rounded-lg text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 4h6v6M20 4l-9 9M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>
                        </svg>
                    </a>
                    <button type="button" @click="viewport.reloadPreview()"
                            :title="i18n.t('toolbar.reload')"
                            :aria-label="i18n.t('toolbar.reload')"
                            class="h-9 w-9 flex items-center justify-center rounded-lg text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                    </button>
                </div>
                <!-- Overflow (⋮) — same actions, below lg only. -->
                <div class="lg:hidden relative" ref="overflowRef" @keydown.escape="overflowOpen = false">
                    <button type="button"
                            @click="overflowOpen = !overflowOpen"
                            :aria-expanded="overflowOpen"
                            :title="i18n.t('toolbar.more_actions')"
                            :aria-label="i18n.t('toolbar.more_actions')"
                            class="h-9 w-9 flex items-center justify-center rounded-lg transition-colors"
                            :class="overflowOpen ? 'bg-zinc-200 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700'">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="5" r="1.5"/>
                            <circle cx="12" cy="12" r="1.5"/>
                            <circle cx="12" cy="19" r="1.5"/>
                        </svg>
                    </button>
                    <div v-show="overflowOpen"
                         class="absolute right-0 top-full mt-2 z-50 min-w-[200px] rounded-xl shadow-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-1.5">
                        <button type="button"
                                data-testid="iframe-theme-toggle-overflow"
                                @click="overflowOpen = false; toggleIframeTheme()"
                                :aria-pressed="ui.iframeTheme === 'dark' ? 'true' : 'false'"
                                class="w-full px-3 py-2 flex items-center gap-2.5 text-xs rounded-lg text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <svg v-if="ui.iframeTheme === 'dark'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>
                            </svg>
                            <svg v-else aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="4"/>
                                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                            </svg>
                            <span>{{ i18n.t('toolbar.iframe_theme') }}</span>
                        </button>
                        <button type="button"
                                @click="overflowOpen = false; goCanvasMode()"
                                :disabled="!viewport.slug.value"
                                class="w-full px-3 py-2 flex items-center gap-2.5 text-xs rounded-lg text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                            <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/>
                                <path d="M9 21h6"/>
                            </svg>
                            <span>{{ i18n.t('toolbar.canvas_mode_label') }}</span>
                        </button>
                        <a :href="openInNewTabHref()" target="_blank" rel="noopener" @click="overflowOpen = false"
                           class="w-full px-3 py-2 flex items-center gap-2.5 text-xs rounded-lg text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 4h6v6M20 4l-9 9M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>
                            </svg>
                            <span>{{ i18n.t('toolbar.open_in_new_tab') }}</span>
                        </a>
                        <button type="button" @click="overflowOpen = false; viewport.reloadPreview()"
                                class="w-full px-3 py-2 flex items-center gap-2.5 text-xs rounded-lg text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <svg aria-hidden="true" focusable="false" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                <path d="M3 3v5h5"/>
                            </svg>
                            <span>{{ i18n.t('toolbar.reload') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
        <!-- Foundations route still gets the open-in-new-tab affordance,
             just without the viewport controls. -->
        <template v-if="viewport.iframeSrc.value && viewport.type.value === 'foundations'">
            <div class="flex items-center gap-1 shrink-0">
                <button type="button"
                        data-testid="iframe-theme-toggle"
                        @click="toggleIframeTheme()"
                        :aria-pressed="ui.iframeTheme === 'dark' ? 'true' : 'false'"
                        :title="i18n.t('toolbar.iframe_theme')"
                        :aria-label="i18n.t('toolbar.iframe_theme')"
                        class="h-7 w-7 flex items-center justify-center rounded text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                    <svg v-if="ui.iframeTheme === 'dark'" aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z"/>
                    </svg>
                    <svg v-else aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                    </svg>
                </button>
                <a :href="openInNewTabHref()" target="_blank" rel="noopener"
                   :title="i18n.t('toolbar.open_in_new_tab')"
                   :aria-label="i18n.t('toolbar.open_in_new_tab')"
                   class="h-7 w-7 flex items-center justify-center rounded text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                    <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 4h6v6M20 4l-9 9M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>
                    </svg>
                </a>
                <button type="button" @click="viewport.reloadPreview()"
                        :title="i18n.t('toolbar.reload')"
                        :aria-label="i18n.t('toolbar.reload')"
                        class="h-7 w-7 flex items-center justify-center rounded text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                    <svg aria-hidden="true" focusable="false" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                    </svg>
                </button>
            </div>
        </template>
    </div>
</template>
