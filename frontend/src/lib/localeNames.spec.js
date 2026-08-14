import { describe, it, expect } from 'vitest';
import { languageName, shortLabel } from './localeNames.js';

describe('languageName', () => {
    it('names a full catalogue code in the language itself', () => {
        expect(languageName('cs_CZ')).toBe('Čeština');
        expect(languageName('en_US')).toBe('English');
        expect(languageName('it_IT')).toBe('Italiano');
        expect(languageName('pl_PL')).toBe('Polski');
        expect(languageName('sk_SK')).toBe('Slovenčina');
    });

    it('names a bare two-letter code too', () => {
        // detectLocale() can hand the switcher a browser-derived short code
        // ('cs'), while the discovered set carries full ones ('cs_CZ') —
        // both shapes reach this function and both must resolve.
        expect(languageName('cs')).toBe('Čeština');
        expect(languageName('de')).toBe('Deutsch');
    });

    it('accepts a hyphen as the separator', () => {
        expect(languageName('pt-BR')).toBe('Português (Brasil)');
        expect(languageName('en-GB')).toBe('English');
    });

    it('is case-insensitive on the language part', () => {
        expect(languageName('CS_cz')).toBe('Čeština');
    });

    it('distinguishes regional variants that are genuinely separate languages', () => {
        // Two catalogues can share a language subtag and still deserve
        // distinct labels — collapsing them to one name would render two
        // switcher entries with identical text and no way to tell them apart.
        expect(languageName('pt_PT')).toBe('Português');
        expect(languageName('pt_BR')).toBe('Português (Brasil)');
        expect(languageName('zh_CN')).toBe('简体中文');
        expect(languageName('zh_TW')).toBe('繁體中文');
    });

    it('returns an unknown code unchanged rather than inventing a name', () => {
        expect(languageName('xx_YY')).toBe('xx_YY');
        expect(languageName('klingon')).toBe('klingon');
    });

    it('survives empty and non-string input', () => {
        expect(languageName('')).toBe('');
        expect(languageName(null)).toBe('');
        expect(languageName(undefined)).toBe('');
    });
});

describe('shortLabel', () => {
    it('uppercases the language subtag', () => {
        expect(shortLabel('cs_CZ')).toBe('CS');
        expect(shortLabel('en-GB')).toBe('EN');
        expect(shortLabel('sk')).toBe('SK');
    });

    it('keeps the region when two offered locales share a language', () => {
        // 'PT' twice on the trigger would make the closed switcher lie about
        // which one is active, so the caller passes the full offered set and
        // gets a disambiguated label back.
        const offered = ['pt_PT', 'pt_BR'];
        expect(shortLabel('pt_PT', offered)).toBe('PT-PT');
        expect(shortLabel('pt_BR', offered)).toBe('PT-BR');
    });

    it('stays short when the language subtag is already unique in the set', () => {
        const offered = ['cs_CZ', 'en_US', 'pt_BR'];
        expect(shortLabel('pt_BR', offered)).toBe('PT');
        expect(shortLabel('cs_CZ', offered)).toBe('CS');
    });

    it('falls back to the raw code when there is no language subtag to take', () => {
        expect(shortLabel('')).toBe('');
        expect(shortLabel(null)).toBe('');
    });
});
