import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref, provide, defineComponent, h } from 'vue';
import UsagePanel from './UsagePanel.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

function mountPanel(type, slug) {
    setActivePinia(createPinia());
    useI18nStore().strings = { usage: { used_in: 'Used in', components_in_page: 'Components in page' } };
    const catalog = useCatalogStore();
    catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: ['hero', 'ghost-id'] }];
    catalog.items = [{ id: 'hero', name: 'Hero', usage: ['homepage'] }];

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div/>' } }] });

    const Host = defineComponent({
        setup() {
            provide('viewport', { currentItem: ref(catalog.find(type, slug)), type: ref(type) });
            return () => h(UsagePanel);
        },
    });
    return mount(Host, { global: { plugins: [router] } });
}

describe('UsagePanel', () => {
    it('shows "Used in" chips for a component route, resolving each usage id against pages/items', () => {
        const wrapper = mountPanel('component', 'hero');
        expect(wrapper.text()).toContain('Used in');
        expect(wrapper.text()).toContain('Homepage');
    });

    it('shows "Components in page" for a page route', () => {
        const wrapper = mountPanel('page', 'homepage');
        expect(wrapper.text()).toContain('Components in page');
        expect(wrapper.text()).toContain('Hero');
    });

    it('renders an unknown usage id as a disabled, greyed-out chip', () => {
        const wrapper = mountPanel('page', 'homepage');
        const ghost = wrapper.findAll('button').find((b) => b.text() === 'ghost-id');
        expect(ghost.attributes('disabled')).toBeDefined();
    });

    it('renders nothing for a route with no usage field', () => {
        const wrapper = mountPanel('component', 'nonexistent');
        expect(wrapper.text()).toBe('');
    });
});
