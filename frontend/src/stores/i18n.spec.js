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

    it('rejects an unsupported locale without mutating state', async () => {
        global.fetch = vi.fn();
        const i18n = useI18nStore();
        await i18n.load('fr');
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
