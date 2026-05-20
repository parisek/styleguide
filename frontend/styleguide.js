import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import persist from '@alpinejs/persist';

import './styleguide.css';

import './stores/i18n.js';
import './stores/ui.js';
import './stores/components.js';

import './router.js';

import './components/sidebar.js';
import './components/search.js';
import './components/preview.js';
import './components/usage.js';
import './components/linkBar.js';
import './components/overview.js';
import './components/languageSwitcher.js';

Alpine.plugin(collapse);
Alpine.plugin(persist);
window.Alpine = Alpine;
Alpine.start();

// Keep document.title in sync with the current route. Runs as an Alpine effect
// so it re-fires whenever any reactive dependency it touches changes — route
// flips, components API resolves, locale switches. The project name comes
// from the `data-project-name` attribute that Styleguide::dispatchSpa stamps
// into <body> at request time.
const projectName = document.body.dataset.projectName || 'Styleguide';
Alpine.effect(() => {
    const route = Alpine.store('ui').route;
    const i18n = Alpine.store('i18n');
    let label;
    if (route.type === 'overview') {
        label = i18n.t('nav.overview');
    } else if (route.type === 'foundations') {
        label = i18n.t('nav.foundations');
    } else if (route.type === 'fields') {
        label = i18n.t('nav.fields');
    } else if (route.slug) {
        const item = Alpine.store('components').find(route.type, route.slug);
        label = item?.name ?? route.slug;
    }
    document.title = label ? `${label} — ${projectName}` : `Styleguide — ${projectName}`;
});
