<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, inject } from 'vue';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

const ui = useUiStore();
const i18n = useI18nStore();
const catalog = useCatalogStore();
const viewport = inject('viewport');

const paneRef = ref(null);
const wrapperRef = ref(null);
const iframeContentHeight = ref(null);
let contentRO = null;

onMounted(() => {
    viewport.observeContainer(paneRef.value);
});
onBeforeUnmount(() => {
    if (contentRO) contentRO.disconnect();
    // Explicit teardown of the container ResizeObserver observeContainer()
    // creates on mount -- without this it stays attached to a now-detached
    // paneRef node until the next component/page route re-calls
    // observeContainer() and disconnects it as a side effect (bounded, but
    // a real observer sits idle in between).
    viewport.observeContainer(null);
});
watch(wrapperRef, (el) => viewport.observeWrapper(el));

const isLoading = computed(() => ui.isPreviewLoading);

// Same-origin iframes let the parent read contentDocument directly. Measure
// scrollHeight (accounts for everything below the fold) and keep an
// observer so post-load DOM changes (image/font load, accordion expansion,
// JS-driven content swaps) feed back into the iframe's explicit height. A
// fresh observer is created on every load so navigating between components
// doesn't leak observers from the previous document.
function fitIframeToContent(iframe) {
    const doc = iframe?.contentDocument;
    if (!doc) return;
    const measure = () => {
        const h = Math.max(doc.documentElement?.scrollHeight ?? 0, doc.body?.scrollHeight ?? 0);
        if (h > 0) iframeContentHeight.value = h;
    };
    measure();
    if (contentRO) contentRO.disconnect();
    contentRO = new ResizeObserver(measure);
    if (doc.documentElement) contentRO.observe(doc.documentElement);
    if (doc.body) contentRO.observe(doc.body);
}

// The loading flag itself is set synchronously by setRoute() in the ui
// store (before the iframe src changes). Here we just clear it when the
// new document finishes parsing. Iframes fire `load` for every src change
// including the initial bind, so this reliably matches a previous
// setRoute() -> isPreviewLoading = true.
function onIframeLoad(event) {
    ui.isPreviewLoading = false;
    const type = viewport.type.value;
    if (type === 'component' || type === 'page') {
        fitIframeToContent(event.target);
    } else {
        iframeContentHeight.value = null;
        if (contentRO) contentRO.disconnect();
    }
}

// CSS for the wrapper element. Every fixed-px width (preset or Custom) takes
// the *scaled* dimensions so the wrapper occupies the visible amount of
// space in flow -- drag handles + shadow + chassis ring all anchor against
// the visible (scaled) box, not the logical CSS-pixel size. Only Full mode
// (effective.width null) bypasses the scaling math.
const wrapperStyle = computed(() => {
    const { width: w, height: h } = viewport.effective.value;
    if (w === null) return 'width: 100%; height: 100%';
    const z = viewport.zoom.value;
    const sourceH = h ?? iframeContentHeight.value ?? 400;
    const scaledW = Math.max(1, Math.round(w * z));
    const scaledH = Math.max(1, Math.round(sourceH * z));
    return `width: ${scaledW}px; height: ${scaledH}px`;
});

// CSS for the iframe element. Logical preset dimensions in CSS px (so
// viewport units inside resolve against the device's "true" size) plus a
// uniform transform: scale() to fit visually. Wrapper gets the *scaled*
// dimensions so layout flow uses the visible size, not the logical one.
const iframeStyle = computed(() => {
    const { width: w } = viewport.effective.value;
    if (w === null) return 'width: 100%; height: 100%';
    const h = viewport.effective.value.height ?? iframeContentHeight.value ?? 400;
    const z = viewport.zoom.value;
    return `width: ${w}px; height: ${h}px; transform: scale(${z}); transform-origin: 0 0`;
});
</script>

<template>
    <!-- `items-stretch` in Full preset lets the wrapper's height: 100% resolve
         against this flex parent's intrinsic height (which itself fills the
         chrome's right pane via `flex-1`). For non-Full presets we use
         `items-center` so the scaled device sits visually centred in the
         canvas -- matches how a device lying on a desk reads, and uses the
         extra vertical room a wide preset (2K, etc.) leaves after
         fit-to-bounds zoom. -->
    <div ref="paneRef"
         class="flex-1 flex justify-center overflow-auto"
         :class="viewport.isFullPreset.value ? 'p-0 bg-white dark:bg-zinc-900 items-stretch' : 'p-6 bg-zinc-100 dark:bg-zinc-950 items-center'">
        <template v-if="viewport.iframeSrc.value">
            <!-- Outer positioning ancestor -- sized to the inner wrapper via
                 inline-block, so it inherits the scaled device dimensions.
                 Hosts the chassis decorations (speaker slot, home indicator)
                 and drag handles as absolute-positioned siblings of the
                 iframe-bearing wrapper. The iframe wrapper itself keeps
                 overflow-hidden (needed to clip iframe corners against the
                 rounded chassis); putting decorations here lets them sit on
                 the ring area without being clipped.

                 For preset/Custom modes the ancestor is `inline-block` so
                 chassis pills (speaker slot, camera dot, home indicator) can
                 sit on the ring as absolute-positioned siblings of the
                 wrapper without being clipped by the wrapper's
                 `overflow-hidden`. Full mode is the exception: the wrapper
                 wants `width: 100%; height: 100%` against the chrome pane,
                 but `inline-block` collapses to the iframe's intrinsic UA
                 width (~300px), so percentage widths resolved against a
                 300px ancestor -- not the chrome pane. For Full we promote
                 to a `block` element that stretches via the flex parent's
                 `items-stretch`. -->
            <div class="relative" :class="viewport.isFullPreset.value ? 'block w-full h-full' : 'inline-block'">
                <!-- Mobile chassis: speaker slot top + home indicator bottom.
                     Positioned to sit on the 10px ring (top/bottom -7px puts
                     the pill roughly centered on the chassis bezel). Colors
                     read against ring-zinc-800/ring-zinc-700 in both themes. -->
                <template v-if="viewport.activePresetCategory.value === 'mobile'">
                    <div data-testid="chassis-mobile" class="pointer-events-none">
                        <div class="absolute left-1/2 -translate-x-1/2 -top-[5px] h-1 w-12 rounded-full bg-zinc-600 dark:bg-zinc-500"></div>
                        <div class="absolute left-1/2 -translate-x-1/2 -bottom-[5px] h-1 w-20 rounded-full bg-zinc-500 dark:bg-zinc-400"></div>
                    </div>
                </template>
                <!-- Tablet chassis: lone camera dot. The 6px ring is too thin
                     for a speaker pill -- a centered dot reads as "tablet". -->
                <template v-if="viewport.activePresetCategory.value === 'tablet'">
                    <div data-testid="chassis-tablet" class="pointer-events-none absolute left-1/2 -translate-x-1/2 -top-[4px] h-1 w-1 rounded-full bg-zinc-600 dark:bg-zinc-400"></div>
                </template>
                <!-- Chassis-adjacent orientation toggle: sibling of the iframe
                     wrapper so it floats outside the chassis ring without being
                     clipped by the wrapper's overflow-hidden. Only for mobile +
                     tablet -- those are the only presets where rotating maps to a
                     real device-orientation change (desktop/custom/full have no
                     canonical orientation). The inline phone glyph rotates 90°
                     to mirror the chosen orientation, giving immediate visual
                     feedback for what the next click will do. -->
                <template v-if="(viewport.activePresetCategory.value === 'mobile' || viewport.activePresetCategory.value === 'tablet') && ui.previewHeight !== null">
                    <button type="button"
                            @click="ui.toggleRotation()"
                            :title="i18n.t('toolbar.rotate')"
                            :aria-label="i18n.t('toolbar.rotate')"
                            class="absolute -top-9 right-0 h-7 w-7 flex items-center justify-center rounded bg-white dark:bg-zinc-800 ring-1 ring-zinc-200 dark:ring-zinc-700 shadow-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                        <svg aria-hidden="true" focusable="false"
                             class="w-3.5 h-3.5 transition-transform duration-300 ease-out"
                             :class="ui.previewRotated ? 'rotate-90' : 'rotate-0'"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="7" y="3" width="10" height="18" rx="2"/>
                            <circle cx="12" cy="18" r="0.5" fill="currentColor"/>
                        </svg>
                    </button>
                </template>

                <!-- The wrapper bg stays white in both themes -- iframe content
                     is the consumer's domain (no theme propagation), and they
                     render against an assumed light backdrop. A dark wrapper
                     would bleed through transparent iframe areas.

                     Device frame: `activePresetCategory` drives the bezel look.
                     Mobile presets get a chunky phone-style bezel (rounded-3xl
                     + thick ring); tablet gets a softer bezel; desktop gets a
                     monitor-bezel; Full/foundations skip the frame so the
                     preview reads edge-to-edge. Ring color shifts in dark mode
                     so the chassis still contrasts against dark:bg-zinc-950
                     (zinc-800 there would visually dissolve). -->
                <div ref="wrapperRef"
                     data-testid="iframe-wrapper"
                     :style="wrapperStyle"
                     :class="[
                         viewport.isDragging.value ? '' : 'transition-[width,height,border-radius] duration-200 ease-out',
                         viewport.activePresetCategory.value === 'mobile' ? 'rounded-[2rem] ring-[6px] ring-zinc-800 dark:ring-zinc-700 shadow-xl' : '',
                         viewport.activePresetCategory.value === 'tablet' ? 'rounded-2xl ring-[5px] ring-zinc-800 dark:ring-zinc-700 shadow-xl' : '',
                         viewport.activePresetCategory.value === 'desktop' ? 'rounded-lg ring-2 ring-zinc-200 dark:ring-zinc-700 shadow-xl' : '',
                         viewport.activePresetCategory.value === 'custom' ? 'rounded shadow-lg' : '',
                         viewport.activePresetCategory.value === 'full' ? '' : '',
                     ]"
                     class="relative bg-white overflow-hidden">
                    <!-- Iframe height tracks inner contentDocument so the
                         component sits in natural document flow rather
                         than forcing an internal scroll bar inside a
                         fixed-height shell. The 400px fallback shows
                         before the first onload fires so the preview area
                         has something visible during the initial paint. -->
                    <iframe :src="viewport.iframeSrc.value" @load="onIframeLoad"
                            class="border-0 block"
                            :style="iframeStyle"
                            :class="{ 'pointer-events-none': viewport.isDragging.value }"></iframe>
                    <!-- Solid overlay during navigation -- the iframe keeps showing the old
                         slug's content until the browser finishes parsing the new src. The
                         overlay sits ON TOP of the iframe so the user never sees the stale
                         content. No enter transition: the old slug's body must be obscured
                         instantly when navigation starts. The loading dot itself fades in
                         with a 120ms delay so very fast loads (<120ms -- common for cached
                         responses) skip the dot entirely. -->
                    <div v-show="isLoading"
                         data-testid="loading-overlay"
                         class="absolute inset-0 flex items-center justify-center bg-white pointer-events-none">
                        <div v-show="isLoading"
                             class="w-2 h-2 rounded-full bg-zinc-300 animate-pulse"></div>
                    </div>
                </div>
                <!-- Drag handles: siblings of the iframe wrapper (not inside it) so
                     they can extend onto the chassis ring without being clipped by the
                     wrapper's overflow-hidden. Visible only in Custom mode -- presets
                     carry a fixed device width, and dragging would silently kick the
                     user out of the preset (confusing). Foundations route has no
                     responsivity to test, so it's also excluded. -->
                <template v-if="viewport.activePreset.value === 'custom' && viewport.type.value !== 'foundations' && viewport.type.value !== 'overview'">
                    <div data-testid="drag-handle-right"
                         @mousedown="viewport.startDrag" @touchstart="viewport.startDrag"
                         class="absolute -right-2 top-0 bottom-0 w-4 flex items-center justify-center cursor-ew-resize group">
                        <div class="w-1 h-12 rounded-full bg-zinc-400 dark:bg-zinc-600 group-hover:bg-zinc-700 dark:group-hover:bg-zinc-300 transition-colors"
                             :class="viewport.isDragging.value && '!bg-zinc-500 dark:!bg-zinc-200'"></div>
                    </div>
                </template>
                <template v-if="viewport.activePreset.value === 'custom' && viewport.type.value !== 'foundations' && viewport.type.value !== 'overview'">
                    <div data-testid="drag-handle-left"
                         @mousedown="viewport.startDrag" @touchstart="viewport.startDrag"
                         class="absolute -left-2 top-0 bottom-0 w-4 flex items-center justify-center cursor-ew-resize group">
                        <div class="w-1 h-12 rounded-full bg-zinc-400 dark:bg-zinc-600 group-hover:bg-zinc-700 dark:group-hover:bg-zinc-300 transition-colors"
                             :class="viewport.isDragging.value && '!bg-zinc-500 dark:!bg-zinc-200'"></div>
                    </div>
                </template>
                <!-- Dimension badge under the chassis: visible only when the
                     preset actually has logical W×H (not Full, not Custom
                     whose height is content-driven). Position is `absolute`
                     so it sits in the gap below the chassis ring without
                     taking layout space -- `top: 100%` puts it flush against
                     the ring's outer edge; an extra mt-2 gives a touch of
                     breathing room from the home-indicator pill on mobile. -->
                <template v-if="viewport.dimensionsLabel.value && viewport.activePreset.value !== 'custom' && viewport.activePreset.value !== 'full'">
                    <div class="absolute left-1/2 -translate-x-1/2 mt-4 font-mono text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap pointer-events-none"
                         style="top: 100%">{{ viewport.dimensionsLabel.value }}</div>
                </template>
            </div>
        </template>
        <template v-if="!viewport.iframeSrc.value && !catalog.loading">
            <p class="text-zinc-500 mt-20">{{ i18n.t('empty_state') }}</p>
        </template>
        <template v-if="catalog.loading">
            <p class="text-zinc-500 mt-20">{{ i18n.t('loading') }}</p>
        </template>
    </div>
</template>
