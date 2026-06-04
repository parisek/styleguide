import Alpine from 'alpinejs';

function parse() {
    const m = location.pathname.match(/^\/styleguide(?:\/(component|page|doc|overview|foundations|fields)(?:\/(.+?))?)?\/?$/);
    if (!m) return { type: 'landing', slug: null };
    return {
        type: m[1] ?? 'landing',
        slug: m[2] ?? null,
    };
}

document.addEventListener('alpine:init', () => {
    const apply = () => {
        let { type, slug } = parse();
        // Landing (bare `/styleguide` or `/styleguide/`) maps to foundations
        // so the user lands on the design-tokens preview (colors, typography,
        // logo) instead of an empty "Select a component" pane. Foundations
        // is the natural first view of a styleguide — what the brand looks
        // like — with the component / page catalog one click away in
        // Overview. URL stays `/styleguide` (no history pushState), so
        // bookmarks and back-button to the landing keep working.
        if (type === 'landing') type = 'foundations';
        Alpine.store('ui').setRoute(type, slug);
    };

    apply();

    window.addEventListener('popstate', apply);

    window.sgNavigate = (path) => {
        history.pushState(null, '', path);
        apply();
    };
});
