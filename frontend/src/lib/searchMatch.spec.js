import { describe, it, expect } from 'vitest';
import { normalizeForSearch, matchesQuery, filterItems } from './searchMatch.js';

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
