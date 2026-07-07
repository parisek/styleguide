import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { runAxeCheck } from '../lib/axeInject.js';

// axeInject.js's runAxeCheck() reaches into a real iframe's
// contentWindow/contentDocument -- unavailable in these toolbar-only mounts
// (PreviewPane.vue, the actual iframe owner, isn't rendered here), so it's
// mocked at the module boundary. formatAxeResults() is NOT mocked -- these
// tests exercise the real pure function, only the DOM-heavy injection is
// stubbed. Covered end-to-end (including the real axe-core script) by
// tests/e2e/playwright/a11y-check.spec.js.
vi.mock('../lib/axeInject.js', () => ({ runAxeCheck: vi.fn() }));

function mountWithViewport(type = 'component', slug = 'hero', { items, variant, setVariant, onViewport } = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        toolbar: {
            viewport_preset: 'Viewport', custom_width_label: 'Custom', custom_width_placeholder: 'px',
            orientation_label: 'Orientation', type_component: 'Component', type_page: 'Page',
            canvas_mode_label: 'Canvas', open_in_new_tab: 'Open', reload: 'Reload', more_actions: 'More',
            variant_label: 'Variant', variant_default: 'All',
        },
        sections: { blocks: 'Blocks' },
        a11y: { check_action: 'Accessibility check' },
    };
    useCatalogStore().items = items ?? [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant, setVariant });
            // Lets the a11y-check tests below reach viewport.registerIframe()
            // directly (a real iframe/contentWindow is DOM/browser-only —
            // PreviewPane.vue isn't mounted in these toolbar-only specs) —
            // optional, so every pre-existing call site above is unaffected.
            onViewport?.(viewport);
            provide('viewport', viewport);
            return () => h(ViewportToolbar);
        },
    });
    return mount(Host);
}

describe('ViewportToolbar', () => {
    it('renders the active preset word label ("Full" by default)', () => {
        const wrapper = mountWithViewport();
        expect(wrapper.text()).toContain('Full');
    });

    it('clicking a preset row calls setPreset and updates the trigger label', async () => {
        const wrapper = mountWithViewport();
        await wrapper.find('[data-testid="viewport-trigger"]').trigger('click');
        const tabletRow = wrapper.findAll('[data-testid^="viewport-preset-"]').find((el) => el.attributes('data-testid') === 'viewport-preset-tablet');
        await tabletRow.trigger('click');
        expect(wrapper.text()).toContain('Tablet');
    });

    it('renders the breadcrumb section + item name for a component route', () => {
        const wrapper = mountWithViewport('component', 'hero');
        expect(wrapper.text()).toContain('Blocks');
        expect(wrapper.text()).toContain('Hero');
    });

    it('does not render the viewport dropdown for the foundations route', () => {
        const wrapper = mountWithViewport('foundations', null);
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(false);
    });

    it('clicking the iframe-theme toggle flips ui.iframeTheme independently of the chrome theme', async () => {
        const wrapper = mountWithViewport();
        const ui = useUiStore();
        expect(ui.iframeTheme).toBe('light');
        await wrapper.find('[data-testid="iframe-theme-toggle"]').trigger('click');
        expect(ui.iframeTheme).toBe('dark');
        await wrapper.find('[data-testid="iframe-theme-toggle"]').trigger('click');
        expect(ui.iframeTheme).toBe('light');
    });
});

describe('ViewportToolbar — variant switcher', () => {
    it('is absent when the entry has no variants', () => {
        const wrapper = mountWithViewport('component', 'hero', {
            items: [{ id: 'hero', name: 'Hero', category: 'Block', variants: [] }],
        });
        expect(wrapper.find('[data-testid="variant-switcher"]').exists()).toBe(false);
    });

    it('is absent when the entry carries no variants field at all (BC default)', () => {
        const wrapper = mountWithViewport('component', 'hero', {
            items: [{ id: 'hero', name: 'Hero', category: 'Block' }],
        });
        expect(wrapper.find('[data-testid="variant-switcher"]').exists()).toBe(false);
    });

    it('renders All + each discovered variant when variants exist', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [
                    { id: 'dark-bg', label: 'dark-bg' },
                    { id: 'secondary', label: 'Secondary style' },
                ],
            }],
        });
        const switcher = wrapper.find('[data-testid="variant-switcher"]');
        expect(switcher.exists()).toBe(true);
        const labels = switcher.findAll('button').map((b) => b.text());
        // "All" (toolbar.variant_default) -- the no-?variant= default view now
        // stacks every variant instead of showing just the implicit default,
        // so the pill label reflects that ("Default" would misdescribe it).
        expect(labels).toEqual(['All', 'dark-bg', 'Secondary style']);
    });

    it('clicking a variant button calls viewport.setVariant with its id', async () => {
        const setVariant = vi.fn();
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            setVariant,
        });
        const buttons = wrapper.find('[data-testid="variant-switcher"]').findAll('button');
        await buttons[1].trigger('click'); // index 0 = Default
        expect(setVariant).toHaveBeenCalledWith('secondary');
    });

    it('clicking All calls viewport.setVariant(null)', async () => {
        const setVariant = vi.fn();
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            variant: ref('secondary'),
            setVariant,
        });
        const buttons = wrapper.find('[data-testid="variant-switcher"]').findAll('button');
        await buttons[0].trigger('click');
        expect(setVariant).toHaveBeenCalledWith(null);
    });

    it('marks the active variant button (or Default) as visually selected', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            variant: ref('secondary'),
        });
        const buttons = wrapper.find('[data-testid="variant-switcher"]').findAll('button');
        expect(buttons[0].classes()).not.toContain('bg-red-600');
        expect(buttons[1].classes()).toContain('bg-red-600');
    });

    // Regression for Phase 4 Task 3 review finding 1: the switcher used to
    // live inside the toolbarVisible block, which excludes responsive:false
    // entries — silently making a responsive:false entry's variants
    // unreachable from the SPA even though docs/API.md promises the
    // switcher shows "when at least one exists", with no carve-out for
    // responsive:false.
    it('still renders the variant switcher for a responsive:false entry (width controls stay hidden)', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                responsive: false,
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        const switcher = wrapper.find('[data-testid="variant-switcher"]');
        expect(switcher.exists()).toBe(true);
        expect(switcher.findAll('button').map((b) => b.text())).toEqual(['All', 'Secondary style']);
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(false);
    });
});

describe('ViewportToolbar — accessibility check', () => {
    beforeEach(() => {
        runAxeCheck.mockReset();
    });

    it('renders the check button on the inline lg+ surface for a normal route, and on the dedicated cluster for foundations', () => {
        const compWrapper = mountWithViewport('component', 'hero');
        expect(compWrapper.find('[data-testid="a11y-check-button"]').exists()).toBe(true);
        expect(compWrapper.find('[data-testid="a11y-check-button-foundations"]').exists()).toBe(false);

        const foundationsWrapper = mountWithViewport('foundations', null);
        expect(foundationsWrapper.find('[data-testid="a11y-check-button-foundations"]').exists()).toBe(true);
        expect(foundationsWrapper.find('[data-testid="a11y-check-button"]').exists()).toBe(false);
    });

    it('also renders the check button inside the ⋮ overflow menu', () => {
        const wrapper = mountWithViewport();
        expect(wrapper.find('[data-testid="a11y-check-button-overflow"]').exists()).toBe(true);
    });

    it('is a no-op when no iframe has been registered yet (PreviewPane not mounted in this spec)', async () => {
        const wrapper = mountWithViewport();
        await wrapper.find('[data-testid="a11y-check-button"]').trigger('click');
        expect(runAxeCheck).not.toHaveBeenCalled();
    });

    it('runs the axe check against the registered iframe and stores formatted results', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'hero', { onViewport: (vp) => { viewport = vp; } });
        const fakeIframe = {};
        viewport.registerIframe(fakeIframe);
        runAxeCheck.mockResolvedValue({
            violations: [{ id: 'image-alt', impact: 'critical', description: 'Alt text', help: 'Images must have alt', helpUrl: '#', nodes: [{ target: ['img'] }] }],
        });

        await wrapper.find('[data-testid="a11y-check-button"]').trigger('click');
        await flushPromises();

        expect(runAxeCheck).toHaveBeenCalledWith(fakeIframe);
        const ui = useUiStore();
        expect(ui.a11yRunning).toBe(false);
        expect(ui.a11yResults.total).toBe(1);
        expect(ui.a11yResults.byImpact.critical).toHaveLength(1);
    });

    // Review finding baked in: a second click while a check is already
    // in flight must not fire a duplicate axe.run() against the same
    // iframe document.
    it('re-entrancy guard: a second click while a check is running has no effect', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'hero', { onViewport: (vp) => { viewport = vp; } });
        viewport.registerIframe({});
        let resolveCheck;
        runAxeCheck.mockReturnValue(new Promise((resolve) => { resolveCheck = resolve; }));

        await wrapper.find('[data-testid="a11y-check-button"]').trigger('click');
        expect(useUiStore().a11yRunning).toBe(true);

        await wrapper.find('[data-testid="a11y-check-button"]').trigger('click');
        expect(runAxeCheck).toHaveBeenCalledTimes(1);

        resolveCheck({ violations: [] });
        await flushPromises();
        expect(useUiStore().a11yRunning).toBe(false);
    });

    // setRoute() (stores/ui.js) fires on every navigation, including one
    // that happens while a check from the PREVIOUS route is still in
    // flight -- it bumps a11yGeneration, which runA11yCheck() compares
    // against its own snapshot after awaiting, and must then discard the
    // result instead of repopulating ui.a11yResults for a document that's
    // no longer displayed.
    it('discards a late-resolving check\'s results if the route changed while it was in flight', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'hero', { onViewport: (vp) => { viewport = vp; } });
        viewport.registerIframe({});
        let resolveCheck;
        runAxeCheck.mockReturnValue(new Promise((resolve) => { resolveCheck = resolve; }));

        await wrapper.find('[data-testid="a11y-check-button"]').trigger('click');
        const ui = useUiStore();
        expect(ui.a11yRunning).toBe(true);

        ui.setRoute('component', 'other');
        expect(ui.a11yRunning).toBe(false);

        resolveCheck({ violations: [{ id: 'image-alt', impact: 'critical', description: 'x', help: 'x', helpUrl: '#', nodes: [] }] });
        await flushPromises();

        expect(ui.a11yResults).toBeNull();
        expect(ui.a11yRunning).toBe(false);
    });
});
