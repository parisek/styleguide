import Alpine from 'alpinejs';

function parse() {
    const m = location.pathname.match(/^\/styleguide(?:\/(component|page|overview|foundations|fields)(?:\/(.+?))?)?\/?$/);
    if (!m) return { type: 'landing', slug: null };
    return {
        type: m[1] ?? 'landing',
        slug: m[2] ?? null,
    };
}

document.addEventListener('alpine:init', () => {
    const apply = () => {
        let { type, slug } = parse();
        // Landing (bare `/styleguide` or `/styleguide/`) maps to overview so
        // the user lands on the colors / typography / fonts preview instead
        // of an empty "Select a component" pane. URL stays `/styleguide` —
        // the rewrite is virtual, no history pushState — so bookmarks /
        // back-button to the landing keep working.
        if (type === 'landing') type = 'overview';
        Alpine.store('ui').setRoute(type, slug);
    };

    apply();

    window.addEventListener('popstate', apply);

    window.sgNavigate = (path) => {
        history.pushState(null, '', path);
        apply();
    };
});
