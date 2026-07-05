import { createApp } from 'vue';
import { createPinia } from 'pinia';

// frontend/styleguide.css lives one level up from src/ (deviation from the
// brief's literal `./styleguide.css`, which doesn't resolve from here).
import '../styleguide.css';

import App from './App.vue';
import { router } from './router.js';
import { readSpaConfig } from './lib/config.js';
import { useI18nStore } from './stores/i18n.js';
import { useUiStore } from './stores/ui.js';
import { useThemeStore } from './stores/theme.js';
import { useCatalogStore } from './stores/catalog.js';

const config = readSpaConfig();

// detectLocale() in stores/i18n.js falls back to html.dataset.defaultLocale
// when no URL param / localStorage value picks a locale — index.html no
// longer stamps that attribute server-side (dispatchSpa() now only injects
// #sg-config), so we set it here, in JS, from the same config payload.
document.documentElement.dataset.defaultLocale = config.locale;

const app = createApp(App);
app.use(createPinia());
app.use(router);

const i18n = useI18nStore();
const ui = useUiStore();
const theme = useThemeStore();
const catalog = useCatalogStore();

theme.init();
i18n.init();
ui.initFromUrl();
catalog.init();

app.mount('#app');

// Favicon fallback — recover with a generic glyph if the configured favicon
// 404s, isn't a valid image, or no favicon is configured. Ported from
// styleguide.js; App.vue (Task 5) binds #sg-favicon's `src`/`alt`
// reactively to config.favicon — this only wires the `error` listener
// once at boot.
const GENERIC_FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Cpath d='M3 9h18M9 21V9'/%3E%3C/svg%3E";
document.addEventListener('DOMContentLoaded', () => {
    const favEl = document.getElementById('sg-favicon');
    if (!favEl) return;
    const applyFallback = () => {
        if (favEl.src === GENERIC_FAVICON) return;
        favEl.src = GENERIC_FAVICON;
        favEl.classList.add('p-1');
    };
    favEl.addEventListener('error', applyFallback);
    if (!favEl.getAttribute('src') || (favEl.complete && favEl.naturalWidth === 0)) applyFallback();
});

// document.title sync — replaces the Alpine.effect in the legacy
// styleguide.js. Runs on every route/locale/catalog change via a Pinia
// subscription rather than Alpine's auto-tracking effect.
function syncTitle() {
    const route = router.currentRoute.value;
    let label;
    if (route.name === 'overview') {
        label = i18n.t('nav.overview');
    } else if (route.name === 'foundations' || route.name === 'landing') {
        label = i18n.t('nav.foundations');
    } else if (route.params.slug) {
        const item = catalog.find(route.name, route.params.slug);
        label = item?.name ?? route.params.slug;
    }
    document.title = label ? `${label} — ${config.projectName}` : `Styleguide — ${config.projectName}`;
}
router.afterEach(syncTitle);
i18n.$subscribe(syncTitle);
catalog.$subscribe(syncTitle);
syncTitle();

export { config };
