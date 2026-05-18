import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        sidebarOpen: true,
        previewWidth: '100%',
        isDragging: false,
        // Mirror of the preview's iframe loading state. Lives in the ui store
        // rather than the preview component because `setRoute()` needs to flip
        // it synchronously — before the iframe src changes, before the browser
        // starts the request, before any `load` event can fire. Tracking it in
        // an Alpine $watch lost the race on cached responses (load fired
        // before the watcher set isLoading=true → overlay stayed visible).
        isPreviewLoading: false,
        route: { type: 'landing', slug: null },

        setWidth(w) {
            this.previewWidth = w;
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },

        setRoute(type, slug = null) {
            // Only flip the loading flag for routes that actually render an
            // iframe — landing / fields / future non-iframe views don't need
            // the overlay, and triggering it would leave a phantom overlay
            // with no `load` event to clear it.
            if (['component', 'page', 'overview'].includes(type)) {
                this.isPreviewLoading = true;
            }
            this.route = { type, slug };
        },

        get widthLabel() {
            return this.previewWidth === '100%' ? 'Full' : this.previewWidth;
        },
    });
});
