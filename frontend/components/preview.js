import Alpine from 'alpinejs';

// Viewport presets aligned with Tailwind v4 default breakpoints (md / lg / xl /
// 2xl = 768 / 1024 / 1280 / 1536) plus four device-mythic anchors (320 / 375 /
// 425 for mobiles, 1920 for Full HD). The first label group keeps the legacy
// "Mobile / Tablet / Desktop" semantics for tooltips, while the width number is
// the actual button text — Storybook-style segmented control with explicit
// pixel values.
//
// Height is informational (shown in the tooltip); the iframe itself stays as
// tall as the surrounding chrome allows, mirroring Chrome DevTools' Responsive
// mode. Custom widths go through `applyCustomWidth()` and don't live in this
// list — they're persisted as the raw px string via the ui store.
const VIEWPORTS = [
    { key: 'mobile-s',   label: 'Mobile S',   width: 320,  height: 568  },
    { key: 'mobile',     label: 'Mobile',     width: 375,  height: 667  },
    { key: 'mobile-l',   label: 'Mobile L',   width: 425,  height: 812  },
    { key: 'tablet',     label: 'Tablet',     width: 768,  height: 1024 },
    { key: 'tablet-l',   label: 'Tablet L',   width: 1024, height: 1366 },
    { key: 'desktop',    label: 'Desktop',    width: 1280, height: 800  },
    { key: 'desktop-l',  label: 'Desktop L',  width: 1536, height: 960  },
    { key: 'desktop-xl', label: 'Desktop XL', width: 1920, height: 1080 },
    { key: 'full',       label: 'Full',       width: null, height: null }, // 100%
];

// Custom width sanity range — same bounds as the URL-param parser in ui.js,
// kept in sync so all three input paths (URL, drag, custom input) reject
// the same garbage.
const CUSTOM_MIN = 100;
const CUSTOM_MAX = 4000;

document.addEventListener('alpine:init', () => {
    Alpine.data('preview', () => ({
        viewports: VIEWPORTS,
        currentWidth: 0,
        // The Custom input always shows the current pixel value (or '' when
        // Full is active). Typing then Enter / blur writes through to the
        // store. The bidirectional sync is set up in init() below.
        customWidthInput: '',

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

            // Mirror store width → custom input. Runs on every width change
            // (preset click, drag, URL param boot, persisted load, custom
            // input itself) so the input field stays a faithful readout when
            // it's not the source of truth.
            this._syncCustomFromStore();
            Alpine.effect(() => this._syncCustomFromStore());
        },

        _syncCustomFromStore() {
            const w = Alpine.store('ui').previewWidth;
            if (w === '100%') {
                this.customWidthInput = '';
                return;
            }
            const px = parseInt(w, 10);
            if (Number.isInteger(px)) this.customWidthInput = px;
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

        get currentItemDescription() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return item?.description ?? '';
        },

        get iframeSrc() {
            const route = Alpine.store('ui').route;
            // Foundations / fields render inside the iframe too — same project
            // CSS + Twig env as components / pages, just rendered against
            // shared yaml context instead of one specific component.
            if (route.type === 'foundations' || route.type === 'fields') {
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

        // Which preset matches the current width? Returns the preset key for
        // an exact match, or 'custom' when the width is a pixel value that
        // doesn't line up with any preset (drag-resize, manual input,
        // URL-param override with a non-preset number).
        get activePreset() {
            const w = this.previewWidth;
            if (w === '100%') return 'full';
            const px = parseInt(w, 10);
            const match = VIEWPORTS.find((v) => v.width === px);
            return match?.key ?? 'custom';
        },

        setPreset(key) {
            const preset = VIEWPORTS.find((v) => v.key === key);
            if (!preset) return;
            Alpine.store('ui').setWidth(preset.width === null ? '100%' : `${preset.width}px`);
        },

        // Custom-input apply path. Triggered on Enter / blur from the number
        // input. Strict integer check (`Number(...)` + `Number.isInteger`)
        // rejects decimals like 375.5 outright — otherwise `parseInt` would
        // silently truncate to 375 and the user would see a different width
        // than they typed. Out-of-range or non-integer values revert to the
        // store's current width via _syncCustomFromStore so the input can't
        // be left in a desync state.
        applyCustomWidth() {
            const px = Number(this.customWidthInput);
            if (!Number.isInteger(px) || px < CUSTOM_MIN || px > CUSTOM_MAX) {
                this._syncCustomFromStore();
                return;
            }
            Alpine.store('ui').setWidth(`${px}px`);
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
