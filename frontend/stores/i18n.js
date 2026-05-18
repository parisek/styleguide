import Alpine from 'alpinejs';

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

document.addEventListener('alpine:init', () => {
    Alpine.store('i18n', {
        locale: 'en',
        strings: {},

        async init() {
            await this.load(detectLocale());
        },

        async load(locale) {
            if (!SUPPORTED.includes(locale)) return;
            // Locale files are non-hashed and served with max-age=3600, so the
            // default fetch hits the disk cache. `no-cache` makes the browser
            // revalidate with ETag every time — server responds 304 when
            // unchanged (cheap) and serves fresh content when locale strings
            // change between dev rebuilds.
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
    });
});
