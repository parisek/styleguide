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

    const dimensionsLabel = computed(() => {
        const { width, height } = effective.value;
        if (!width || !height) return null;
        const dims = `${width} × ${height}`;
        return zoom.value < 1 ? `${dims} (${Math.round(zoom.value * 100)} %)` : dims;
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

    const iframeSrc = computed(() => {
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
        if (variant.value) src += (src.includes('?') ? '&' : '?') + `variant=${encodeURIComponent(variant.value)}`;
        return src;
    });

    const toolbarVisible = computed(() => !!iframeSrc.value
        && type.value !== 'foundations'
        && type.value !== 'overview'
        && currentItem.value?.responsive !== false);

    // Deliberately independent of toolbarVisible/`responsive` (docs/API.md:
    // "when at least one exists, the SPA toolbar shows a variant switcher" —
    // no carve-out for responsive:false). A fixed-layout doc/demo can still
    // ship alternate markup files; hiding the switcher just because width
    // controls don't apply would make those variants unreachable from the
    // SPA. Restricted to routes that actually render an iframe (component/
    // page/doc) so foundations/overview — which never carry `variants` —
    // can't accidentally qualify.
    const variantSwitcherVisible = computed(() => !!iframeSrc.value
        && ['component', 'page', 'doc'].includes(type.value)
        && (currentItem.value?.variants?.length ?? 0) > 0);

    const currentSectionKey = computed(() => {
        if (!slug.value) return null;
        if (type.value === 'page') return 'pages';
        if (type.value === 'doc') return null;
        if (!currentItem.value) return null;
        return catalog.sectionOf(currentItem.value, type.value);
    });

    const currentItemName = computed(() => currentItem.value?.name ?? slug.value);
    const currentItemDescription = computed(() => currentItem.value?.description ?? '');
    const fieldsTree = computed(() => flattenFieldsTree(currentItem.value?.fields));
    const fieldsCount = computed(() => fieldsTree.value.length);

    const isDragging = ref(false);
    let wrapperEl = null;
    function observeWrapper(el) {
        wrapperEl = el;
    }

    // Shares the <iframe> DOM handle PreviewPane.vue owns with whatever
    // triggers an on-demand action against its contentWindow/contentDocument
    // (ViewportToolbar.vue's accessibility check) -- mirrors observeWrapper
    // above: a plain ref rather than a full ResizeObserver-style
    // registration, since nothing here needs to react to the iframe's own
    // resize/mutation, just hold a reference to it. `ref` (not a bare
    // variable like wrapperEl) because ViewportToolbar's click handler reads
    // it reactively across renders/route changes, whereas observeWrapper's
    // consumer (startDrag) only ever reads the latest value synchronously
    // inside an event handler.
    const iframeEl = ref(null);
    function registerIframe(el) {
        iframeEl.value = el;
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
        dimensionsLabel, isPortrait, setPreset, setPortrait, customWidthInput, applyCustomWidth,
        reloadPreview, iframeSrc, toolbarVisible, variantSwitcherVisible, currentSectionKey, currentItemName,
        currentItemDescription, fieldsTree, fieldsCount, isDragging, startDrag,
        observeWrapper, observeContainer, iframeEl, registerIframe, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX, VIEWPORTS,
    };
}
