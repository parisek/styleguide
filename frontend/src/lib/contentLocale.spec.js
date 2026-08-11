import { describe, it, expect, beforeEach } from 'vitest';
import {
    STORAGE_KEY, readStoredLocale, writeStoredLocale, clearStoredLocale, resolveContentLocale,
} from './contentLocale.js';

beforeEach(() => {
    localStorage.clear();
});

describe('contentLocale storage helpers', () => {
    it('readStoredLocale returns null when nothing was ever written', () => {
        expect(readStoredLocale()).toBeNull();
    });

    it('writeStoredLocale/readStoredLocale round-trip a plain string under the namespaced key', () => {
        writeStoredLocale('cs');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('cs');
        expect(readStoredLocale()).toBe('cs');
    });

    it('clearStoredLocale removes the key', () => {
        writeStoredLocale('cs');
        clearStoredLocale();
        expect(readStoredLocale()).toBeNull();
    });
});

describe('resolveContentLocale', () => {
    const isKnown = (loc) => ['cs', 'en'].includes(loc);

    it('an explicit URL locale wins outright, even over a stored value', () => {
        const result = resolveContentLocale({
            urlLocale: 'en', storedLocale: 'cs', defaultLocale: 'cs', isKnown,
        });
        expect(result).toEqual({ locale: 'en', clearStale: false });
    });

    it('a stored value is used when the URL carries no explicit locale', () => {
        const result = resolveContentLocale({
            urlLocale: null, storedLocale: 'en', defaultLocale: 'cs', isKnown,
        });
        expect(result).toEqual({ locale: 'en', clearStale: false });
    });

    it('falls back to the YAML default when neither URL nor storage supplies a locale', () => {
        const result = resolveContentLocale({
            urlLocale: null, storedLocale: null, defaultLocale: 'cs', isKnown,
        });
        expect(result).toEqual({ locale: 'cs', clearStale: false });
    });

    it('a stale stored locale (catalogue no longer offered) falls back to the default and signals clearStale', () => {
        const result = resolveContentLocale({
            urlLocale: null, storedLocale: 'de', defaultLocale: 'cs', isKnown,
        });
        expect(result).toEqual({ locale: 'cs', clearStale: true });
    });

    it('does not signal clearStale when the URL wins, even if the stored value is also stale', () => {
        const result = resolveContentLocale({
            urlLocale: 'en', storedLocale: 'de', defaultLocale: 'cs', isKnown,
        });
        expect(result).toEqual({ locale: 'en', clearStale: false });
    });
});
