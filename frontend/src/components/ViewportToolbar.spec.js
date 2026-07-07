import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';

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
    };
    useCatalogStore().items = items ?? [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant, setVariant });
            // Hands the composable instance back to the caller — optional,
            // so every pre-existing call site above is unaffected.
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
// single-preview-only width controls. No `[data-testid="variant-switcher"]`
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
            variant: ref('secondary'),
        });
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(true);
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
        });
        expect(wrapper.find('[data-testid="iframe-theme-toggle"]').exists()).toBe(true);
    });

    // Styleguide 2.0 UX fix: the five density options used to be a
    // segmented pill row; they're now a dropdown sharing the viewport
    // trigger's own pill/icon/label/chevron shape and open/close mechanics.
    it('renders the density dropdown trigger only when the grid is active', () => {
        const gridWrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
        });
        expect(gridWrapper.find('[data-testid="variant-columns-trigger"]').exists()).toBe(true);

        const noVariantsWrapper = mountWithViewport('component', 'hero');
        expect(noVariantsWrapper.find('[data-testid="variant-columns-trigger"]').exists()).toBe(false);

        const isolatedWrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
            variant: ref('secondary'),
        });
        expect(isolatedWrapper.find('[data-testid="variant-columns-trigger"]').exists()).toBe(false);
    });

    it('trigger reads "Auto" by default; opening it lists all five options with Auto highlighted', async () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
        });
        const trigger = wrapper.find('[data-testid="variant-columns-trigger"]');
        expect(trigger.text()).toContain('Auto');

        // Menu is closed by default -- rows exist in the DOM (v-show) but
        // the trigger's own aria-expanded says so.
        expect(trigger.attributes('aria-expanded')).toBe('false');
        await trigger.trigger('click');
        expect(trigger.attributes('aria-expanded')).toBe('true');

        expect(wrapper.find('[data-testid="variant-columns-auto"]').classes()).toContain('text-red-700');
        for (const n of [1, 2, 3, 4]) {
            const row = wrapper.find(`[data-testid="variant-columns-${n}"]`);
            expect(row.text()).toBe(`${n} column${n === 1 ? '' : 's'}`);
            expect(row.classes()).not.toContain('text-red-700');
        }
    });

    it('clicking a density row updates ui.variantColumns, moves the highlight, and updates the trigger label', async () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{
                id: 'multi',
                name: 'Multi',
                category: 'Block',
                variants: [{ id: 'secondary', title: 'Secondary style' }],
            }],
        });
        const ui = useUiStore();
        expect(ui.variantColumns).toBe('auto');

        await wrapper.find('[data-testid="variant-columns-trigger"]').trigger('click');
        await wrapper.find('[data-testid="variant-columns-2"]').trigger('click');
        expect(ui.variantColumns).toBe(2);
        expect(wrapper.find('[data-testid="variant-columns-trigger"]').text()).toContain('2 columns');
        // Menu closes on selection, same as the viewport preset dropdown.
        expect(wrapper.find('[data-testid="variant-columns-trigger"]').attributes('aria-expanded')).toBe('false');

        await wrapper.find('[data-testid="variant-columns-trigger"]').trigger('click');
        expect(wrapper.find('[data-testid="variant-columns-2"]').classes()).toContain('text-red-700');
        expect(wrapper.find('[data-testid="variant-columns-auto"]').classes()).not.toContain('text-red-700');

        await wrapper.find('[data-testid="variant-columns-auto"]').trigger('click');
        expect(ui.variantColumns).toBe('auto');
        expect(wrapper.find('[data-testid="variant-columns-trigger"]').text()).toContain('Auto');
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
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
                variants: [{ id: 'secondary', title: 'Secondary style' }],
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
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', title: 'Secondary style' }] }],
        });
        const crumb = wrapper.find('[data-testid="breadcrumb-item-name"]');
        expect(crumb.exists()).toBe(true);
        expect(crumb.element.tagName).toBe('SPAN');
        expect(wrapper.find('[data-testid="breadcrumb-variant"]').exists()).toBe(false);
    });

    it('turns the item-name crumb into a button and appends the Variant segment once a specific variant is deep-linked', () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', title: 'Secondary style' }] }],
            variant: ref('secondary'),
        });
        const crumb = wrapper.find('[data-testid="breadcrumb-item-name"]');
        expect(crumb.element.tagName).toBe('BUTTON');
        expect(wrapper.find('[data-testid="breadcrumb-variant"]').text()).toBe('Secondary style');
    });

    it('clicking the item-name crumb calls setVariant(null), returning to the grid', async () => {
        let capturedId = 'not-called';
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', title: 'Secondary style' }] }],
            variant: ref('secondary'),
            setVariant: (id) => { capturedId = id; },
        });
        await wrapper.find('[data-testid="breadcrumb-item-name"]').trigger('click');
        expect(capturedId).toBeNull();
    });
});

