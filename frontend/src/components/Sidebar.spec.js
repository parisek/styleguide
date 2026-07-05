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

// Sidebar.vue reads the SPA config via readSpaConfig(), which throws if the
// #sg-config script element isn't present in the document — mirror the real
// PHP-injected payload here so every mount() below has something to read.
// Tests that care about specific favicon/projectName values call this again
// with overrides before mounting (removes-then-reappends, so it's safe to
// call more than once per test).
function stubSgConfig(overrides = {}) {
    document.getElementById('sg-config')?.remove();
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify({
        locale: 'cs', projectName: 'Styleguide', favicon: '', title: 'Styleguide', baseUrl: '/styleguide', ...overrides,
    });
    document.body.appendChild(el);
}

async function mountSidebar(initialPath = '/foundations') {
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
    await router.push(initialPath);
    const wrapper = mount(Sidebar, { global: { plugins: [router] } });
    await router.isReady();
    return { wrapper, router };
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), addListener: vi.fn() }));
    localStorage.clear();
    stubSgConfig();
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
        // Target the label span, not the whole button: the button now also
        // renders a sibling count-badge span (e.g. "Basic" + "1"), so
        // button.text() would concatenate both and never equal 'Basic'.
        const toggle = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Basic');
        await toggle.trigger('click');
        await wrapper.vm.$nextTick();
        expect(JSON.parse(localStorage.getItem('sg-sections')).basic).toBe(false);
    });

    it('renders the section header count badge with the filtered item count', async () => {
        const { wrapper } = await mountSidebar();
        // Fixture: 3 items categorised 'Block' -> Blocks section, 1 ('gizmo',
        // uncategorised) -> Basic section.
        const blocksButton = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Blocks');
        const basicButton = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Basic');
        expect(blocksButton.findAll('span')[1].text()).toBe('3');
        expect(basicButton.findAll('span')[1].text()).toBe('1');
    });

    it("wires the header favicon/name from config's favicon/projectName", async () => {
        stubSgConfig({ projectName: 'Acme', favicon: '/f.svg' });
        const { wrapper } = await mountSidebar();
        const favicon = wrapper.find('#sg-favicon');
        expect(favicon.attributes('src')).toBe('/f.svg');
        expect(favicon.attributes('alt')).toBe('Acme');
        expect(wrapper.find('#sg-project-name').text()).toBe('Acme');
    });

    it('marks the Overview nav item active when on /overview', async () => {
        const { wrapper } = await mountSidebar('/overview');
        const overviewLink = wrapper.findAll('a').find((a) => a.text() === 'Overview');
        expect(overviewLink.classes()).toContain('bg-red-600/10');
        expect(overviewLink.classes()).toContain('text-red-700');
    });
});
