import Alpine from 'alpinejs';

// Storybook-inspired viewport presets. Width is the meaningful dimension
// (iframe height tracks the available chrome). Height is shown in the toolbar
// readout but doesn't constrain rendering — the iframe stays as tall as the
// preview area, mirroring how Chrome DevTools' device toolbar works in
// "Responsive" mode.
const VIEWPORTS = [
    { key: 'mobile',  label: 'Mobile',  width: 375,  height: 667  },
    { key: 'tablet',  label: 'Tablet',  width: 768,  height: 1024 },
    { key: 'desktop', label: 'Desktop', width: 1280, height: 800  },
    { key: 'full',    label: 'Full',    width: null, height: null }, // 100%
];

document.addEventListener('alpine:init', () => {
    Alpine.data('preview', () => ({
        viewports: VIEWPORTS,
        currentWidth: 0,

        init() {
            // Track iframe wrapper width reactively. `offsetWidth` isn't an
            // observable, so a computed getter reads stale values; a single
            // ResizeObserver on document.body picks up the wrapper whenever
            // x-if re-renders it (component navigation) without rebinding.
            // `x-ref` inside `<template x-if>` doesn't surface on the parent
            // x-data's $refs in Alpine 3, so we query the DOM directly.
            this._ro = new ResizeObserver((entries) => {
                for (const entry of entries) {
                    this.currentWidth = Math.round(entry.contentRect.width);
                }
            });
            this.$watch('iframeSrc', () => queueMicrotask(() => this.observeWrapper()));
            queueMicrotask(() => this.observeWrapper());
        },

        // The loading flag itself is set synchronously by setRoute() in the
        // ui store (before the iframe src changes). Here we just clear it
        // when the new document finishes parsing. Iframes fire `load` for
        // every src change including the initial bind, so this reliably
        // matches a previous setRoute() → isPreviewLoading = true.
        onIframeLoad() {
            Alpine.store('ui').isPreviewLoading = false;
        },

        get isLoading() {
            return Alpine.store('ui').isPreviewLoading;
        },

        observeWrapper() {
            this._ro.disconnect();
            const wrapper = document.querySelector('[x-ref="iframeWrapper"]');
            if (wrapper) this._ro.observe(wrapper);
            else this.currentWidth = 0;
        },

        // Breadcrumb pieces for the toolbar. `currentSectionKey` returns null
        // while the components API hasn't loaded yet so the template hides the
        // section segment instead of flashing an untranslated label.
        get currentSectionKey() {
            const route = Alpine.store('ui').route;
            if (!route.slug) return null;
            const components = Alpine.store('components');
            if (route.type === 'page') return 'pages';
            const item = components.find(route.type, route.slug);
            if (!item) return null;
            return components.sectionOf(item, route.type);
        },

        get currentItemName() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return item?.name ?? route.slug;
        },

        get iframeSrc() {
            const route = Alpine.store('ui').route;
            // Overview / fields render inside the iframe too — same project
            // CSS + Twig env as components / pages, just rendered against
            // shared yaml context instead of one specific component.
            if (route.type === 'overview' || route.type === 'fields') {
                return `/styleguide/render/${route.type}/index`;
            }
            if (!route.slug) return null;
            if (route.type !== 'component' && route.type !== 'page') return null;
            return `/styleguide/render/${route.type}/${route.slug}`;
        },

        get previewWidth() {
            return Alpine.store('ui').previewWidth;
        },

        get isDragging() {
            return Alpine.store('ui').isDragging;
        },

        // Which preset matches the current width? `null` means custom (drag-resize).
        get activePreset() {
            const w = this.previewWidth;
            if (w === '100%') return 'full';
            const px = parseInt(w, 10);
            const match = VIEWPORTS.find((v) => v.width === px);
            return match?.key ?? null;
        },

        setPreset(key) {
            const preset = VIEWPORTS.find((v) => v.key === key);
            if (!preset) return;
            Alpine.store('ui').setWidth(preset.width === null ? '100%' : `${preset.width}px`);
        },

        startDrag(event) {
            event.preventDefault();
            const startX = event.clientX ?? event.touches?.[0]?.clientX;
            if (startX == null) return;

            const wrapper = document.querySelector('[x-ref="iframeWrapper"]');
            if (!wrapper) return;
            const parentRect = wrapper.parentElement.getBoundingClientRect();
            // Wrapper is centered in its parent, so dragging the right edge
            // by Δx grows the wrapper by 2·Δx (both sides grow symmetrically).
            // Anchor the calculation to the parent's center so the cursor
            // stays under the drag handle.
            const centerX = parentRect.left + parentRect.width / 2;
            const startHalf = wrapper.offsetWidth / 2;

            Alpine.store('ui').isDragging = true;

            let raf = 0;
            let pendingWidth = null;
            const flush = () => {
                raf = 0;
                if (pendingWidth != null) {
                    Alpine.store('ui').setWidth(`${pendingWidth}px`);
                    pendingWidth = null;
                }
            };

            const move = (e) => {
                const x = e.clientX ?? e.touches?.[0]?.clientX;
                if (x == null) return;
                // Distance from center under cursor → that's the half-width.
                // Clamp to a sensible minimum so the iframe doesn't collapse.
                const half = Math.max(160, x - centerX);
                // Snap to whole pixels to avoid sub-pixel flicker.
                pendingWidth = Math.round(half * 2);
                if (!raf) raf = requestAnimationFrame(flush);
            };

            const up = () => {
                if (raf) {
                    cancelAnimationFrame(raf);
                    flush();
                }
                Alpine.store('ui').isDragging = false;
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
                document.removeEventListener('touchmove', move);
                document.removeEventListener('touchend', up);
            };

            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
            document.addEventListener('touchmove', move, { passive: true });
            document.addEventListener('touchend', up);
        },
    }));
});
