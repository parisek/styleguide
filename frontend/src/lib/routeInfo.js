// Maps a vue-router route to the legacy {type, slug} shape every store/
// component keys off (mirrors frontend/router.js `parse()` + the
// landing-maps-to-foundations rule from its `apply()`).
export function routeInfo(route) {
    const name = route?.name;
    if (name === 'component' || name === 'page' || name === 'doc') {
        return { type: name, slug: route.params.slug ?? null };
    }
    if (name === 'overview') return { type: 'overview', slug: null };
    if (name === 'fields') return { type: 'fields', slug: null };
    // 'foundations', 'landing' (bare "/"), and 'not-found-fallback' (any
    // unmatched path) all render the foundations view with the URL left
    // untouched — see frontend/router.js: "Landing ... maps to foundations
    // ... URL stays /styleguide (no history pushState)".
    return { type: 'foundations', slug: null };
}
