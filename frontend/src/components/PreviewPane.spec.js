import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import PreviewPane from './PreviewPane.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountPane(type = 'component', slug = 'hero', { onViewport } = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = { toolbar: { rotate: 'Rotate', orientation_portrait: 'Portrait', orientation_landscape: 'Landscape' }, empty_state: 'Select a component', loading: 'Loading...' };
    // The mount helper simulates a catalogue that has already finished its
    // initial fetch (items populated) -- loading defaults to true in the
    // store until init() resolves, which never runs in this isolated
    // mount, so it's set explicitly here. Without this the empty-state
    // spec below sees the (equally legacy-faithful) "loading" paragraph
    // instead, since `catalog.loading` stays true.
    const catalog = useCatalogStore();
    catalog.items = [{ id: 'hero', name: 'Hero' }];
    catalog.loading = false;

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef });
            // Hands the composable instance back to the caller (Task 6's
            // registerIframe test below needs to read viewport.iframeEl
            // directly) without changing the return shape for every
            // pre-existing call site, which never passes this option.
            onViewport?.(viewport);
            provide('viewport', viewport);
            return () => h(PreviewPane);
        },
    });
    return mount(Host, { attachTo: document.body });
}

describe('PreviewPane', () => {
    it('renders an iframe pointed at the render endpoint for the current route', () => {
        const wrapper = mountPane('component', 'hero');
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/hero');
    });

    it('uses width:100%;height:100% for the default Full preset', () => {
        const wrapper = mountPane('component', 'hero');
        const style = wrapper.find('[data-testid="iframe-wrapper"]').attributes('style');
        expect(style).toContain('width: 100%');
        expect(style).toContain('height: 100%');
    });

    it('shows drag handles only when the Custom preset is active', async () => {
        const wrapper = mountPane('component', 'hero');
        expect(wrapper.find('[data-testid="drag-handle-right"]').exists()).toBe(false);
        const ui = useUiStore();
        ui.setWidth('500px');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="drag-handle-right"]').exists()).toBe(true);
    });

    it('shows mobile chassis decorations only for a mobile-category preset', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="chassis-mobile"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chassis-tablet"]').exists()).toBe(false);
    });

    it('reflects ui.isPreviewLoading in the loading overlay visibility', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="loading-overlay"]').isVisible()).toBe(true);
        ui.isPreviewLoading = false;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="loading-overlay"]').isVisible()).toBe(false);
    });

    it('flips ui.isPreviewLoading to false when the iframe fires load', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.find('iframe').trigger('load');
        expect(ui.isPreviewLoading).toBe(false);
    });

    it('shows the empty-state message when there is no route slug and not loading', () => {
        const wrapper = mountPane('overview', null);
        expect(wrapper.text()).toContain('Select a component');
    });

    // Task 8 review fix: onMounted wires viewport.observeContainer(paneRef)
    // to a real ResizeObserver; without an explicit teardown that instance
    // stays attached to the (now detached) pane node after the route
    // changes away from this component. Swap in a tracking stub so
    // disconnect() calls are observable, then assert onBeforeUnmount
    // actually tears it down instead of leaving it for the next
    // observeContainer() call to clean up as a side effect.
    it('disconnects the container ResizeObserver on unmount', () => {
        const originalResizeObserver = global.ResizeObserver;
        class TrackingResizeObserver {
            constructor(callback) {
                this.callback = callback;
                this.disconnectCalls = 0;
                TrackingResizeObserver.instances.push(this);
            }
            observe() {}
            unobserve() {}
            disconnect() { this.disconnectCalls += 1; }
        }
        TrackingResizeObserver.instances = [];
        global.ResizeObserver = TrackingResizeObserver;

        try {
            const wrapper = mountPane('component', 'hero');
            expect(TrackingResizeObserver.instances).toHaveLength(1);
            const containerObserver = TrackingResizeObserver.instances[0];

            wrapper.unmount();

            expect(containerObserver.disconnectCalls).toBe(1);
        } finally {
            global.ResizeObserver = originalResizeObserver;
        }
    });

    // Task 6 (on-demand accessibility check): ViewportToolbar's a11y check
    // reads the <iframe> DOM handle through viewport.iframeEl rather than
    // owning a ref of its own (PreviewPane is the only component that
    // renders the element) -- mirrors the wrapperRef/observeWrapper wiring
    // tested implicitly by the chassis/drag-handle specs above.
    it('registers the iframe element with viewport.registerIframe on mount and clears it on unmount', async () => {
        let viewport;
        const wrapper = mountPane('component', 'hero', { onViewport: (vp) => { viewport = vp; } });
        // The watch(iframeRef, ...) callback that calls registerIframe()
        // runs on the next tick (Vue's default 'pre' flush queues it as a
        // microtask), not synchronously within mount() itself -- same
        // reason other specs in this file await $nextTick() after a store
        // mutation before asserting on its DOM effect.
        await wrapper.vm.$nextTick();
        expect(viewport.iframeEl.value).toBe(wrapper.find('iframe').element);

        wrapper.unmount();
        expect(viewport.iframeEl.value).toBeNull();
    });
});
