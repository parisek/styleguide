import { describe, it, expect } from 'vitest';
import {
    gridEntries, filterGridEntries, sectionCounts, gridLayout, gridWidthOptions, resolveGridWidth, gridPreviewSize, GRID_SECTIONS,
} from './catalogGrid.js';

// The catalog store's rule, reduced to what these fixtures need.
const sectionOf = (item) => {
    const cat = (item.category ?? '').toLowerCase();
    if (cat === 'gutenberg') return 'gutenberg';
    if (['block', 'blocks', 'layout'].includes(cat)) return 'blocks';
    return 'basic';
};

const catalog = {
    items: [
        { id: 'hero', name: 'Hero', category: 'Block', variants: [{ id: 'dark' }, { id: 'wide' }] },
        { id: 'button', name: 'Button', category: '', variants: [] },
        { id: 'hidden', name: 'Hidden', category: 'Block', has_styleguide: false },
        { id: 'named', name: 'Named only', category: 'Block', has_default_variant: false, variants: [{ id: 'second' }, { id: 'first' }] },
        { id: 'quote', name: 'Quote', category: 'Gutenberg', aliases: [{ name: 'Layout 238', variant: null }] },
    ],
    pages: [
        { id: 'home', name: 'Home' },
        { id: 'draft', name: 'Draft', has_styleguide: false },
    ],
};

describe('gridEntries', () => {
    it('lists renderable components and pages in sidebar section order, server order inside', () => {
        const entries = gridEntries(catalog, sectionOf);
        expect(entries.map((e) => e.key)).toEqual([
            'component:button', 'component:hero', 'component:named', 'component:quote', 'page:home',
        ]);
        expect(entries.map((e) => e.section)).toEqual(['basic', 'blocks', 'blocks', 'gutenberg', 'pages']);
    });

    it('counts every variant tile, the default included, and 0 without variants', () => {
        const byId = Object.fromEntries(gridEntries(catalog, sectionOf).map((e) => [e.id, e]));
        expect(byId.hero.variantCount).toBe(3);
        expect(byId.named.variantCount).toBe(2);
        expect(byId.button.variantCount).toBe(0);
    });

    it('previews the default fixture, or the first variant when there is no default', () => {
        const byId = Object.fromEntries(gridEntries(catalog, sectionOf).map((e) => [e.id, e]));
        expect(byId.hero.variant).toBeNull();
        expect(byId.named.variant).toBe('second');
        expect(byId.home.variant).toBeNull();
    });

    it('tolerates a catalogue that has not loaded', () => {
        expect(gridEntries({}, sectionOf)).toEqual([]);
    });
});

describe('filterGridEntries', () => {
    const entries = gridEntries(catalog, sectionOf);

    it('keeps everything with no filter', () => {
        expect(filterGridEntries(entries)).toHaveLength(entries.length);
    });

    it('narrows by section', () => {
        expect(filterGridEntries(entries, { section: 'blocks' }).map((e) => e.id)).toEqual(['hero', 'named']);
    });

    it('matches name, id and aliases like the sidebar filter, diacritics-insensitive', () => {
        expect(filterGridEntries(entries, { query: 'HER' }).map((e) => e.id)).toEqual(['hero']);
        expect(filterGridEntries(entries, { query: 'layout 238' }).map((e) => e.id)).toEqual(['quote']);
        expect(filterGridEntries(entries, { section: 'pages', query: 'hero' })).toEqual([]);
    });
});

describe('sectionCounts', () => {
    it('gives one count per non-empty section, in sidebar order', () => {
        expect(sectionCounts(gridEntries(catalog, sectionOf))).toEqual([
            { section: 'basic', count: 1 },
            { section: 'blocks', count: 2 },
            { section: 'gutenberg', count: 1 },
            { section: 'pages', count: 1 },
        ]);
        expect(GRID_SECTIONS).toEqual(['basic', 'blocks', 'gutenberg', 'pages']);
    });
});

describe('gridLayout', () => {
    it('fits as many columns of the minimum width as the container allows', () => {
        expect(gridLayout(1100, { minTile: 260, gap: 16 })).toEqual({ columns: 4, tileWidth: (1100 - 48) / 4 });
        expect(gridLayout(551, { minTile: 260, gap: 16 }).columns).toBe(2);
        expect(gridLayout(535, { minTile: 260, gap: 16 }).columns).toBe(1);
    });

    it('keeps one column on a narrow or unmeasured container', () => {
        expect(gridLayout(200).columns).toBe(1);
        expect(gridLayout(200).tileWidth).toBe(200);
        expect(gridLayout(0)).toEqual({ columns: 1, tileWidth: 0 });
        expect(gridLayout(Number.NaN)).toEqual({ columns: 1, tileWidth: 0 });
    });
});

describe('a custom section order', () => {
    it('orders the entries and the chips by the order it is given', () => {
        const byKind = (item) => ({ block: 'blocks', section: 'sections' }[item.kind] ?? 'basic');
        const catalog = { items: [
            { id: 'button', kind: 'element' },
            { id: 'footer', kind: 'section' },
            { id: 'hero', kind: 'block' },
        ], pages: [] };
        const order = ['blocks', 'sections', 'basic', 'pages'];
        const entries = gridEntries(catalog, byKind, order);
        expect(entries.map((e) => e.id)).toEqual(['hero', 'footer', 'button']);
        expect(sectionCounts(entries, order).map((c) => c.section)).toEqual(['blocks', 'sections', 'basic']);
    });
});

describe('gridWidthOptions', () => {
    it('takes the project widths, smallest first, without duplicates', () => {
        expect(gridWidthOptions([1440, 768, 320, 768])).toEqual([320, 768, 1440]);
    });

    it('falls back to phone, tablet and desktop when the project sets none', () => {
        expect(gridWidthOptions(null)).toEqual([375, 768, 1280]);
        expect(gridWidthOptions([])).toEqual([375, 768, 1280]);
    });
});

describe('resolveGridWidth', () => {
    it('keeps a stored width the options offer, else the widest', () => {
        expect(resolveGridWidth(768, [320, 768, 1440])).toBe(768);
        expect(resolveGridWidth(null, [320, 768, 1440])).toBe(1440);
        expect(resolveGridWidth(1000, [320, 768, 1440])).toBe(1440);
    });
});

describe('gridPreviewSize', () => {
    it('is 16:10 from desktop width up and square below', () => {
        expect(gridPreviewSize(1280)).toEqual({ width: 1280, height: 800 });
        expect(gridPreviewSize(1440)).toEqual({ width: 1440, height: 900 });
        expect(gridPreviewSize(768)).toEqual({ width: 768, height: 768 });
        expect(gridPreviewSize(320)).toEqual({ width: 320, height: 320 });
    });
});
