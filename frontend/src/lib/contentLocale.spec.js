import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
    STORAGE_KEY, readStoredLocale, writeStoredLocale, clearStoredLocale, resolveContentLocale, readDiscoveredLocales,
} from './contentLocale.js';

const LEGACY_STORAGE_KEY = 'styleguide:locale';

beforeEach(() => {
    localStorage.clear();
});

describe('contentLocale storage helpers', () => {
    it('uses the single shared sg-locale key (collapsed from the old split sg-locale/styleguide:locale pair)', () => {
        expect(STORAGE_KEY).toBe('sg-locale');
    });

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

    it('migrates a value from the retired styleguide:locale key on first read, then removes it', () => {
        localStorage.setItem(LEGACY_STORAGE_KEY, 'sk_SK');
        expect(readStoredLocale()).toBe('sk_SK');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('sk_SK');
        expect(localStorage.getItem(LEGACY_STORAGE_KEY)).toBeNull();
    });

    it('does not let a stale legacy value clobber a value already written under the canonical key', () => {
        localStorage.setItem(STORAGE_KEY, 'en');
        localStorage.setItem(LEGACY_STORAGE_KEY, 'cs');
        expect(readStoredLocale()).toBe('en');
        expect(localStorage.getItem(LEGACY_STORAGE_KEY)).toBeNull();
    });

    it('migration is idempotent -- a second read with no legacy key left is a no-op', () => {
        localStorage.setItem(LEGACY_STORAGE_KEY, 'sk_SK');
        readStoredLocale();
        expect(readStoredLocale()).toBe('sk_SK');
    });
});

describe('readDiscoveredLocales', () => {
    afterEach(() => {
        delete document.documentElement.dataset.locales;
    });

    it('returns [] when <html> carries no data-locales attribute', () => {
        delete document.documentElement.dataset.locales;
        expect(readDiscoveredLocales()).toEqual([]);
    });

    it('parses the JSON-encoded discovered-locale list stamped by documentChrome.js', () => {
        document.documentElement.dataset.locales = JSON.stringify(['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT']);
        expect(readDiscoveredLocales()).toEqual(['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT']);
    });

    it('degrades to [] on malformed JSON rather than throwing', () => {
        document.documentElement.dataset.locales = 'not-json';
        expect(readDiscoveredLocales()).toEqual([]);
    });

    it('degrades to [] when the parsed value is not an array', () => {
        document.documentElement.dataset.locales = JSON.stringify({ cs: true });
        expect(readDiscoveredLocales()).toEqual([]);
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
