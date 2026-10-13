import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCatalogStore } from './catalog.js';

function jsonResponse(body) {
    return Promise.resolve({ json: async () => body });
}

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('useCatalogStore', () => {
    it('init() fetches components/pages/docs in parallel and flips loading off', async () => {
        global.fetch = vi.fn((url) => {
            if (url.endsWith('/api/components')) return jsonResponse([{ id: 'hero', name: 'Hero', category: 'Block' }]);
            if (url.endsWith('/api/pages')) return jsonResponse([{ id: 'homepage', name: 'Homepage', usage: ['hero'] }]);
            if (url.endsWith('/api/docs')) return jsonResponse([]);
            if (url.endsWith('/api/health')) return jsonResponse({ warnings: [], counts: {} });
            throw new Error(`unexpected fetch ${url}`);
        });
        const catalog = useCatalogStore();
        expect(catalog.loading).toBe(true);
        await catalog.init();
        expect(catalog.loading).toBe(false);
        expect(catalog.items).toHaveLength(1);
        expect(catalog.pages).toHaveLength(1);
    });

    it('init() flips loading off even when a fetch rejects', async () => {
        global.fetch = vi.fn().mockRejectedValue(new Error('network down'));
        const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const catalog = useCatalogStore();
        await catalog.init();
        expect(catalog.loading).toBe(false);
        errSpy.mockRestore();
    });

    it('init() populates warnings from /api/health without blocking the main catalogue', async () => {
        global.fetch = vi.fn((url) => {
            if (url.endsWith('/api/components')) return jsonResponse([]);
            if (url.endsWith('/api/pages')) return jsonResponse([]);
            if (url.endsWith('/api/docs')) return jsonResponse([]);
            if (url.endsWith('/api/health')) {
                return jsonResponse({ warnings: [{ file: 'component/broken/broken.twig', error: 'boom' }], counts: {} });
            }
            throw new Error(`unexpected fetch ${url}`);
        });
        const catalog = useCatalogStore();
        await catalog.init();
        expect(catalog.warnings).toEqual([{ file: 'component/broken/broken.twig', error: 'boom' }]);
    });

    it('init() leaves warnings empty (not throwing) when the health fetch fails', async () => {
        global.fetch = vi.fn((url) => {
            if (url.endsWith('/api/health')) return Promise.reject(new Error('health down'));
            return jsonResponse([]);
        });
        const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const catalog = useCatalogStore();
        await catalog.init();
        expect(catalog.warnings).toEqual([]);
        expect(catalog.loading).toBe(false);
        errSpy.mockRestore();
    });

    it('sectionOf buckets gutenberg/block/layout/unknown categories and pins pages', () => {
        const catalog = useCatalogStore();
        expect(catalog.sectionOf({ category: 'Gutenberg' }, 'component')).toBe('gutenberg');
        expect(catalog.sectionOf({ category: 'Block' }, 'component')).toBe('blocks');
        expect(catalog.sectionOf({ category: 'Layout' }, 'component')).toBe('blocks');
        expect(catalog.sectionOf({ category: 'Whatever' }, 'component')).toBe('basic');
        expect(catalog.sectionOf({ category: '' }, 'component')).toBe('basic');
        expect(catalog.sectionOf({}, 'page')).toBe('pages');
    });

    it('with componentsGroupBy kind, sections come from kind', () => {
        const catalog = useCatalogStore();
        catalog.componentsGroupBy = 'kind';
        catalog.items = [
            { id: 'hero', name: 'Hero', kind: 'block', category: 'Hero' },
            { id: 'cart', name: 'Cart', kind: 'block', category: 'Ecommerce' },
            { id: 'checkout', name: 'Checkout', kind: 'block', category: 'Ecommerce' },
            { id: 'footer', name: 'Footer', kind: 'section', category: 'Footer' },
            { id: 'button', name: 'Button', kind: 'element', category: 'Basic' },
        ];
        expect(catalog.componentSectionKeys).toEqual(['basic', 'parts', 'blocks', 'gutenberg', 'sections', 'utilities']);
        expect(catalog.bySection('blocks').map((c) => c.id)).toEqual(['hero', 'cart', 'checkout']);
        expect(catalog.bySection('sections').map((c) => c.id)).toEqual(['footer']);
        expect(catalog.sectionOf({}, 'page')).toBe('pages');
    });

    it('without componentsGroupBy the section keys stay the legacy ones', () => {
        const catalog = useCatalogStore();
        expect(catalog.componentsGroupBy).toBeNull();
        expect(catalog.componentSectionKeys).toEqual(['basic', 'blocks', 'gutenberg']);
        expect(catalog.sectionOf({ kind: 'block', category: 'Hero' })).toBe('basic');
    });

    it('bySection excludes has_styleguide:false skeleton templates', () => {
        const catalog = useCatalogStore();
        catalog.items = [
            { id: 'a', category: 'Block', has_styleguide: true },
            { id: 'b', category: 'Block', has_styleguide: false },
        ];
        expect(catalog.bySection('blocks').map((i) => i.id)).toEqual(['a']);
    });

    it('find() looks up by id in the type-appropriate list', () => {
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero' }];
        catalog.pages = [{ id: 'homepage', name: 'Homepage' }];
        catalog.docs = [{ id: 'sample-doc', name: 'Sample doc' }];
        expect(catalog.find('component', 'hero')?.name).toBe('Hero');
        expect(catalog.find('page', 'homepage')?.name).toBe('Homepage');
        expect(catalog.find('doc', 'sample-doc')?.name).toBe('Sample doc');
        expect(catalog.find('component', 'missing')).toBeNull();
    });

    it('reverseUsageFor inverts page.usage arrays into a component -> [pages] map', () => {
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: ['hero', 'footer'] }];
        catalog.items = [{ id: 'hero', name: 'Hero' }, { id: 'footer', name: 'Footer' }];
        expect(catalog.reverseUsageFor('hero')).toEqual([
            expect.objectContaining({ id: 'homepage', type: 'page', name: 'Homepage' }),
        ]);
        expect(catalog.reverseUsageFor('nonexistent')).toEqual([]);
    });

    it('forwardUsageFor resolves a page.usage array into named+typed chips, greying out unknown ids', () => {
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: ['hero', 'ghost-id'] }];
        catalog.items = [{ id: 'hero', name: 'Hero' }];
        const chips = catalog.forwardUsageFor(catalog.pages[0]);
        expect(chips).toEqual([
            expect.objectContaining({ id: 'hero', type: 'component', name: 'Hero' }),
            expect.objectContaining({ id: 'ghost-id', type: null, name: 'ghost-id' }),
        ]);
    });

    describe('components.sections', () => {
        const sections = [
            { id: 'basic' },
            { id: 'eshop', label: { cs: 'E-shop komponenty', en: 'E-shop components' } },
            { id: 'internal', hidden: true, fallback: true },
        ];
        const items = [
            { id: 'button', name: 'Button', section: 'basic' },
            { id: 'cart', name: 'Cart', section: 'eshop' },
            { id: 'debug-grid', name: 'Debug grid', section: 'internal' },
            { id: 'skeleton', name: 'Skeleton', section: 'basic', has_styleguide: false },
        ];

        function store() {
            const catalog = useCatalogStore();
            catalog.componentsSections = sections;
            catalog.items = items;
            catalog.pages = [{ id: 'home', name: 'Home', usage: ['cart', 'debug-grid'] }];
            return catalog;
        }

        it('takes the order and the section of each item from the payload', () => {
            const catalog = store();
            expect(catalog.componentSectionKeys).toEqual(['basic', 'eshop']);
            expect(catalog.sectionOf(items[1])).toBe('eshop');
        });

        it('keeps hidden components out of the lists but not out of find()', () => {
            const catalog = store();
            expect(catalog.listedItems.map((c) => c.id)).toEqual(['button', 'cart']);
            expect(catalog.hiddenCount).toBe(1);
            expect(catalog.bySection('internal')).toEqual([]);
            expect(catalog.find('component', 'debug-grid')).not.toBeNull();
        });

        it('showHidden lists them again and keeps their section', () => {
            const catalog = store();
            catalog.showHidden = true;
            expect(catalog.listedItems.map((c) => c.id)).toContain('debug-grid');
            expect(catalog.componentSectionKeys).toEqual(['basic', 'eshop', 'internal']);
            expect(catalog.isSectionHidden('internal')).toBe(true);
        });

        it('a usage chip of a hidden component is a name without a link', () => {
            const catalog = store();
            const chips = catalog.forwardUsageFor('home');
            expect(chips.find((c) => c.id === 'cart').type).toBe('component');
            expect(chips.find((c) => c.id === 'debug-grid')).toMatchObject({ type: null, name: 'Debug grid' });
        });

        it('sectionLabel: the configured label first, the SPA locale for a built-in id', async () => {
            const catalog = store();
            const { useI18nStore } = await import('./i18n.js');
            const i18n = useI18nStore();
            i18n.locale = 'cs';
            i18n.strings = { sections: { basic: 'Základní prvky' } };
            expect(catalog.sectionLabel('eshop')).toBe('E-shop komponenty');
            expect(catalog.sectionLabel('basic')).toBe('Základní prvky');
            i18n.locale = 'en';
            expect(catalog.sectionLabel('eshop')).toBe('E-shop components');
        });

        it('without the key nothing changes', () => {
            const catalog = useCatalogStore();
            catalog.items = items;
            expect(catalog.componentsSections).toBeNull();
            expect(catalog.isHidden(items[2])).toBe(false);
            expect(catalog.listedItems.map((c) => c.id)).toEqual(['button', 'cart', 'debug-grid']);
        });
    });
});
