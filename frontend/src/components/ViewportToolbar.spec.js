import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';

function mountWithViewport(type = 'component', slug = 'hero', { items, variant, setVariant, onViewport, projectWidths = null, attach = false } = {}) {
    // The width set persists; one spec's comparison must not leak into the next.
    localStorage.removeItem('sg-preview-compare');
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
            widths_hint: 'Hint', widths_project: 'Project widths', widths_other: 'More',
            widths_word: 'widths', compare_all: 'Compare all', compare_add: 'Add side by side',
            compare_max: 'At most 4', show_only: 'Show only',
        },
        sections: { blocks: 'Blocks' },
    };
    useCatalogStore().items = items ?? [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant, setVariant, projectWidths });
            // Hands the composable instance back to the caller — optional,
            // so every pre-existing call site above is unaffected.
            onViewport?.(viewport);
            provide('viewport', viewport);
            return () => h(ViewportToolbar);
        },
    });
    // Focus moves only in an attached document.
    return mount(Host, attach ? { attachTo: document.body } : {});
}

describe('ViewportToolbar — width checklist', () => {
    async function openMenu(wrapper) {
        await wrapper.get('[data-testid="viewport-trigger"]').trigger('click');
        return wrapper.get('[data-testid="viewport-menu"]');
    }
    const row = (wrapper, id) => wrapper.get(`[data-testid="${id}"]`);
    // Row ids name the pick control (`viewport-preset-tablet`); its box is
    // the same id with `check` (`viewport-check-tablet`).
    const boxOf = (wrapper, id) => wrapper.get(`[data-testid="${id.replace(/^viewport-(preset|width)-/, 'viewport-check-')}"]`);
    const check = async (wrapper, id) => boxOf(wrapper, id).trigger('click');

    it('has no separate compare button any more', () => {
        const wrapper = mountWithViewport('component', 'hero', { projectWidths: [1440, 768, 320] });
        expect(wrapper.find('[data-testid="compare-toggle"]').exists()).toBe(false);
    });

    it('lists the project widths first, then the presets without them', async () => {
        const wrapper = mountWithViewport('component', 'hero', { projectWidths: [1440, 768, 320] });
        const menu = await openMenu(wrapper);
        const ids = menu.findAll('[role="menuitem"][data-testid^="viewport-"]').map((el) => el.attributes('data-testid'));
        expect(ids.slice(0, 3)).toEqual(['viewport-preset-mobile-s', 'viewport-preset-tablet', 'viewport-width-1440']);
        expect(ids.filter((id) => id === 'viewport-preset-tablet')).toHaveLength(1);
        expect(ids.at(-1)).toBe('viewport-preset-full');
        expect(row(wrapper, 'viewport-width-1440').text()).toContain('Desktop');
    });

    it('has no project group without configured widths, but still checkboxes', async () => {
        const wrapper = mountWithViewport();
        const menu = await openMenu(wrapper);
        expect(menu.text()).not.toContain('Project widths');
        expect(menu.find('[data-testid="compare-project-widths"]').exists()).toBe(false);
        expect(boxOf(wrapper, 'viewport-preset-tablet').attributes('role')).toBe('menuitemcheckbox');
        // Full has no pixel width: a choice, never a checkbox.
        expect(wrapper.find('[data-testid="viewport-check-full"]').exists()).toBe(false);
    });

    it('a click on the row shows that width alone and closes the menu', async () => {
        const wrapper = mountWithViewport();
        const menu = await openMenu(wrapper);
        await row(wrapper, 'viewport-preset-tablet').trigger('click');
        expect(useUiStore().previewWidth).toBe('768px');
        expect(boxOf(wrapper, 'viewport-preset-tablet').attributes('aria-checked')).toBe('true');
        expect(menu.isVisible()).toBe(false);
    });

    it('a tick adds a width, keeps the menu open, and the trigger names the set', async () => {
        const wrapper = mountWithViewport();
        const menu = await openMenu(wrapper);
        await row(wrapper, 'viewport-preset-tablet').trigger('click');
        await wrapper.get('[data-testid="viewport-trigger"]').trigger('click');
        await check(wrapper, 'viewport-preset-mobile-s');
        expect(useUiStore().compareWidths).toEqual([320, 768]);
        expect(menu.isVisible()).toBe(true);
        expect(boxOf(wrapper, 'viewport-preset-mobile-s').attributes('aria-checked')).toBe('true');
        expect(wrapper.get('[data-testid="viewport-trigger-word"]').text()).toBe('2 widths');
        expect(wrapper.get('[data-testid="viewport-trigger-dims"]').text()).toBe('320 · 768');
    });

    it('Shift+click on a row adds, as its box does', async () => {
        const wrapper = mountWithViewport();
        await openMenu(wrapper);
        await row(wrapper, 'viewport-preset-desktop').trigger('click');
        await row(wrapper, 'viewport-preset-mobile').trigger('click', { shiftKey: true });
        expect(useUiStore().compareWidths).toEqual([375, 1280]);
    });

    it('names both controls for a screen reader: the row shows only, the box adds', async () => {
        const wrapper = mountWithViewport();
        await openMenu(wrapper);
        expect(row(wrapper, 'viewport-preset-tablet').attributes('role')).toBe('menuitem');
        expect(row(wrapper, 'viewport-preset-tablet').attributes('aria-label')).toBe('Show only Tablet 768');
        expect(boxOf(wrapper, 'viewport-preset-tablet').attributes('aria-label')).toBe('Add side by side: Tablet 768');
    });

    it('keeps the custom field and orientation out of the menu role', async () => {
        const wrapper = mountWithViewport();
        await openMenu(wrapper);
        const menu = wrapper.get('[role="menu"]');
        expect(menu.find('[data-testid="custom-width-input"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="custom-width-input"]').exists()).toBe(true);
    });

    it('arrow keys keep the column between lines and switch it with Left and Right', async () => {
        const wrapper = mountWithViewport('component', 'hero', { attach: true });
        await openMenu(wrapper);
        const menu = wrapper.get('[role="menu"]');
        boxOf(wrapper, 'viewport-preset-mobile-s').element.focus();
        await menu.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(boxOf(wrapper, 'viewport-preset-mobile').element);
        await menu.trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(row(wrapper, 'viewport-preset-mobile').element);
        await menu.trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(row(wrapper, 'viewport-preset-mobile-s').element);
        wrapper.unmount();
    });

    it('Shift+Enter in the custom field adds, and stops at four widths', async () => {
        const wrapper = mountWithViewport();
        useUiStore().setCompareWidths([320, 768, 1280]);
        await openMenu(wrapper);
        const input = wrapper.get('[data-testid="custom-width-input"]');
        await input.setValue(1100);
        await input.trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(useUiStore().compareWidths).toEqual([320, 768, 1100, 1280]);
        await input.setValue(900);
        await input.trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(useUiStore().compareWidths).toEqual([320, 768, 1100, 1280]);
        expect(boxOf(wrapper, 'viewport-preset-mobile').attributes('aria-disabled')).toBe('true');
    });

    it('"Compare all" checks every project width', async () => {
        const wrapper = mountWithViewport('component', 'hero', { projectWidths: [1440, 768, 320] });
        await openMenu(wrapper);
        await wrapper.get('[data-testid="compare-project-widths"]').trigger('click');
        expect(useUiStore().compareWidths).toEqual([320, 768, 1440]);
    });

    it('a checked custom width gets a row of its own, so it can be unchecked', async () => {
        const wrapper = mountWithViewport();
        await openMenu(wrapper);
        await row(wrapper, 'viewport-preset-tablet').trigger('click');
        await wrapper.get('[data-testid="custom-width-input"]').setValue(1100);
        await wrapper.get('[data-testid="custom-width-add"]').trigger('click');
        expect(useUiStore().compareWidths).toEqual([768, 1100]);
        await check(wrapper, 'viewport-width-1100');
        expect(useUiStore().compareWidths).toEqual([]);
        expect(useUiStore().previewWidth).toBe('768px');
    });

    it('stops adding at four widths and says so', async () => {
        const wrapper = mountWithViewport();
        useUiStore().setCompareWidths([320, 768, 1280, 1920]);
        await openMenu(wrapper);
        expect(wrapper.find('[data-testid="compare-max"]').exists()).toBe(true);
        await check(wrapper, 'viewport-preset-mobile');
        expect(useUiStore().compareWidths).toEqual([320, 768, 1280, 1920]);
    });

    it('keeps the width menu while comparing (it is the way out) and hides the tile density', async () => {
        const wrapper = mountWithViewport('component', 'multi', {
            items: [{ id: 'multi', name: 'Multi', category: 'Block', variants: [{ id: 'secondary', title: 'Secondary' }] }],
        });
        expect(wrapper.find('[data-testid="variant-columns-trigger"]').exists()).toBe(true);
        useUiStore().setCompareWidths([1440, 320]);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="variant-columns-trigger"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="iframe-theme-toggle"]').exists()).toBe(true);
    });

    it('names a single project width by its device word, not "Custom"', async () => {
        const wrapper = mountWithViewport('component', 'hero', { projectWidths: [1440, 768, 320] });
        await openMenu(wrapper);
        await row(wrapper, 'viewport-width-1440').trigger('click');
        expect(wrapper.get('[data-testid="viewport-trigger-word"]').text()).toBe('Desktop');
    });
});

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
        // The active option must be announced, not just colored — regression
        // guard for the aria-pressed state lost in the dropdown rebuild.
        expect(wrapper.find('[data-testid="variant-columns-auto"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-testid="variant-columns-2"]').attributes('aria-checked')).toBe('false');
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

describe('ViewportToolbar — overview grid', () => {
    it('titles the grid route and shows no "select a component" prompt or preview actions', () => {
        const wrapper = mountWithViewport('grid', null);
        useI18nStore().strings.nav = { grid: 'Previews' };
        useI18nStore().strings.toolbar.select_prompt = 'Select something';
        return wrapper.vm.$nextTick().then(() => {
            expect(wrapper.text()).toContain('Previews');
            expect(wrapper.text()).not.toContain('Select something');
            expect(wrapper.find('[title="Reload"]').exists()).toBe(false);
        });
    });
});
