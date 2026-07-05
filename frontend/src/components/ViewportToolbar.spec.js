import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountWithViewport(type = 'component', slug = 'hero') {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        toolbar: {
            viewport_preset: 'Viewport', custom_width_label: 'Custom', custom_width_placeholder: 'px',
            orientation_label: 'Orientation', type_component: 'Component', type_page: 'Page',
            canvas_mode_label: 'Canvas', open_in_new_tab: 'Open', reload: 'Reload', more_actions: 'More',
        },
        sections: { blocks: 'Blocks' },
    };
    useCatalogStore().items = [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            provide('viewport', useViewportPreset({ type: typeRef, slug: slugRef }));
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
});
