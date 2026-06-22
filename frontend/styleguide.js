import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import persist from '@alpinejs/persist';

import './styleguide.css';

import './stores/i18n.js';
import './stores/ui.js';
import './stores/components.js';
import './stores/theme.js';

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

// Favicon fallback — the src is stamped server-side into #sg-favicon by
// Styleguide::dispatchSpa from the consumer's styleguide.yaml. We can't know at
// build time whether that file exists; if it 404s (or isn't a valid image, or
// no favicon is configured at all) recover at runtime with a generic glyph
// instead of the browser's broken-image icon.
const GENERIC_FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Cpath d='M3 9h18M9 21V9'/%3E%3C/svg%3E";
const favEl = document.getElementById('sg-favicon');
if (favEl) {
    const applyFallback = () => {
        if (favEl.src === GENERIC_FAVICON) return;
        favEl.src = GENERIC_FAVICON;
        favEl.classList.add('p-1'); // inset the generic glyph within its rounded box
    };
    favEl.addEventListener('error', applyFallback);
    // Cover images that already failed before this listener attached, plus the
    // empty-src "no favicon configured" case.
    if (!favEl.getAttribute('src') || (favEl.complete && favEl.naturalWidth === 0)) applyFallback();
}
Alpine.effect(() => {
    const route = Alpine.store('ui').route;
    const i18n = Alpine.store('i18n');
    let label;
    if (route.type === 'overview') {
        label = i18n.t('nav.overview');
    } else if (route.type === 'foundations') {
        label = i18n.t('nav.foundations');
    } else if (route.slug) {
        const item = Alpine.store('components').find(route.type, route.slug);
        label = item?.name ?? route.slug;
    }
    document.title = label ? `${label} — ${projectName}` : `Styleguide — ${projectName}`;
});
