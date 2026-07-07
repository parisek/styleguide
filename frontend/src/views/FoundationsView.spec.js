import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import FoundationsView from './FoundationsView.vue';
import { useUiStore } from '../stores/ui.js';

function mountView() {
    setActivePinia(createPinia());
    const Host = defineComponent({
        setup() {
            provide('viewport', { iframeSrc: ref('/styleguide/render/foundations/index') });
            return () => h(FoundationsView);
        },
    });
    return mount(Host);
}

describe('FoundationsView', () => {
    it('renders an iframe pointed at the foundations render endpoint', () => {
        const wrapper = mountView();
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/foundations/index');
    });

    it('shows the loading overlay while ui.isPreviewLoading is true, hides it on iframe load', async () => {
        const wrapper = mountView();
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="foundations-loading"]').isVisible()).toBe(true);
        await wrapper.find('iframe').trigger('load');
        expect(ui.isPreviewLoading).toBe(false);
    });
});
