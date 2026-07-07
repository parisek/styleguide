import { defineStore } from 'pinia';
import { usePersistedRef } from '../lib/persistedRef.js';
import { setCookie } from '../lib/cookie.js';
import { parseWidthParam, isPortraitOrientation, rotationForPortrait } from '../lib/viewportMath.js';

// Migrates the pre-2.0 rows/grid tile-layout toggle (key `sg-variant-layout`,
// values "rows"/"grid") to the new 5-option density control (key
// `sg-variant-columns`, values "auto"/1/2/3/4) the first time this module
// loads after the upgrade -- a visitor's persisted choice should carry
// forward instead of silently resetting to the new default. "rows" (one
// tile per row) maps to the exact replacement, `1` -- VariantGrid.vue's
// single-column subgrid renders identically, just via a different knob.
// "grid" (side-by-side auto-fit columns) maps to "auto" (device-aware
// density), the closest new equivalent and the new default besides. Runs
// unconditionally at import time (not lazily inside usePersistedRef, which
// is a generic helper with no knowledge of this specific key's history);
// guarded on the new key already existing so it's a true one-shot and the
// legacy key removal below can't re-trigger it on a later reload.
function migrateVariantColumnsKey() {
    try {
        if (localStorage.getItem('sg-variant-columns') !== null) return;
        const raw = localStorage.getItem('sg-variant-layout');
        if (raw === null) return;
        let legacy;
        try { legacy = JSON.parse(raw); } catch { legacy = raw; }
        localStorage.setItem('sg-variant-columns', JSON.stringify(legacy === 'rows' ? 1 : 'auto'));
        localStorage.removeItem('sg-variant-layout');
    } catch (e) {
        // Safari private mode throws on localStorage access; fall back silently
        // (usePersistedRef's own read just below tolerates this the same way).
    }
}

// Ported from frontend/stores/ui.js. `routeType`/`routeSlug` replace the
// legacy `route: {type, slug}` object with two flat fields (Pinia state
// diffing is simpler on primitives); `src/lib/routeInfo.js` + the
// vue-router `beforeEach` guard in Task 4 are what call `setRoute()` on
// every navigation, replacing the old `router.js` `apply()`/`popstate`
// listener.
export const useUiStore = defineStore('ui', {
    state: () => {
        migrateVariantColumnsKey();
        return {
            sidebarOpen: usePersistedRef('sg-sidebar-open', true),
            previewWidth: usePersistedRef('sg-preview-width', '100%'),
            previewHeight: usePersistedRef('sg-preview-height', null),
            previewRotated: usePersistedRef('sg-preview-rotated', false),
            isDragging: false,
            isPreviewLoading: false,
            searchQuery: '',
            routeType: 'landing',
            routeSlug: null,
            // On-demand accessibility check (axe-core). Ephemeral, NOT persisted
            // (unlike previewWidth/sidebarOpen/etc. above) — a stale violation
            // list surviving a reload or a route change would describe a
            // document that's no longer loaded in the iframe. Reset by
            // setRoute() below, mirroring isPreviewLoading's own per-navigation
            // reset.
            a11yResults: null,
            a11yRunning: false,
            // Monotonic counter bumped by setRoute() (see below) alongside the
            // reset above. ViewportToolbar's runA11yCheck() snapshots this
            // before starting a check and compares it again after awaiting
            // runAxeCheck() -- a mismatch means a navigation happened while the
            // check was in flight, so the (now-stale) result is discarded
            // instead of repopulating these fields for a document the iframe no
            // longer shows. Store-level (not a local ref in ViewportToolbar)
            // because setRoute() is the single place navigation is observed;
            // mirrors the reloadNonce idiom in useViewportPreset.js.
            a11yGeneration: 0,
            // Iframe content theme — independent of the SPA chrome's own light/
            // dark/system toggle (stores/theme.js). Persisted under its own
            // localStorage key so switching one doesn't affect the other.
            iframeTheme: usePersistedRef('sg-iframe-theme', 'light'),
            // Variant grid tile density — "auto" (column sizing derives from the
            // shared viewport preset, see lib/tileGeometry.js's
            // autoGridColumnBasis()) or an exact column count 1-4. Persisted
            // under its own key, independent of previewWidth etc., since it's a
            // VariantGrid-only concern (inert once a specific `?variant=`
            // isolates to the classic single preview). Replaces the pre-2.0
            // rows/grid toggle -- see migrateVariantColumnsKey() above for the
            // one-shot upgrade of a visitor's existing preference.
            variantColumns: usePersistedRef('sg-variant-columns', 'auto'),
        };
    },
    getters: {
        isPortrait: (state) => isPortraitOrientation({
            width: parseInt(state.previewWidth, 10),
            height: state.previewHeight,
            rotated: state.previewRotated,
        }),
        displayWidth: (state) => {
            if (state.previewRotated && state.previewHeight !== null) return `${state.previewHeight}px`;
            return state.previewWidth;
        },
        displayHeight: (state) => {
            if (state.previewRotated && state.previewHeight !== null) {
                const px = parseInt(state.previewWidth, 10);
                return Number.isInteger(px) ? px : state.previewHeight;
            }
            return state.previewHeight;
        },
        widthLabel: (state) => (state.previewWidth === '100%' ? 'Full' : state.previewWidth),
    },
    actions: {
        // Called once at app boot (main.js, Task 4). `?width=` overrides the
        // persisted value on first load only; user interaction after that
        // writes through usePersistedRef normally.
        initFromUrl() {
            if (window.matchMedia('(max-width: 1023px)').matches) {
                this.sidebarOpen = false;
            }
            const urlWidth = parseWidthParam(new URLSearchParams(location.search).get('width'));
            if (urlWidth) this.setWidth(urlWidth);
        },
        setWidth(w, h = null) {
            this.previewWidth = w;
            this.previewHeight = h;
            if (h === null) this.previewRotated = false;
        },
        toggleRotation() {
            if (this.previewHeight === null) return;
            this.previewRotated = !this.previewRotated;
        },
        setOrientation(rotated) {
            if (this.previewHeight === null) return;
            this.previewRotated = !!rotated;
        },
        setPortrait(portrait) {
            if (this.previewHeight === null) return;
            const wPx = parseInt(this.previewWidth, 10);
            if (!Number.isInteger(wPx)) return;
            this.previewRotated = rotationForPortrait({ width: wPx, height: this.previewHeight, portrait });
        },
        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },
        setRoute(type, slug = null) {
            if (['component', 'page', 'doc', 'foundations'].includes(type)) {
                this.isPreviewLoading = true;
            }
            this.routeType = type;
            this.routeSlug = slug;
            // Review finding baked in: clear on EVERY navigation, not just
            // iframe-bearing ones (unlike the isPreviewLoading branch above)
            // — a stale a11y panel from the previous route/document would
            // otherwise linger over /overview or /foundations too.
            this.a11yResults = null;
            this.a11yRunning = false;
            // Invalidates any check still in flight from the previous route
            // — see the a11yGeneration state comment above.
            this.a11yGeneration++;
        },
        // Mirrors the server-side whitelist in Router::whitelistTheme() — any
        // value other than the literal string 'dark' resolves to 'light', so
        // a corrupted localStorage value can never produce a broken query param.
        //
        // Also mirrors the choice into the `sg-iframe-theme` cookie (in
        // addition to the `iframeTheme` ref's own localStorage persistence via
        // usePersistedRef) — localStorage never leaves the browser, so it's
        // invisible to Router::synthesizeEmbeddedRoute() on the server. A
        // native link click inside dark-toggled iframe content is a top-level
        // browser navigation of the iframe, not an SPA route change; the only
        // way the server can recover the preference for that request is a
        // cookie riding along with it. See Router::IFRAME_THEME_COOKIE.
        setIframeTheme(value) {
            this.iframeTheme = value === 'dark' ? 'dark' : 'light';
            setCookie('sg-iframe-theme', this.iframeTheme);
        },
        // Whitelisted the same way as setIframeTheme() above — a corrupted
        // localStorage value (or a stray call with an out-of-range number)
        // can never resolve to anything but the "auto" default. Numbers, not
        // numeric strings -- ViewportToolbar.vue's segmented buttons pass 1-4
        // as actual numbers, matching the type usePersistedRef round-trips
        // through JSON (JSON.parse('1') is the number 1, not "1").
        setVariantColumns(value) {
            this.variantColumns = ['auto', 1, 2, 3, 4].includes(value) ? value : 'auto';
        },
    },
});
