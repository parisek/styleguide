import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';

function mountWithViewport(type = 'component', slug = 'hero', { items, variant, setVariant } = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        toolbar: {
            viewport_preset: 'Viewport', custom_width_label: 'Custom', custom_width_placeholder: 'px',
            orientation_label: 'Orientation', type_component: 'Component', type_page: 'Page',
            canvas_mode_label: 'Canvas', open_in_new_tab: 'Open', reload: 'Reload', more_actions: 'More',
            variant_label: 'Variant', variant_default: 'Default',
        },
        sections: { blocks: 'Blocks' },
    };
    useCatalogStore().items = items ?? [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            provide('viewport', useViewportPreset({ type: typeRef, slug: slugRef, variant, setVariant }));
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

    it('renders Default + each discovered variant when variants exist', () => {
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
        expect(labels).toEqual(['Default', 'dark-bg', 'Secondary style']);
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

    it('clicking Default calls viewport.setVariant(null)', async () => {
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
});
