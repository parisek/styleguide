import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import Sidebar from './Sidebar.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
            { path: '/overview', name: 'overview', component: { template: '<div/>' } },
            { path: '/foundations', name: 'foundations', component: { template: '<div/>' } },
        ],
    });
}

async function mountSidebar() {
    setActivePinia(createPinia());
    const catalog = useCatalogStore();
    catalog.items = [
        { id: 'widget-one', name: 'Widget - one', category: 'Block', hasStyleguide: true },
        { id: 'widget-two', name: 'Widget - two', category: 'Block', hasStyleguide: true },
        { id: 'widget-three', name: 'Widget - three', category: 'Block', hasStyleguide: true },
        { id: 'gizmo', name: 'Gizmo', category: '', hasStyleguide: true },
    ];
    catalog.pages = [{ id: 'homepage', name: 'Homepage', hasStyleguide: true }];
    catalog.docs = [];
    catalog.loading = false;
    useI18nStore().strings = { nav: { docs: 'Docs', overview: 'Overview', foundations: 'Foundations', styleguide: 'Styleguide' }, sections: { basic: 'Basic', blocks: 'Blocks', gutenberg: 'Gutenberg', pages: 'Pages' }, search: { label: 'Search', placeholder: 'Search...' } };

    const router = makeRouter();
    await router.push('/foundations');
    const wrapper = mount(Sidebar, { global: { plugins: [router] } });
    await router.isReady();
    return { wrapper, router };
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), addListener: vi.fn() }));
    localStorage.clear();
});

describe('Sidebar', () => {
    it('renders a Widget group for a >=3 prefix cluster with suffix-only children', async () => {
        const { wrapper } = await mountSidebar();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget');
        expect(wrapper.text()).toContain('One');
        expect(wrapper.text()).not.toContain('Widget - one');
    });

    it('renders the Gizmo singleton flat with its full name', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.text()).toContain('Gizmo');
    });

    it('navigates via router.push when a component link is clicked, then closes the sidebar on mobile', async () => {
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), addListener: vi.fn() }));
        const { wrapper, router } = await mountSidebar();
        const ui = useUiStore();
        ui.sidebarOpen = true;
        const gizmoLink = wrapper.findAll('a').find((a) => a.text() === 'Gizmo');
        await gizmoLink.trigger('click');
        // router.push() resolves its navigation guard chain over many
        // microtask ticks — a single trigger()-awaited Vue.nextTick() is not
        // enough to observe the completed navigation. flushPromises() (a
        // macrotask wait) lets that chain fully drain first; this is the
        // documented vue-router + vue-test-utils pattern for asserting on
        // router.currentRoute after a programmatic push.
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/component/gizmo');
        expect(ui.sidebarOpen).toBe(false);
    });

    it('flattens the Widget group to full names while a search query is active', async () => {
        const { wrapper } = await mountSidebar();
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget - one');
    });

    it('toggleSection persists to sg-sections', async () => {
        const { wrapper } = await mountSidebar();
        const toggle = wrapper.findAll('button').find((b) => b.text() === 'Basic');
        await toggle.trigger('click');
        await wrapper.vm.$nextTick();
        expect(JSON.parse(localStorage.getItem('sg-sections')).basic).toBe(false);
    });
});
