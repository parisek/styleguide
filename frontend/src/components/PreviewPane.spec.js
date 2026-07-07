import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import PreviewPane from './PreviewPane.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountPane(type = 'component', slug = 'hero', { onViewport, items, variant } = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        toolbar: { rotate: 'Rotate', orientation_portrait: 'Portrait', orientation_landscape: 'Landscape', variant_default: 'Default' },
        empty_state: 'Select a component', loading: 'Loading...',
    };
    // The mount helper simulates a catalogue that has already finished its
    // initial fetch (items populated) -- loading defaults to true in the
    // store until init() resolves, which never runs in this isolated
    // mount, so it's set explicitly here. Without this the empty-state
    // spec below sees the (equally legacy-faithful) "loading" paragraph
    // instead, since `catalog.loading` stays true.
    const catalog = useCatalogStore();
    catalog.items = items ?? [{ id: 'hero', name: 'Hero' }];
    catalog.loading = false;

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant });
            // Hands the composable instance back to the caller without
            // changing the return shape for every pre-existing call site,
            // which never passes this option. The second argument (route
            // refs) is additive too -- only the no-flash-navigation specs
            // below use it, to simulate a route change without a real
            // router.
            onViewport?.(viewport, { typeRef, slugRef });
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

});

// No-flash preview navigation: the OLD document used to keep painting in
// the iframe until the new one finished loading (a real browser doesn't
// blank an iframe just because its `src` attribute changed). Keying the
// iframe on its own src identity forces Vue to unmount the old element and
// mount a genuinely fresh (blank) one on every navigation instead.
describe('PreviewPane — no-flash navigation', () => {
    it('keys the iframe by src identity so a route change remounts a fresh element', async () => {
        let refs;
        const wrapper = mountPane('component', 'hero', {
            items: [{ id: 'hero', name: 'Hero' }, { id: 'gizmo', name: 'Gizmo' }],
            onViewport: (_vp, r) => { refs = r; },
        });
        const before = wrapper.find('iframe').element;

        refs.slugRef.value = 'gizmo';
        await wrapper.vm.$nextTick();

        const after = wrapper.find('iframe').element;
        expect(after).not.toBe(before);
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/gizmo');
    });

    it('resets the measured content height immediately on navigation, before the new document reports its own height', async () => {
        let refs;
        const wrapper = mountPane('component', 'hero', {
            items: [{ id: 'hero', name: 'Hero' }, { id: 'gizmo', name: 'Gizmo' }],
            onViewport: (_vp, r) => { refs = r; },
        });
        const ui = useUiStore();
        // Custom width, no canonical height -- wrapperStyle's height is
        // content-driven (iframeContentHeight ?? the 400px pre-measure
        // default), same quadrant the drag-handle specs above exercise.
        ui.setWidth('375px');
        await wrapper.vm.$nextTick();

        // jsdom never actually navigates a same-origin iframe (no real
        // network stack), so contentDocument.documentElement stays null
        // (see VariantGrid.spec.js's identical caveat) -- stub a fake
        // document directly on the element so fitIframeToContent() has
        // something with a real scrollHeight to measure.
        const iframeEl = wrapper.find('iframe').element;
        Object.defineProperty(iframeEl, 'contentDocument', {
            configurable: true,
            value: { documentElement: { scrollHeight: 900 }, body: { scrollHeight: 900 } },
        });
        await wrapper.find('iframe').trigger('load');
        await wrapper.vm.$nextTick();

        let style = wrapper.find('[data-testid="iframe-wrapper"]').attributes('style');
        expect(style).toContain('height: 900px');

        refs.slugRef.value = 'gizmo';
        await wrapper.vm.$nextTick();

        style = wrapper.find('[data-testid="iframe-wrapper"]').attributes('style');
        expect(style).toContain('height: 400px');
    });

    it('still clears ui.isPreviewLoading once the remounted (post-navigation) iframe fires load', async () => {
        let refs;
        const wrapper = mountPane('component', 'hero', {
            items: [{ id: 'hero', name: 'Hero' }, { id: 'gizmo', name: 'Gizmo' }],
            onViewport: (_vp, r) => { refs = r; },
        });
        const ui = useUiStore();

        refs.slugRef.value = 'gizmo';
        ui.isPreviewLoading = true; // setRoute() would normally flip this synchronously
        await wrapper.vm.$nextTick();

        await wrapper.find('iframe').trigger('load');
        expect(ui.isPreviewLoading).toBe(false);
    });
});

// styleguide-2.0 redesign: the classic single-device chassis is replaced by
// VariantGrid.vue whenever the current entry has discovered variants and no
// `?variant=` is selected (see useViewportPreset.js's `gridActive`).
describe('PreviewPane — variant grid', () => {
    it('renders the grid (not the classic single iframe) for a multi-variant entry with no variant selected', () => {
        const wrapper = mountPane('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
        });
        expect(wrapper.find('[data-testid="variant-grid"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="iframe-wrapper"]').exists()).toBe(false);
    });

    it('renders the classic single iframe (no grid) for an entry without variants', () => {
        const wrapper = mountPane('component', 'hero');
        expect(wrapper.find('[data-testid="variant-grid"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="iframe-wrapper"]').exists()).toBe(true);
    });

    it('renders the classic single iframe (no grid) once a variant is deep-linked', () => {
        const wrapper = mountPane('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            variant: ref('secondary'),
        });
        expect(wrapper.find('[data-testid="variant-grid"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="iframe-wrapper"]').exists()).toBe(true);
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/multi?variant=secondary');
    });
});
