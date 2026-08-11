import { defineStore } from 'pinia';
import { readStoredLocale, writeStoredLocale } from '../lib/contentLocale.js';

// The CHROME's own closed set -- which locales `public/locales/*.json` ships
// UI strings for. No longer the switcher's offered set (that's every
// discovered `.mo` catalogue, see Sidebar.vue's use of
// contentLocale.js's readDiscoveredLocales()) -- a picked locale outside
// this set still gets a working switcher entry, it just falls back to
// English chrome text (see chromeStringsLocaleFor() + load() below) while
// the content locale itself still switches correctly.
export const SUPPORTED = ['cs', 'en'];

// Matches a requested locale (any shape the switcher offers -- 'cs', 'en',
// or a full catalogue code like 'sk_SK') against SUPPORTED by its leading
// two letters, the same short-code normalisation detectLocale() below
// already applies to a browser-reported `navigator.language`. Falls back to
// English chrome strings rather than refusing to load anything -- the owner
// is explicit that mixed UI/content language is an acceptable trade so
// every discovered locale stays reachable from the switcher.
function chromeStringsLocaleFor(locale) {
    const short = (locale || '').slice(0, 2).toLowerCase();
    return SUPPORTED.includes(short) ? short : 'en';
}

function detectLocale() {
    const html = document.documentElement;
    const defaultLocale = html.dataset.defaultLocale || 'en';

    // `?lang=` is the chrome-only initial-locale override (distinct from the
    // content-locale `?locale=` param useContentLocale.js reads) -- kept
    // unvalidated here, same as the stored/default candidates below: load()
    // is the single place that decides whether a candidate has chrome
    // strings of its own or falls back to English, so this function only
    // picks WHICH candidate wins by precedence, never whether it's usable.
    const urlLocale = new URLSearchParams(location.search).get('lang');
    if (urlLocale) return urlLocale;

    const stored = readStoredLocale();
    if (stored) return stored;

    if (defaultLocale) return defaultLocale;

    const browser = (navigator.language || 'en').split('-')[0];
    return browser || 'en';
}

// Ported from frontend/stores/i18n.js. NOTE: `sg-locale` (lib/contentLocale.js's
// STORAGE_KEY, shared with the content-locale store since the two keys were
// collapsed into one -- see that module's doc comment) is a PLAIN STRING in
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
            if (!locale) return;
            const chromeLocale = chromeStringsLocaleFor(locale);
            const response = await fetch(`/styleguide/assets/locales/${chromeLocale}.json`, { cache: 'no-cache' });
            if (!response.ok) {
                console.error(`[styleguide] failed to load locale ${chromeLocale}`);
                return;
            }
            this.strings = await response.json();
            // The stored/current value stays the locale the visitor PICKED,
            // never the chrome fallback -- storing 'en' after a click on a
            // locale outside SUPPORTED would silently switch the content
            // locale too (the two now share one storage key), which is
            // exactly the bug this split guards against.
            this.locale = locale;
            writeStoredLocale(locale);
            document.documentElement.setAttribute('lang', locale);
        },
        t(path) {
            return path.split('.').reduce((obj, key) => obj?.[key], this.strings) ?? path;
        },
    },
});
