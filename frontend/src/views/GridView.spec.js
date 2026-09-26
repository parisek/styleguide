import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import GridView from './GridView.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';

class FakeIntersectionObserver {
    static instances = [];

    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        FakeIntersectionObserver.instances.push(this);
    }

    observe(el) { this.targets.push(el); }

    disconnect() {}

    fire(isIntersecting) {
        this.callback(this.targets.map((target) => ({ target, isIntersecting })));
    }
}

let restoreClientWidth;
function stubClientWidth(px) {
    const original = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, value: px });
    return () => {
        if (original) Object.defineProperty(HTMLElement.prototype, 'clientWidth', original);
        else delete HTMLElement.prototype.clientWidth;
    };
}

async function mountGrid({ items, pages } = {}) {
    setActivePinia(createPinia());
    localStorage.clear();
    const catalog = useCatalogStore();
    catalog.items = items ?? [
        { id: 'button', name: 'Button', category: '', has_styleguide: true },
        { id: 'hero', name: 'Hero', category: 'Block', has_styleguide: true, variants: [{ id: 'dark' }] },
        { id: 'quote', name: 'Quote', category: 'Gutenberg', has_styleguide: true, aliases: [{ name: 'Layout 238', variant: null }] },
        { id: 'hidden', name: 'Hidden', category: 'Block', has_styleguide: false },
    ];
    catalog.pages = pages ?? [{ id: 'home', name: 'Home', has_styleguide: true }];
    catalog.loading = false;
    useI18nStore().strings = {
        grid: { title: 'All previews', subtitle: 'Sub', filter_all: 'All', filter_placeholder: 'Filter…', filter_label: 'Filter', variants: 'Variants', empty: 'Nothing matches.' },
        sections: { basic: 'Basic', blocks: 'Blocks', gutenberg: 'Gutenberg', pages: 'Pages' },
    };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/grid', name: 'grid', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
            { path: '/page/:slug', name: 'page', component: { template: '<div/>' } },
        ],
    });
    await router.push('/grid');
    const wrapper = mount(GridView, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();
    return { wrapper, router, catalog };
}

const tileNames = (wrapper) => wrapper.findAll('[data-testid="grid-tile-link"]').map((a) => a.text());

beforeEach(() => {
    FakeIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
    restoreClientWidth = stubClientWidth(1100);
});

afterEach(() => {
    restoreClientWidth();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('GridView', () => {
    it('shows every renderable component and page, in sidebar section order', async () => {
        const { wrapper } = await mountGrid();
        expect(tileNames(wrapper)).toEqual(['Button', 'Hero', 'Quote', 'Home']);
    });

    it('observes the tiles against its own scrolling element', async () => {
        const { wrapper } = await mountGrid();
        expect(FakeIntersectionObserver.instances[0].options.root).toBe(wrapper.find('[data-testid="grid-view"]').element);
    });

    it('lays the tiles out in as many equal columns as fit', async () => {
        const { wrapper } = await mountGrid();
        expect(wrapper.find('[data-testid="grid-tiles"]').attributes('style')).toContain('repeat(4, minmax(0, 1fr))');
    });

    it('filters by section chip and by text', async () => {
        const { wrapper } = await mountGrid();
        const chips = wrapper.findAll('[data-testid="grid-filter-section"]');
        expect(chips.map((c) => c.text())).toEqual(['All 4', 'Basic 1', 'Blocks 1', 'Gutenberg 1', 'Pages 1']);

        await chips[2].trigger('click');
        expect(tileNames(wrapper)).toEqual(['Hero']);
        expect(chips[2].attributes('aria-pressed')).toBe('true');

        await chips[0].trigger('click');
        await wrapper.find('[data-testid="grid-filter-query"]').setValue('layout 238');
        expect(tileNames(wrapper)).toEqual(['Quote']);

        await wrapper.find('[data-testid="grid-filter-query"]').setValue('nothing-like-this');
        expect(wrapper.find('[data-testid="grid-empty"]').text()).toBe('Nothing matches.');
    });

    it('opens the entry when a tile is clicked, with a real href for a new tab', async () => {
        const { wrapper, router } = await mountGrid();
        const link = wrapper.findAll('[data-testid="grid-tile-link"]')[1];
        expect(link.attributes('href')).toBe('/component/hero');
        await link.trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/component/hero');
    });

    it('builds each tile source like the single preview, with the iframe theme', async () => {
        const { wrapper } = await mountGrid();
        useUiStore().iframeTheme = 'dark';
        FakeIntersectionObserver.instances.forEach((io) => io.fire(true));
        await flushPromises();
        const srcs = wrapper.findAll('iframe').map((f) => f.attributes('src'));
        expect(srcs).toContain('/styleguide/render/component/hero?theme=dark');
        expect(srcs).toContain('/styleguide/render/page/home?theme=dark');
    });

    it('loads at most six previews at once', async () => {
        const items = Array.from({ length: 10 }, (_, i) => ({ id: `c${i}`, name: `C${i}`, category: 'Block', has_styleguide: true }));
        const { wrapper } = await mountGrid({ items, pages: [] });
        FakeIntersectionObserver.instances.forEach((io) => io.fire(true));
        await flushPromises();
        expect(wrapper.findAll('iframe')).toHaveLength(6);

        await wrapper.findAll('iframe')[0].trigger('load');
        expect(wrapper.findAll('iframe')).toHaveLength(7);
    });

    it('shows the variants badge with the tile count, default included', async () => {
        const { wrapper } = await mountGrid();
        const badges = wrapper.findAll('[data-testid="grid-tile-variants"]');
        expect(badges).toHaveLength(1);
        expect(badges[0].text()).toBe('2');
    });
});
