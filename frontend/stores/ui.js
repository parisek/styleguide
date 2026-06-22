import Alpine from 'alpinejs';

// Parse a `?width=…` URL param. Accepts `full` / `100%` / a strict positive
// integer in a sane 100..4000 px range. Anything else returns null and the
// persisted value wins. The all-digits regex pre-check rejects `375.5` /
// `375px` / `375junk` (all of which `parseInt` would silently coerce to 375)
// so a malformed URL doesn't quietly resolve to an unintended width.
function parseWidthParam(raw) {
    if (!raw) return null;
    if (raw === 'full' || raw === '100%') return '100%';
    if (!/^\d+$/.test(raw)) return null;
    const px = Number(raw);
    if (Number.isInteger(px) && px >= 100 && px <= 4000) return `${px}px`;
    return null;
}

document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        // Sidebar open/closed state survives page reloads via the
        // `@alpinejs/persist` plugin (registered in styleguide.js before
        // Alpine.start()). The `.as(key)` namespaces the localStorage key so
        // sibling apps can't accidentally collide with us.
        sidebarOpen: Alpine.$persist(true).as('sg-sidebar-open'),
        // Preview iframe width — persisted, with a URL-param override on first
        // load (read-only chain: `?width=…` → localStorage → '100%'). The
        // write-side (push URL on width change) is intentionally NOT wired
        // yet — pasting a URL with `?width=375` works today; the follow-up
        // PR will add History API updates so a chosen width also shows up
        // in the address bar.
        previewWidth: Alpine.$persist('100%').as('sg-preview-width'),
        // Preset-derived iframe height in pixels — null means "auto-fit to
        // content" (the historical behaviour). Set by setPreset() alongside
        // width when the user picks a fixed device preset (Mobile 375, Tablet
        // 1024, …) so the iframe carries the preset's aspect ratio and
        // viewport units (h-svh / h-screen) inside resolve against it. Reset
        // to null on custom-width input, drag, or Full — both auto-fit and
        // 100%-wide modes want content-driven height. Persisted alongside
        // previewWidth so the two stay in sync across reloads (setWidth
        // writes both atomically).
        previewHeight: Alpine.$persist(null).as('sg-preview-height'),
        // Landscape-orientation flag. When true, the iframe renders with
        // previewWidth and previewHeight swapped — so Mobile 375 × 667
        // portrait becomes 667 × 375 landscape. Stays a flag (not direct
        // swap of the underlying width/height) so `activePreset` keeps
        // matching the canonical preset entry and the highlight stays
        // accurate. Auto-reset to false on any non-preset width change
        // (custom input, drag, Full) — those modes have no canonical
        // orientation to rotate.
        previewRotated: Alpine.$persist(false).as('sg-preview-rotated'),
        isDragging: false,
        // Mirror of the preview's iframe loading state. Lives in the ui store
        // rather than the preview component because `setRoute()` needs to flip
        // it synchronously — before the iframe src changes, before the browser
        // starts the request, before any `load` event can fire. Tracking it in
        // an Alpine $watch lost the race on cached responses (load fired
        // before the watcher set isLoading=true → overlay stayed visible).
        isPreviewLoading: false,
        // Sidebar search query. Lives in the ui store (rather than the search
        // Alpine component) because the sidebar's `x-for` lives in a sibling
        // Alpine scope — store gives both ends a single reactive source.
        searchQuery: '',
        route: { type: 'landing', slug: null },

        init() {
            // On small screens the sidebar is a slide-over overlay (see
            // index.html) — force it closed at boot so it doesn't cover the
            // preview on load. `sidebarOpen` is persisted, so a desktop user who
            // collapsed it keeps that; mobile always starts closed regardless of
            // the persisted value. The 1024px cutoff matches the `lg` breakpoint
            // the overlay CSS keys off.
            if (window.matchMedia('(max-width: 1023px)').matches) {
                this.sidebarOpen = false;
            }

            // URL param override applies once, only at boot. After that, the
            // user's preset / drag / custom-input interactions write through
            // $persist normally. (Future: write back to URL via History API
            // when the width changes — keeps the chain symmetric.)
            //
            // `setWidth` (not direct assignment) is the entry point so the
            // companion `previewHeight` resets to null in lockstep with
            // `previewWidth` — the URL only carries width, so any preset
            // height left over from a prior session must be cleared to
            // avoid the highlight-by-width / height-still-set mismatch that
            // would make rotate-button + dimension-badge state stale.
            // Components that want preset width AND height in the URL can
            // round-trip through setPreset() after the URL resolves.
            const urlWidth = parseWidthParam(new URLSearchParams(location.search).get('width'));
            if (urlWidth) this.setWidth(urlWidth);
        },

        setWidth(w, h = null) {
            this.previewWidth = w;
            this.previewHeight = h;
            // Any width change that isn't a preset application drops the
            // landscape flag — custom widths, drag, Full have no canonical
            // orientation. setPreset() passes h explicitly to opt back in.
            if (h === null) this.previewRotated = false;
        },

        // Toggle landscape orientation. Only meaningful when both
        // previewWidth and previewHeight have pixel values (i.e. a device
        // preset is active). Callers should gate the rotate button on
        // `previewHeight !== null`.
        toggleRotation() {
            if (this.previewHeight === null) return;
            this.previewRotated = !this.previewRotated;
        },

        // Explicit orientation setter — used by the segmented portrait /
        // landscape switch in the toolbar. The switch's two buttons each
        // call this with a concrete value (false / true) rather than
        // toggling, so the active button reflects the current orientation
        // and the user sees both states at once.
        setOrientation(rotated) {
            if (this.previewHeight === null) return;
            this.previewRotated = !!rotated;
        },

        // Effective iframe dimensions after applying rotation. `displayWidth`
        // and `displayHeight` are what the template should push onto the
        // iframe element; they swap previewWidth ↔ previewHeight when
        // previewRotated is true.
        get displayWidth() {
            if (this.previewRotated && this.previewHeight !== null) {
                return `${this.previewHeight}px`;
            }
            return this.previewWidth;
        },
        get displayHeight() {
            if (this.previewRotated && this.previewHeight !== null) {
                // previewWidth is a string like "375px" or "100%" — extract
                // the integer for the rotated-height numeric value.
                const px = parseInt(this.previewWidth, 10);
                return Number.isInteger(px) ? px : this.previewHeight;
            }
            return this.previewHeight;
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },

        setRoute(type, slug = null) {
            // Only flip the loading flag for routes that actually render an
            // iframe — landing / overview / fields don't, and triggering it
            // would leave a phantom overlay with no `load` event to clear it.
            if (['component', 'page', 'doc', 'foundations'].includes(type)) {
                this.isPreviewLoading = true;
            }
            this.route = { type, slug };
        },

        get widthLabel() {
            return this.previewWidth === '100%' ? 'Full' : this.previewWidth;
        },
    });
});
