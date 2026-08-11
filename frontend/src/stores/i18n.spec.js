import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useI18nStore } from './i18n.js';

beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
});

describe('useI18nStore', () => {
    it('loads a locale, storing strings and updating <html lang>', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ nav: { overview: 'Overview' } }),
        });
        const i18n = useI18nStore();
        await i18n.load('en');
        expect(i18n.locale).toBe('en');
        expect(i18n.t('nav.overview')).toBe('Overview');
        expect(document.documentElement.getAttribute('lang')).toBe('en');
    });

    it('persists the locale as a PLAIN STRING (not JSON-encoded) under sg-locale', async () => {
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        const i18n = useI18nStore();
        await i18n.load('en');
        // Legacy stores.i18n.js writes `localStorage.setItem(STORAGE_KEY, locale)` —
        // a bare string, NOT `JSON.stringify(locale)`. Getting this wrong breaks every
        // user who already has "en" or "cs" (unquoted) saved from the Alpine build.
        expect(localStorage.getItem('sg-locale')).toBe('en');
    });

    it('loads English chrome strings for a picked locale outside SUPPORTED, but stores the picked locale itself', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ nav: { overview: 'Overview' } }),
        });
        const i18n = useI18nStore();
        // sk_SK -- a discovered content catalogue with no chrome strings of
        // its own (SUPPORTED is only ['cs', 'en']).
        await i18n.load('sk_SK');
        expect(fetch).toHaveBeenCalledWith('/styleguide/assets/locales/en.json', { cache: 'no-cache' });
        expect(i18n.t('nav.overview')).toBe('Overview');
        // The picked locale, not the English fallback, is what's current --
        // storing 'en' here would silently downgrade the content locale too,
        // since the two now share one storage key.
        expect(i18n.locale).toBe('sk_SK');
        expect(localStorage.getItem('sg-locale')).toBe('sk_SK');
        expect(document.documentElement.getAttribute('lang')).toBe('sk_SK');
    });

    it('matches the chrome fallback by the first two letters of the picked locale', async () => {
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        const i18n = useI18nStore();
        await i18n.load('cs_CZ');
        expect(fetch).toHaveBeenCalledWith('/styleguide/assets/locales/cs.json', { cache: 'no-cache' });
        expect(i18n.locale).toBe('cs_CZ');
    });

    it('does nothing for an empty/falsy locale', async () => {
        global.fetch = vi.fn();
        const i18n = useI18nStore();
        await i18n.load('');
        expect(fetch).not.toHaveBeenCalled();
        expect(i18n.locale).toBe('en');
    });

    it('t() falls back to the dotted path itself when the key is missing', () => {
        const i18n = useI18nStore();
        expect(i18n.t('nonexistent.key')).toBe('nonexistent.key');
    });

    it('logs and leaves state unchanged when the fetch response is not ok', async () => {
        global.fetch = vi.fn().mockResolvedValue({ ok: false });
        const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const i18n = useI18nStore();
        await i18n.load('en');
        expect(i18n.locale).toBe('en'); // unchanged from the store's initial default
        errSpy.mockRestore();
    });
});
