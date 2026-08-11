// Single owner of every document-level side effect driven by the
// server-injected #sg-config payload (favicon link, default locale stamp,
// title seed). These used to live as unrelated inline blocks in main.js —
// which is exactly how the browser-tab favicon lost its consumer when the
// Vue rewrite dropped dispatchSpa()'s per-tag preg_replace patches: the
// sidebar <img> got a new owner (Sidebar.vue), the <link> got nobody, and
// nothing failed. One function means a config field either has its consumer
// here or visibly has none.

// Generic square-glyph fallback so the tab and sidebar never render broken:
// used when no favicon is configured (link + img) or when the configured one
// fails to load (img only — <link rel="icon"> has no reliable error event;
// broken favicons are the server-side FaviconAudit's job to report).
export const GENERIC_FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Cpath d='M3 9h18M9 21V9'/%3E%3C/svg%3E";

export function applyDocumentChrome(config, doc = document) {
    const favicon = typeof config.favicon === 'string' && config.favicon !== ''
        ? config.favicon
        : GENERIC_FAVICON;
    const linkEl = doc.getElementById('sg-favicon-tag');
    if (linkEl) linkEl.setAttribute('href', favicon);

    // detectLocale() in stores/i18n.js falls back to html.dataset.defaultLocale
    // when no URL param / localStorage value picks a locale — index.html no
    // longer stamps that attribute server-side (dispatchSpa() only injects
    // #sg-config), so it's stamped here from the same payload.
    doc.documentElement.dataset.defaultLocale = config.locale;

    // Every discovered `.mo` catalogue code, stamped the same way as
    // data-default-locale above — a JSON array (data-* attributes only hold
    // strings) so lib/contentLocale.js's readDiscoveredLocales() can parse
    // it back client-side. Empty/missing `config.locales` degrades to `[]`,
    // matching a project with no `translations_path` configured: the
    // switcher then has nothing to offer, same as today.
    doc.documentElement.dataset.locales = JSON.stringify(
        Array.isArray(config.locales) ? config.locales : [],
    );

    // Title seed — before the router/i18n/catalog stores exist to drive the
    // route-aware syncTitle() in main.js, dist/index.html's static
    // <title>Styleguide</title> would be the visible tab title for however
    // long store init + first route resolution take. syncTitle() takes over
    // as the source of truth once routing is ready.
    if (typeof config.title === 'string' && config.title !== '') {
        doc.title = config.title;
    }
}
