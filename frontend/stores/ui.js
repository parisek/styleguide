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
            // URL param override applies once, only at boot. After that, the
            // user's preset / drag / custom-input interactions write through
            // $persist normally. (Future: write back to URL via History API
            // when the width changes — keeps the chain symmetric.)
            const urlWidth = parseWidthParam(new URLSearchParams(location.search).get('width'));
            if (urlWidth) this.previewWidth = urlWidth;
        },

        setWidth(w) {
            this.previewWidth = w;
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },

        setRoute(type, slug = null) {
            // Only flip the loading flag for routes that actually render an
            // iframe — landing / overview / fields don't, and triggering it
            // would leave a phantom overlay with no `load` event to clear it.
            if (['component', 'page', 'foundations'].includes(type)) {
                this.isPreviewLoading = true;
            }
            this.route = { type, slug };
        },

        get widthLabel() {
            return this.previewWidth === '100%' ? 'Full' : this.previewWidth;
        },
    });
});
