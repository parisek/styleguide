import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        sidebarOpen: true,
        previewWidth: '100%',
        isDragging: false,
        route: { type: 'landing', slug: null },

        setWidth(w) {
            this.previewWidth = w;
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },

        setRoute(type, slug = null) {
            this.route = { type, slug };
        },

        get widthLabel() {
            return this.previewWidth === '100%' ? 'Full' : this.previewWidth;
        },
    });
});
