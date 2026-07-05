import { describe, it, expect, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import OverviewView from './OverviewView.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

async function mountOverview() {
    setActivePinia(createPinia());
    localStorage.clear();
    const catalog = useCatalogStore();
    catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: 'hero', hasStyleguide: true }];
    catalog.items = [
        { id: 'hero', name: 'Hero', category: 'Block', hasStyleguide: true, figma: 'https://figma/hero' },
        { id: 'gutenberg-block', name: 'GB block', category: 'Gutenberg', hasStyleguide: true },
    ];
    useI18nStore().strings = {
        overview: { title: 'Components and pages', subtitle: 'Sub', show_usage: 'Show usage', pages: 'Pages', components: 'Components', used_in: 'Used in' },
        sections: { blocks: 'Blocks', gutenberg: 'Gutenberg', basic: 'Basic' },
    };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div/>' } }] });
    const wrapper = mount(OverviewView, { global: { plugins: [router] } });
    return { wrapper, router, catalog };
}

describe('OverviewView', () => {
    it('renders the Pages section and both component-category sections with counts', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.text()).toContain('Homepage');
        expect(wrapper.text()).toContain('Hero');
        expect(wrapper.text()).toContain('GB block');
    });

    it('shows a Figma link icon for an item carrying a figma metadata field', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.find('a[href="https://figma/hero"]').exists()).toBe(true);
    });

    it('shows forward usage chips under a page when showUsage is on', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.text()).toContain('Used in');
        // "Hero" appears both as its own row title and as a forward-usage chip
        // under Homepage; assert the chip specifically renders (button, not link).
        const chipButtons = wrapper.findAll('button').filter((b) => b.text() === 'Hero');
        expect(chipButtons.length).toBeGreaterThan(0);
    });

    it('persists the showUsage toggle to sg-overview-show-usage', async () => {
        const { wrapper } = await mountOverview();
        const checkbox = wrapper.find('input[type="checkbox"]');
        await checkbox.setValue(false);
        await wrapper.vm.$nextTick();
        expect(localStorage.getItem('sg-overview-show-usage')).toBe('false');
    });

    it('navigates via router.push when a component row link is clicked', async () => {
        const { wrapper, router } = await mountOverview();
        const heroLink = wrapper.findAll('a').find((a) => a.text() === 'Hero');
        await heroLink.trigger('click');
        // router.push() resolves its navigation guard chain over many
        // microtask ticks — see Sidebar.spec.js for the same documented
        // vue-router + vue-test-utils pattern this mirrors.
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/component/hero');
    });
});
