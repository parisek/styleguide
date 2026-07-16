import { createApp } from 'vue';
import { createPinia } from 'pinia';

// frontend/styleguide.css lives one level up from src/ (deviation from the
// brief's literal `./styleguide.css`, which doesn't resolve from here).
import '../styleguide.css';

import App from './App.vue';
import { router } from './router.js';
import { readSpaConfig } from './lib/config.js';
import { applyDocumentChrome } from './lib/documentChrome.js';
import { useI18nStore } from './stores/i18n.js';
import { useUiStore } from './stores/ui.js';
import { useThemeStore } from './stores/theme.js';
import { useCatalogStore } from './stores/catalog.js';

const config = readSpaConfig();
// Every document-level consumer of the payload (favicon <link>, default
// locale stamp, title seed) lives in applyDocumentChrome — see the module
// header there for why they're deliberately not inlined here.
applyDocumentChrome(config);

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
    } else if (route.name === 'fields') {
        label = i18n.t('nav.fields');
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
