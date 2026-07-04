import { defineStore } from 'pinia';
import { usePersistedRef } from '../lib/persistedRef.js';
import { parseWidthParam, isPortraitOrientation, rotationForPortrait } from '../lib/viewportMath.js';

// Ported from frontend/stores/ui.js. `routeType`/`routeSlug` replace the
// legacy `route: {type, slug}` object with two flat fields (Pinia state
// diffing is simpler on primitives); `src/lib/routeInfo.js` + the
// vue-router `beforeEach` guard in Task 4 are what call `setRoute()` on
// every navigation, replacing the old `router.js` `apply()`/`popstate`
// listener.
export const useUiStore = defineStore('ui', {
    state: () => ({
        sidebarOpen: usePersistedRef('sg-sidebar-open', true),
        previewWidth: usePersistedRef('sg-preview-width', '100%'),
        previewHeight: usePersistedRef('sg-preview-height', null),
        previewRotated: usePersistedRef('sg-preview-rotated', false),
        isDragging: false,
        isPreviewLoading: false,
        searchQuery: '',
        routeType: 'landing',
        routeSlug: null,
    }),
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
        },
    },
});
