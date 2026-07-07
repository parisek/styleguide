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
            variant_label: 'Variant', variant_default: 'Default', breadcrumb_back_to_grid: 'Back to all variants',
            variant_columns_label: 'Tile density', variant_columns_auto_label: 'Auto',
            variant_columns_auto: 'Auto tooltip',
            variant_columns_1: '1 column', variant_columns_2: '2 columns',
            variant_columns_3: '3 columns', variant_columns_4: '4 columns',
        },
        sections: { blocks: 'Blocks' },
        a11y: { check_action: 'Accessibility check', unavailable_in_grid: 'Needs a single preview' },
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

// The toolbar pill variant switcher (Phase 4 Task 3, commit dc4715a) is gone
// -- variants now render as a full-canvas grid of independent preview tiles
// (VariantGrid.vue / VariantGrid.spec.js), which has no toolbar affordance.
// ViewportToolbar's own responsibility in grid mode is narrower: hide the
// single-preview-only width controls, and disable (not hide) the a11y check
// button since it needs one iframe. No `[data-testid="variant-switcher"]`
// exists anywhere in this file's specs any more.
describe('ViewportToolbar — grid mode', () => {
    // styleguide-2.0 rework: the width-preset dropdown now stays visible AND
    // functional in grid mode -- the shared preset applies to every tile
    // (VariantGrid.vue scales each one down to fit its own cell). There is
    // still no `[data-testid="variant-switcher"]` toolbar pill -- that stays
    // gone per the original redesign.
    it('keeps the width-preset dropdown visible when the entry has variants and none is selected (grid mode)', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        expect(wrapper.find('[data-testid="variant-switcher"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(true);
    });

    it('shows the width-preset dropdown again once a specific variant is deep-linked (classic single preview)', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            variant: ref('secondary'),
        });
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(true);
    });

    it('disables the a11y check button (with a title hint) in grid mode', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        const button = wrapper.find('[data-testid="a11y-check-button"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBe('Needs a single preview');
    });

    it('re-enables the a11y check button once a specific variant is deep-linked', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            variant: ref('secondary'),
        });
        const button = wrapper.find('[data-testid="a11y-check-button"]');
        expect(button.attributes('disabled')).toBeUndefined();
    });

    // The secondary actions cluster (iframe theme toggle, canvas mode, open
    // in new tab, reload) is NOT single-preview-only machinery -- it stays
    // available in grid mode, acting on the grid's default tile / the whole
    // preview area.
    it('keeps the iframe theme toggle available in grid mode', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        expect(wrapper.find('[data-testid="iframe-theme-toggle"]').exists()).toBe(true);
    });

    it('renders the density segmented control only when the grid is active', () => {
        const gridWrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        expect(gridWrapper.find('[data-testid="variant-columns-toggle"]').exists()).toBe(true);

        const noVariantsWrapper = mountWithViewport('component', 'hero');
        expect(noVariantsWrapper.find('[data-testid="variant-columns-toggle"]').exists()).toBe(false);

        const isolatedWrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            variant: ref('secondary'),
        });
        expect(isolatedWrapper.find('[data-testid="variant-columns-toggle"]').exists()).toBe(false);
    });

    it('renders all five density options (Auto, 1, 2, 3, 4), Auto pressed by default', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        expect(wrapper.find('[data-testid="variant-columns-auto"]').attributes('aria-pressed')).toBe('true');
        for (const n of [1, 2, 3, 4]) {
            expect(wrapper.find(`[data-testid="variant-columns-${n}"]`).attributes('aria-pressed')).toBe('false');
            expect(wrapper.find(`[data-testid="variant-columns-${n}"]`).text()).toBe(String(n));
        }
    });

    it('clicking a density button updates ui.variantColumns and moves aria-pressed', async () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
        });
        const ui = useUiStore();
        expect(ui.variantColumns).toBe('auto');

        await wrapper.find('[data-testid="variant-columns-2"]').trigger('click');
        expect(ui.variantColumns).toBe(2);
        expect(wrapper.find('[data-testid="variant-columns-2"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-testid="variant-columns-auto"]').attributes('aria-pressed')).toBe('false');

        await wrapper.find('[data-testid="variant-columns-auto"]').trigger('click');
        expect(ui.variantColumns).toBe('auto');
        expect(wrapper.find('[data-testid="variant-columns-auto"]').attributes('aria-pressed')).toBe('true');
    });
});

// The per-tile "375 × 667 · 84 %" readout VariantGrid.vue used to render in
// every tile header is gone (styleguide 2.0 UX fix) -- the shared scale
// (every tile shares the same preset and, since cell widths are uniform,
// the same zoom) now shows ONCE in this trigger label instead, via the
// gridZoom the grid reports through the viewport composable.
describe('ViewportToolbar — grid-mode shared scale readout', () => {
    it('appends the shared scale percentage to the trigger label when gridZoom < 1 in grid mode', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            onViewport: (vp) => { viewport = vp; },
        });
        viewport.setPreset('mobile');
        viewport.setGridZoom(0.84);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="viewport-trigger"]').text()).toContain('375 × 667 (84 %)');
    });

    it('shows dimensions alone, with no percentage, when gridZoom is exactly 1 in grid mode', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            onViewport: (vp) => { viewport = vp; },
        });
        viewport.setPreset('mobile');
        viewport.setGridZoom(1);
        await wrapper.vm.$nextTick();
        const text = wrapper.find('[data-testid="viewport-trigger"]').text();
        expect(text).toContain('375 × 667');
        expect(text).not.toContain('%');
    });

    it('falls back to the classic single-preview zoom once gridZoom is reset to null (grid deactivation)', async () => {
        let viewport;
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', label: 'Secondary style' }],
            }],
            onViewport: (vp) => { viewport = vp; },
        });
        viewport.setPreset('mobile');
        viewport.setGridZoom(0.5);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="viewport-trigger"]').text()).toContain('(50 %)');

        viewport.setGridZoom(null);
        await wrapper.vm.$nextTick();
        // No container ever measured in this toolbar-only mount, so the
        // classic zoom (fitZoom with availWidth 0) is capped at 1 -- dims
        // alone, no percentage.
        const text = wrapper.find('[data-testid="viewport-trigger"]').text();
        expect(text).toContain('375 × 667');
        expect(text).not.toContain('%');
    });
});

// Breadcrumb-based variant isolation (styleguide 2.0 UX redesign, replaces
// the earlier "← All" toolbar back control): the trailing Variant segment
// only appears once a specific `?variant=` isolates the classic single
// preview, and the component-name crumb itself becomes the "go back to the
// grid" affordance in that state -- standard breadcrumb semantics, not a
// separate button.
describe('ViewportToolbar — breadcrumb variant segment', () => {
    it('renders a plain, non-interactive item-name crumb with no Variant segment in grid mode (no variant selected)', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
        });
        const crumb = wrapper.find('[data-testid="breadcrumb-item-name"]');
        expect(crumb.exists()).toBe(true);
        expect(crumb.element.tagName).toBe('SPAN');
        expect(wrapper.find('[data-testid="breadcrumb-variant"]').exists()).toBe(false);
    });

    it('turns the item-name crumb into a button and appends the Variant segment once a specific variant is deep-linked', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            variant: ref('secondary'),
        });
        const crumb = wrapper.find('[data-testid="breadcrumb-item-name"]');
        expect(crumb.element.tagName).toBe('BUTTON');
        expect(wrapper.find('[data-testid="breadcrumb-variant"]').text()).toBe('Secondary style');
    });

    it('clicking the item-name crumb calls setVariant(null), returning to the grid', async () => {
        let capturedId = 'not-called';
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', label: 'Secondary style' }] }],
            variant: ref('secondary'),
            setVariant: (id) => { capturedId = id; },
        });
        await wrapper.find('[data-testid="breadcrumb-item-name"]').trigger('click');
        expect(capturedId).toBeNull();
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
