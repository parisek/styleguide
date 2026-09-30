// Maps a vue-router route to the legacy {type, slug} shape every store/
// component keys off (mirrors frontend/router.js `parse()` + the
// landing-maps-to-foundations rule from its `apply()`).
import { landing, pageEnabled, BUILTIN_PAGES } from './runtimeConfig.js';

export function routeInfo(route) {
    let name = route?.name;
    // A page switched off in `builtin_pages` shows the landing instead, as
    // an unknown path does.
    if (BUILTIN_PAGES.includes(name) && !pageEnabled(name)) name = 'landing';
    if (name === 'component' || name === 'page' || name === 'doc') {
        return { type: name, slug: route.params.slug ?? null };
    }
    if (name === 'overview') return { type: 'overview', slug: null };
    if (name === 'icons') return { type: 'icons', slug: null };
    if (name === 'fields') return { type: 'fields', slug: null };
    if (name === 'grid') return { type: 'grid', slug: null };
    // `overview.default: grid` (styleguide.yaml) makes the bare mount the
    // overview grid; the router renders GridView there (router.js).
    if (name === 'landing' && landing() === 'grid') return { type: 'grid', slug: null };
    // An unknown path shows Foundations, unless Foundations is switched
    // off; then it shows the landing (the grid).
    if (name === 'not-found-fallback' && !pageEnabled('foundations')) return { type: 'grid', slug: null };
    // 'foundations', 'landing' (bare "/"), and 'not-found-fallback' (any
    // unmatched path) all render the foundations view with the URL left
    // untouched — see frontend/router.js: "Landing ... maps to foundations
    // ... URL stays /styleguide (no history pushState)".
    return { type: 'foundations', slug: null };
}
