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
        // `_reverseMapForPagesCount` snapshots `store.pages.length` at build
        // time so we can detect stale state — the components API resolves
        // `items` and `pages` in two sequential awaits, so without this guard
        // the map can be built from an empty pages array (every component
        // ends up "Unused" forever).
        _reverseMap: null,
        _reverseMapForPagesCount: -1,

        _buildReverseMap(store) {
            const map = new Map();
            // Pages forward-declare their component usage in `page.usage`.
            // Inverting that into component → pages gives the reverse view.
            // External-link fields (asana/figma/drupal/web) ride along on the
            // chip so the "Used in" sub-list can render the same Asana/Figma
            // badges as the row header without a second lookup.
            for (const page of store.pages) {
                const ids = String(page.usage ?? '')
                    .split(',').map((s) => s.trim()).filter(Boolean);
                for (const id of ids) {
                    if (!map.has(id)) map.set(id, []);
                    map.get(id).push({
                        id: page.id,
                        type: 'page',
                        name: page.name ?? page.id,
                        asana: page.asana,
                        figma: page.figma,
                        drupal: page.drupal,
                        web: page.web,
                    });
                }
            }
            this._reverseMap = map;
            this._reverseMapForPagesCount = store.pages.length;
        },

        reverseUsage(id) {
            // Reading `loading` + `pages.length` registers them as Alpine
            // reactive dependencies of every chip template that calls us,
            // so the chips re-render once the components API resolves.
            const store = Alpine.store('components');
            if (store.loading) return [];
            if (this._reverseMap === null || this._reverseMapForPagesCount !== store.pages.length) {
                this._buildReverseMap(store);
            }
            return this._reverseMap.get(id) ?? [];
        },

        // Forward usage (page → components used) — resolves CSV ids against
        // the components store so each chip carries a real name + nav target.
        // Unknown ids stay as greyed-out chips (type === null) so missing
        // metadata surfaces visibly instead of getting silently dropped.
        //
        // Cached via `_forwardMap` (id → chips[]) because the template calls
        // forwardUsage(page) twice per row (once in `x-if` for the length
        // check, once in `x-for` for the list). Without the map each render
        // would re-split the CSV and re-scan pages + items for every chip.
        // Invalidated when store.pages.length OR items.length changes — same
        // pattern as reverseUsage's pages-count snapshot.
        _forwardMap: null,
        _forwardMapForCount: -1,

        _buildForwardMap(store) {
            const map = new Map();
            const decorate = (item, type) => ({
                id: item.id,
                type,
                name: item.name ?? item.id,
                asana: item.asana,
                figma: item.figma,
                drupal: item.drupal,
                web: item.web,
            });
            const resolve = (ids) => ids.map((id) => {
                const page = store.pages.find((p) => p.id === id);
                if (page) return decorate(page, 'page');
                const comp = store.items.find((c) => c.id === id);
                if (comp) return decorate(comp, 'component');
                return { id, type: null, name: id };
            });
            for (const collection of [store.pages, store.items]) {
                for (const item of collection) {
                    const ids = String(item?.usage ?? '')
                        .split(',').map((s) => s.trim()).filter(Boolean);
                    map.set(item.id, resolve(ids));
                }
            }
            this._forwardMap = map;
            this._forwardMapForCount = store.pages.length + store.items.length;
        },

        forwardUsage(item) {
            const store = Alpine.store('components');
            if (store.loading) return [];
            const totalCount = store.pages.length + store.items.length;
            if (this._forwardMap === null || this._forwardMapForCount !== totalCount) {
                this._buildForwardMap(store);
            }
            return this._forwardMap.get(item?.id) ?? [];
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
            // Reading order on the overview: Pages → composite (Blocks /
            // Gutenberg) → atomic (Basic, i.e. Ostatní). Pages render their
            // own section above this getter; here we order the component
            // buckets so the larger composite groups come before the
            // fine-grained atomic-element bucket — readers care more about
            // the page-level surface than the leaf components when scanning.
            return ['blocks', 'gutenberg', 'basic']
                .filter((section) => buckets[section]?.length > 0)
                .map((section) => ({ section, items: buckets[section] }));
        },

        select(item) {
            if (!item.type) return;
            window.sgNavigate(`/styleguide/${item.type}/${item.id}`);
        },

        // Resolves the external-link icon set for any item carrying the
        // standard four metadata keys. Used by row headers (full item from
        // the store) AND chip rows (decorated copy from `_buildForwardMap` /
        // `_buildReverseMap`); both shapes carry the same four keys so the
        // same template snippet renders either. Mirrors the order used by
        // the per-component linkBar above the iframe: Asana → Figma →
        // Drupal → Web.
        linksFor(item) {
            if (!item) return [];
            return [
                { key: 'asana',  url: item.asana,  label: 'Asana'  },
                { key: 'figma',  url: item.figma,  label: 'Figma'  },
                { key: 'drupal', url: item.drupal, label: 'Drupal' },
                { key: 'web',    url: item.web,    label: 'Web'    },
            ].filter((l) => l.url);
        },
    }));
});
