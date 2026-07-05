import { describe, it, expect } from 'vitest';
import { normalizeForSearch, matchesQuery, filterItems, scoreEntry } from './searchMatch.js';

describe('normalizeForSearch', () => {
    it('folds Czech diacritics and lowercases', () => {
        expect(normalizeForSearch('Drobečková navigace')).toBe('drobeckova navigace');
    });

    it('returns empty string for null/undefined', () => {
        expect(normalizeForSearch(null)).toBe('');
        expect(normalizeForSearch(undefined)).toBe('');
    });
});

describe('matchesQuery', () => {
    it('matches a diacritics-free query against a diacritics-bearing name', () => {
        expect(matchesQuery({ id: 'header', name: 'Hlavička' }, 'hlavicka')).toBe(true);
    });

    it('matches against id when name does not match', () => {
        expect(matchesQuery({ id: 'cta-block', name: 'Výzva k akci' }, 'cta')).toBe(true);
    });

    it('is case-insensitive', () => {
        expect(matchesQuery({ id: 'hero', name: 'Hero' }, 'HERO')).toBe(true);
    });

    it('returns true for an empty/whitespace-only query (no filter)', () => {
        expect(matchesQuery({ id: 'x', name: 'X' }, '   ')).toBe(true);
    });

    it('returns false for a non-matching query', () => {
        expect(matchesQuery({ id: 'hero', name: 'Hero' }, 'footer')).toBe(false);
    });
});

describe('filterItems', () => {
    it('filters a list down to matching items only', () => {
        const items = [
            { id: 'hero', name: 'Hero' },
            { id: 'footer', name: 'Footer' },
            { id: 'header', name: 'Hlavička' },
        ];
        expect(filterItems(items, 'hlavicka').map((i) => i.id)).toEqual(['header']);
    });
});

// scoreEntry() is additive (Task 5, command palette) — normalizeForSearch/
// matchesQuery/filterItems above are untouched and covered by the describes
// above unchanged.
describe('scoreEntry', () => {
    const entry = (overrides) => ({
        id: 'hlavicka-sticky',
        name: 'Hlavička - sticky',
        category: 'Blocks',
        description: '<p>Sticky <strong>header</strong> variant</p>',
        ...overrides,
    });

    it('returns 0 when no field matches', () => {
        expect(scoreEntry('zzz', entry())).toBe(0);
    });

    it('matches diacritic-folded query against name (accent-insensitive)', () => {
        expect(scoreEntry('hlavicka', entry())).toBeGreaterThan(0);
    });

    it('weighs name matches higher than description matches', () => {
        const nameHit = scoreEntry('hlavička', entry());
        const descOnly = scoreEntry('header', entry({ name: 'Something else', id: 'x' }));
        expect(nameHit).toBeGreaterThan(descOnly);
    });

    it('strips HTML before matching description', () => {
        expect(scoreEntry('strong', entry({ name: 'X', id: 'x' }))).toBe(0); // the TAG text itself isn't content
        expect(scoreEntry('header', entry({ name: 'X', id: 'x' }))).toBeGreaterThan(0);
    });

    it('ranks an exact field match above a prefix match above a substring match', () => {
        const exact = scoreEntry('hlavička - sticky', entry());
        const prefix = scoreEntry('hlavička', entry());
        const substring = scoreEntry('sticky', entry());
        expect(exact).toBeGreaterThan(prefix);
        expect(prefix).toBeGreaterThan(substring);
    });

    it('matches against id as well as name', () => {
        expect(scoreEntry('hlavicka-sticky', entry({ name: 'Different display name' }))).toBeGreaterThan(0);
    });

    it('empty query matches nothing meaningfully (score 0, caller decides display)', () => {
        expect(scoreEntry('', entry())).toBe(0);
    });
});
