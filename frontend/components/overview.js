import Alpine from 'alpinejs';

// Components & Pages master index — renders inside the SPA shell (not the
// iframe) so its visual chrome ships with the package, not the consumer's
// dist/. Pulls everything from the already-loaded `components` store; no
// new API call.
//
// `usage:` semantics (manually maintained in each .twig's YAML metadata):
//   - on a page → CSV of component ids USED BY the page
//   - on a component → CSV of OTHER component/page ids THAT USE this one
//
// Forward usage (page → components used) reads `page.usage` directly.
// Reverse usage (component → where it appears) needs an inversion of the
// page-side CSVs; we build the map once on first access and look up in O(1).

document.addEventListener('alpine:init', () => {
    Alpine.data('overview', () => ({
        // Persisted toggle — sticky across reloads. localStorage key
        // namespaced with `sg-` so sibling apps can't collide with us.
        showUsage: Alpine.$persist(true).as('sg-overview-show-usage'),

        // Reverse-usage index: component-id → array of {id, type, name} that
        // USE this component. Built lazily on first access; null until then.
        _reverseMap: null,

        _buildReverseMap() {
            const map = new Map();
            const components = Alpine.store('components');
            // Pages forward-declare their component usage in `page.usage`.
            // Inverting that into component → pages gives the reverse view.
            for (const page of components.pages) {
                const ids = String(page.usage ?? '')
                    .split(',').map((s) => s.trim()).filter(Boolean);
                for (const id of ids) {
                    if (!map.has(id)) map.set(id, []);
                    map.get(id).push({ id: page.id, type: 'page', name: page.name ?? page.id });
                }
            }
            this._reverseMap = map;
        },

        reverseUsage(id) {
            if (this._reverseMap === null) this._buildReverseMap();
            return this._reverseMap.get(id) ?? [];
        },

        // Forward usage (page → components used) — resolves CSV ids against
        // the components store so each chip carries a real name + nav target.
        // Unknown ids stay as greyed-out chips (type === null) so missing
        // metadata surfaces visibly instead of getting silently dropped.
        forwardUsage(item) {
            const ids = String(item?.usage ?? '')
                .split(',').map((s) => s.trim()).filter(Boolean);
            const components = Alpine.store('components');
            return ids.map((id) => {
                const page = components.pages.find((p) => p.id === id);
                if (page) return { id, type: 'page', name: page.name ?? id };
                const comp = components.items.find((c) => c.id === id);
                if (comp) return { id, type: 'component', name: comp.name ?? id };
                return { id, type: null, name: id };
            });
        },

        get pages() {
            return Alpine.store('components').pages
                .filter((p) => p.hasStyleguide !== false);
        },

        // Returns [{section: 'basic', items: [...]}, ...] for every non-empty
        // section. Uses the same sectionOf() bucketing the sidebar uses so
        // there's a single source of truth for "what counts as gutenberg".
        get componentSections() {
            const store = Alpine.store('components');
            const buckets = {};
            for (const item of store.items) {
                if (item.hasStyleguide === false) continue;
                const section = store.sectionOf(item, 'component');
                if (!buckets[section]) buckets[section] = [];
                buckets[section].push(item);
            }
            return ['basic', 'blocks', 'gutenberg']
                .filter((section) => buckets[section]?.length > 0)
                .map((section) => ({ section, items: buckets[section] }));
        },

        // Flat alphabetical list per section, for the bottom directory grid.
        directoryList(section) {
            const store = Alpine.store('components');
            const items = section === 'pages'
                ? store.pages
                : store.items.filter((c) => store.sectionOf(c, 'component') === section);
            return items
                .filter((i) => i.hasStyleguide !== false)
                .slice()
                .sort((a, b) => (a.name ?? a.id).localeCompare(b.name ?? b.id, 'cs'));
        },

        select(item) {
            if (!item.type) return;
            window.sgNavigate(`/styleguide/${item.type}/${item.id}`);
        },
    }));
});
