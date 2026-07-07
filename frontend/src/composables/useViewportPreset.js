import { ref, computed, watch } from 'vue';
import { useUiStore } from '../stores/ui.js';
import { useCatalogStore } from '../stores/catalog.js';
import {
    VIEWPORTS, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX,
    findPresetByWidth, effectiveDims, fitZoom, isPortraitOrientation,
} from '../lib/viewportMath.js';
import { flattenFieldsTree } from '../lib/fieldsTree.js';

// Ported from frontend/components/preview.js. One instance is provided by
// App.vue (Task 7 Step 9) and injected by ViewportToolbar.vue (this task)
// and PreviewPane.vue/FieldsDrawer.vue/UsagePanel.vue/LinkBar.vue
// (Tasks 8-10) — mirrors the legacy single `x-data="preview"` Alpine scope
// that all of that markup shared.
// `variant`/`setVariant` default to a no-op ref/fn so every pre-existing
// call site (and useViewportPreset.spec.js, which constructs the composable
// directly outside any router-aware component setup) keeps working
// unchanged. Production wiring lives in App.vue: useVariant() needs
// useRoute()/useRouter() (vue-router injection, only available inside a
// mounted component's setup()), so it's computed one level up and threaded
// through here as plain refs -- same "shared-scope illusion" pattern type/
// slug already use.
export function useViewportPreset({ type, slug, variant = ref(null), setVariant = () => {} }) {
    const ui = useUiStore();
    const catalog = useCatalogStore();

    const currentItem = computed(() => (slug.value ? catalog.find(type.value, slug.value) : null));

    const previewWidthPx = computed(() => {
        if (ui.previewWidth === '100%') return null;
        const px = parseInt(ui.previewWidth, 10);
        return Number.isInteger(px) ? px : null;
    });

    const activePreset = computed(() => {
        if (ui.previewWidth === '100%') return 'full';
        const match = findPresetByWidth(previewWidthPx.value);
        return match?.key ?? 'custom';
    });

    const activePresetCategory = computed(() => {
        if (activePreset.value === 'full') return 'full';
        if (activePreset.value === 'custom') return 'custom';
        return VIEWPORTS.find((v) => v.key === activePreset.value)?.category ?? 'desktop';
    });

    const isFullPreset = computed(() => ui.previewWidth === '100%');

    const effective = computed(() => {
        if (currentItem.value?.responsive === false) return { width: null, height: null };
        return effectiveDims({ width: previewWidthPx.value, height: ui.previewHeight, rotated: ui.previewRotated });
    });

    const containerAvailableWidth = ref(0);
    const containerAvailableHeight = ref(0);
    let containerRO = null;

    // Measures the chrome pane (the `.overflow-auto` container hosting the
    // iframe wrapper) so fit-to-bounds zoom tracks viewport resize. 48px =
    // 2x the p-6 padding on that container in both axes. Always disconnects
    // whatever instance is live first, so re-calling with a new el (route
    // change) never leaks the prior observer. Calling with `el` null (e.g.
    // PreviewPane's onBeforeUnmount) tears the observer down explicitly
    // instead of relying on the next route's observeContainer call to do it.
    function observeContainer(el) {
        if (containerRO) {
            containerRO.disconnect();
            containerRO = null;
        }
        if (!el) return;
        containerRO = new ResizeObserver((entries) => {
            for (const entry of entries) {
                containerAvailableWidth.value = Math.max(0, entry.contentRect.width - 48);
                containerAvailableHeight.value = Math.max(0, entry.contentRect.height - 48);
            }
        });
        if (typeof el.addEventListener === 'function' || el instanceof Element) {
            containerRO.observe(el);
        }
        containerAvailableWidth.value = Math.max(0, (el.clientWidth ?? 0) - 48);
        containerAvailableHeight.value = Math.max(0, (el.clientHeight ?? 0) - 48);
    }

    const zoom = computed(() => fitZoom({
        width: effective.value.width,
        height: effective.value.height,
        availWidth: containerAvailableWidth.value,
        availHeight: containerAvailableHeight.value,
    }));

    // The variant grid's own scale readout (styleguide 2.0 UX fix,
    // replaces a per-tile "375 × 667 · 84 %" repeated in every tile
    // header): every tile in the grid shares the SAME preset and (uniform
    // cell widths) the SAME zoom, so VariantGrid.vue reports just the
    // representative (first) tile's zoom here via setGridZoom() instead of
    // each tile computing/rendering its own. `null` means "no grid active
    // right now" -- VariantGrid.vue resets it to null on unmount (the
    // instant PreviewPane.vue's `gridActive` v-if goes false), so the
    // classic single preview's own zoom (below) is never shadowed by a
    // stale grid value left over from a previous route.
    const gridZoom = ref(null);
    function setGridZoom(z) {
        gridZoom.value = z;
    }

    // Whichever zoom currently governs the visible preview's scale: the
    // classic single preview's own container-fit zoom, or -- while the
    // variant grid owns the canvas -- the shared per-tile zoom reported
    // above. Centralizes the choice so both dimensionsLabel (device
    // presets, below) and ViewportToolbar.vue's Custom-width branch don't
    // each have to re-derive "grid zoom if set, else the classic zoom".
    const effectiveZoom = computed(() => gridZoom.value ?? zoom.value);

    const dimensionsLabel = computed(() => {
        const { width, height } = effective.value;
        if (!width || !height) return null;
        const dims = `${width} × ${height}`;
        const z = effectiveZoom.value;
        return z < 1 ? `${dims} (${Math.round(z * 100)} %)` : dims;
    });

    const isPortrait = computed(() => isPortraitOrientation({
        width: previewWidthPx.value, height: ui.previewHeight, rotated: ui.previewRotated,
    }));

    function setPreset(key) {
        const preset = VIEWPORTS.find((v) => v.key === key);
        if (!preset) return;
        ui.setWidth(preset.width === null ? '100%' : `${preset.width}px`, preset.height);
    }

    function setPortrait(portrait) {
        ui.setPortrait(portrait);
    }

    const customWidthInput = ref('');
    function syncCustomFromStore() {
        if (ui.previewWidth === '100%') { customWidthInput.value = ''; return; }
        const px = parseInt(ui.previewWidth, 10);
        if (Number.isInteger(px)) customWidthInput.value = px;
    }
    watch(() => ui.previewWidth, syncCustomFromStore, { immediate: true });

    function applyCustomWidth() {
        const px = Number(customWidthInput.value);
        if (!Number.isInteger(px) || px < CUSTOM_WIDTH_MIN || px > CUSTOM_WIDTH_MAX) {
            syncCustomFromStore();
            return;
        }
        ui.setWidth(`${px}px`);
    }

    const reloadNonce = ref(0);
    function reloadPreview() {
        catalog.init();
        reloadNonce.value++;
    }

    // Shared URL builder — same composition rules (reload nonce, iframe
    // theme, variant) for both the classic single preview (iframeSrc, below)
    // and the variant grid's per-tile sources (VariantGrid.vue). Pulled out
    // so the grid can request a specific tile's variant id independent of
    // the URL-driven `variant` ref this composable also tracks (a grid tile
    // is never itself deep-linked — only the classic single-preview
    // ?variant= is). `variantIdOverride` of `null`/`undefined` means "no
    // variant" (the default tile / the historical no-?variant= URL shape).
    function buildIframeSrc(variantIdOverride) {
        let src;
        if (type.value === 'foundations') {
            src = '/styleguide/render/foundations/index';
        } else if (!slug.value || !['component', 'page', 'doc'].includes(type.value)) {
            return null;
        } else {
            src = `/styleguide/render/${type.value}/${slug.value}`;
        }
        if (reloadNonce.value) src += (src.includes('?') ? '&' : '?') + `_r=${reloadNonce.value}`;
        // Iframe content theme — independent of the SPA chrome's own theme
        // toggle (stores/theme.js). Only appended when dark so the historical
        // (pre-feature) URL shape is unchanged for the default 'light' case.
        if (ui.iframeTheme === 'dark') src += (src.includes('?') ? '&' : '?') + 'theme=dark';
        // File-convention variant (Task 1: ComponentParser.discoverVariants(),
        // Task 2: Router::whitelistVariant()/Renderer resolve it server-side).
        // Only appended when set, same omit-the-default-case shape as theme
        // above -- the historical no-variant render URL is unchanged.
        if (variantIdOverride) src += (src.includes('?') ? '&' : '?') + `variant=${encodeURIComponent(variantIdOverride)}`;
        return src;
    }

    const iframeSrc = computed(() => buildIframeSrc(variant.value));

    // A dedicated builder for a grid tile's own iframe src — the default
    // tile (id null/undefined) reuses the base no-variant URL, every other
    // tile isolates its own `?variant=<id>`. Exposed (not just used
    // internally) so VariantGrid.vue doesn't need to re-derive the same
    // reload-nonce/theme composition rules.
    function iframeSrcForVariant(variantId) {
        return buildIframeSrc(variantId ?? null);
    }

    // The variant GRID (replaces the removed toolbar pill switcher, commit
    // 901e1b8's server-side stacked view, and the never-shipped
    // variantSwitcherVisible pill gate) takes over the whole preview area
    // whenever the current entry has discovered variants AND no specific
    // `?variant=` is selected. A deep-linked `?variant=<id>` still isolates
    // to the classic single preview -- see useVariant.js: an unknown/
    // removed id is whitelisted back to null, so it falls through to the
    // grid rather than 404ing or showing a blank single preview. Restricted
    // to routes that actually render an iframe (component/page/doc) so
    // foundations/overview -- which never carry `variants` -- can't
    // accidentally qualify.
    const gridActive = computed(() => !!slug.value
        && ['component', 'page', 'doc'].includes(type.value)
        && (currentItem.value?.variants?.length ?? 0) > 0
        && !variant.value);

    // Everything BUT the responsive-width dropdown/drag-handles/orientation
    // toggle -- iframe theme toggle, canvas mode, open-in-new-tab, reload --
    // stays available in grid mode too (they act on the grid's
    // default tile / the whole preview area), so it's gated on this broader
    // flag rather than `toolbarVisible` below. Foundations/overview and
    // responsive:false entries are excluded exactly as before.
    const previewActionsVisible = computed(() => !!iframeSrc.value
        && type.value !== 'foundations'
        && type.value !== 'overview'
        && currentItem.value?.responsive !== false);

    // The responsive-width preset dropdown + custom-width input + orientation
    // toggle stay meaningful in grid mode too — VariantGrid.vue applies the
    // same shared preset uniformly to every tile (scaled down per tile to
    // fit that tile's own cell), rather than owning independent per-tile
    // controls. Only the classic single preview's drag-to-resize handles and
    // device chassis decorations are single-preview-only (PreviewPane.vue
    // simply doesn't render them in grid mode, since VariantGrid.vue owns
    // its own layout there) — so this no longer narrows by `!gridActive`;
    // it's kept as its own computed (rather than inlining
    // previewActionsVisible everywhere) so a future single-preview-only
    // exception has one place to land.
    const toolbarVisible = computed(() => previewActionsVisible.value);

    const currentSectionKey = computed(() => {
        if (!slug.value) return null;
        if (type.value === 'page') return 'pages';
        if (type.value === 'doc') return null;
        if (!currentItem.value) return null;
        return catalog.sectionOf(currentItem.value, type.value);
    });

    const currentItemName = computed(() => currentItem.value?.name ?? slug.value);
    const currentItemDescription = computed(() => currentItem.value?.description ?? '');

    // Breadcrumb-based variant isolation (styleguide 2.0 UX redesign,
    // replaces the earlier "← All" toolbar back control): the toolbar
    // breadcrumb's trailing segment and the description bar's context
    // label both need the ISOLATED variant's own display label, not just
    // its raw id. `variant.value` is already whitelisted against the
    // current entry's discovered variants by useVariant.js (an unknown/
    // removed id resolves to null), so a lookup miss here can only mean
    // "no variant isolated" — never a stale/invalid id — and `?? null`
    // rather than a fallback string is deliberate: callers gate on this
    // being falsy to know whether a variant is isolated at all.
    const currentVariantLabel = computed(() => {
        if (!variant.value) return null;
        return currentItem.value?.variants?.find((v) => v.id === variant.value)?.title ?? variant.value;
    });
    const currentVariantDescription = computed(() => {
        if (!variant.value) return '';
        return currentItem.value?.variants?.find((v) => v.id === variant.value)?.description ?? '';
    });

    // App.vue's description bar content. Deliberately REPLACES the
    // component/page's general `description` with the isolated variant's
    // own one rather than appending both -- the variant's description is
    // strictly more specific once one is isolated, so showing the general
    // blurb underneath it would be redundant at best, contradictory at
    // worst. If the isolated variant has no description of its own,
    // nothing variant-specific renders at all (this resolves to '', which
    // App.vue's v-if treats as "show nothing") -- it does NOT fall back to
    // the component's general description, since that would silently
    // de-contextualize a bar sitting right under a variant-labeled
    // breadcrumb.
    const descriptionBarText = computed(() => (variant.value ? currentVariantDescription.value : currentItemDescription.value));

    const fieldsTree = computed(() => flattenFieldsTree(currentItem.value?.fields));
    const fieldsCount = computed(() => fieldsTree.value.length);

    const isDragging = ref(false);
    let wrapperEl = null;
    function observeWrapper(el) {
        wrapperEl = el;
    }

    function startDrag(event) {
        event.preventDefault();
        const startX = event.clientX ?? event.touches?.[0]?.clientX;
        if (startX == null || !wrapperEl) return;
        const parentRect = wrapperEl.parentElement.getBoundingClientRect();
        const centerX = parentRect.left + parentRect.width / 2;
        const dragZoom = zoom.value || 1;
        isDragging.value = true;
        let raf = 0;
        let pendingWidth = null;
        const flush = () => {
            raf = 0;
            if (pendingWidth != null) {
                ui.setWidth(`${pendingWidth}px`);
                pendingWidth = null;
            }
        };
        const move = (e) => {
            const x = e.clientX ?? e.touches?.[0]?.clientX;
            if (x == null) return;
            const half = Math.max(160, (x - centerX) / dragZoom);
            pendingWidth = Math.round(half * 2);
            if (!raf) raf = requestAnimationFrame(flush);
        };
        const up = () => {
            if (raf) { cancelAnimationFrame(raf); flush(); }
            isDragging.value = false;
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            document.removeEventListener('touchmove', move);
            document.removeEventListener('touchend', up);
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
        document.addEventListener('touchmove', move, { passive: true });
        document.addEventListener('touchend', up);
    }

    return {
        // `type`/`slug` are the same refs passed in — not listed among the
        // composable's originally spec'd return keys, but every consumer
        // that renders route-aware chrome (ViewportToolbar's breadcrumb/
        // type-pill/select-prompt block) needs them and, per the
        // provide/inject design, has no other way to reach them without
        // duplicating routeInfo()/useRoute() in each injected component —
        // which would also break injecting into components mounted outside
        // a router context (see ViewportToolbar.spec.js). Passing the refs
        // through keeps the single shared-scope illusion the legacy
        // `x-data="preview"` Alpine component provided.
        type, slug, variant, setVariant,
        currentItem, activePreset, activePresetCategory, isFullPreset, effective, zoom,
        gridZoom, setGridZoom, effectiveZoom,
        dimensionsLabel, isPortrait, setPreset, setPortrait, customWidthInput, applyCustomWidth,
        reloadPreview, iframeSrc, iframeSrcForVariant, toolbarVisible, previewActionsVisible, gridActive, currentSectionKey, currentItemName,
        currentItemDescription, currentVariantLabel, currentVariantDescription, descriptionBarText, fieldsTree, fieldsCount, isDragging, startDrag,
        observeWrapper, observeContainer, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX, VIEWPORTS,
    };
}
