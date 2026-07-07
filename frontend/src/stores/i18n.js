import { defineStore } from 'pinia';

const SUPPORTED = ['cs', 'en'];
const STORAGE_KEY = 'sg-locale';

function detectLocale() {
    const html = document.documentElement;
    const defaultLocale = html.dataset.defaultLocale || 'en';

    const urlLocale = new URLSearchParams(location.search).get('lang');
    if (urlLocale && SUPPORTED.includes(urlLocale)) {
        localStorage.setItem(STORAGE_KEY, urlLocale);
        return urlLocale;
    }

    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored && SUPPORTED.includes(stored)) return stored;

    if (SUPPORTED.includes(defaultLocale)) return defaultLocale;

    const browser = (navigator.language || 'en').split('-')[0];
    if (SUPPORTED.includes(browser)) return browser;

    return 'en';
}

// Ported from frontend/stores/i18n.js. NOTE: `sg-locale` is a PLAIN STRING in
// localStorage (`localStorage.setItem(key, locale)`), unlike every other
// persisted key in this app which is JSON-encoded via @alpinejs/persist /
// usePersistedRef. Do not route this key through usePersistedRef — that
// would write `"en"` (quoted) instead of `en` and desync from any value a
// pre-rewrite session already stored.
export const useI18nStore = defineStore('i18n', {
    state: () => ({
        locale: 'en',
        strings: {},
    }),
    actions: {
        async init() {
            await this.load(detectLocale());
        },
        async load(locale) {
            if (!SUPPORTED.includes(locale)) return;
            const response = await fetch(`/styleguide/assets/locales/${locale}.json`, { cache: 'no-cache' });
            if (!response.ok) {
                console.error(`[styleguide] failed to load locale ${locale}`);
                return;
            }
            this.strings = await response.json();
            this.locale = locale;
            localStorage.setItem(STORAGE_KEY, locale);
            document.documentElement.setAttribute('lang', locale);
        },
        t(path) {
            return path.split('.').reduce((obj, key) => obj?.[key], this.strings) ?? path;
        },
    },
});
