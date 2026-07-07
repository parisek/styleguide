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

    it('bySection excludes has_styleguide:false skeleton templates', () => {
        const catalog = useCatalogStore();
        catalog.items = [
            { id: 'a', category: 'Block', has_styleguide: true },
            { id: 'b', category: 'Block', has_styleguide: false },
        ];
        expect(catalog.bySection('blocks').map((i) => i.id)).toEqual(['a']);
    });

    it('treeOf delegates to the prefix-tree lib for a section', () => {
        const catalog = useCatalogStore();
        catalog.items = [
            { id: 'widget-one', name: 'Widget - one', category: 'Block' },
            { id: 'widget-two', name: 'Widget - two', category: 'Block' },
            { id: 'widget-three', name: 'Widget - three', category: 'Block' },
        ];
        expect(catalog.treeOf('blocks')).toEqual([
            { type: 'group', label: 'Widget', sortKey: 'Widget', children: expect.any(Array) },
        ]);
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
});
