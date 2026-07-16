import { defineStore } from 'pinia';
import { buildTree } from '../lib/prefixTree.js';
import { externalLinksFor } from '../lib/externalLinks.js';

// Ported from frontend/stores/components.js, renamed `catalog` per the
// Phase 1 spec's target file layout. Adds reverseUsageFor/forwardUsageFor,
// which consolidate the reverse/forward usage-map builders that lived
// duplicated inside frontend/components/overview.js (`_buildReverseMap`/
// `_buildForwardMap`) — same algorithm, single home.
export const useCatalogStore = defineStore('catalog', {
    state: () => ({
        items: [],
        pages: [],
        docs: [],
        warnings: [],
        loading: true,
    }),
    getters: {
        docEntries: (state) => state.docs,
        pagesTree: (state) => buildTree(state.pages.filter((p) => p.has_styleguide !== false)),
        // Gates the /fields nav entry (Sidebar.vue) and FieldsView's own
        // empty state — mirrors the config.hasIcons pattern but is derived
        // from live catalogue data instead of a server-injected flag, since
        // "does any component declare fields" isn't known until the
        // components API response lands.
        hasFields: (state) => state.items.some((c) => Array.isArray(c.fields) && c.fields.length > 0),
    },
    actions: {
        async init() {
            try {
                const [componentsRes, pagesRes, docsRes] = await Promise.all([
                    fetch('/styleguide/api/components'),
                    fetch('/styleguide/api/pages'),
                    fetch('/styleguide/api/docs'),
                ]);
                this.items = await componentsRes.json();
                this.pages = await pagesRes.json();
                this.docs = await docsRes.json();
            } catch (err) {
                console.error('[styleguide] failed to load catalogue', err);
            } finally {
                this.loading = false;
            }

            // Health is operator diagnostics, not core catalogue data — fetched
            // in its own try/catch so a network hiccup (or an older server
            // build predating this endpoint) never blocks or fails loading of
            // the actual component/page/doc list above.
            try {
                const healthRes = await fetch('/styleguide/api/health');
                const health = await healthRes.json();
                this.warnings = health.warnings ?? [];
            } catch (err) {
                console.error('[styleguide] failed to load health diagnostics', err);
            }
        },

        sectionOf(item, type = 'component') {
            if (type === 'page') return 'pages';
            const cat = (item?.category ?? '').toLowerCase();
            if (cat === 'gutenberg') return 'gutenberg';
            if (['block', 'blocks', 'layout'].includes(cat)) return 'blocks';
            return 'basic';
        },

        bySection(section) {
            return this.items.filter((c) => this.sectionOf(c) === section && c.has_styleguide !== false);
        },

        treeOf(section) {
            return buildTree(this.bySection(section));
        },

        find(type, slug) {
            const list = type === 'page' ? this.pages : type === 'doc' ? this.docs : this.items;
            return list.find((c) => c.id === slug) ?? null;
        },

        reverseUsageFor(id) {
            const map = new Map();
            for (const page of this.pages) {
                const ids = page.usage ?? [];
                for (const usedId of ids) {
                    if (!map.has(usedId)) map.set(usedId, []);
                    map.get(usedId).push({ id: page.id, type: 'page', name: page.name ?? page.id, ...pickLinks(page) });
                }
            }
            return map.get(id) ?? [];
        },

        forwardUsageFor(itemOrId) {
            const id = typeof itemOrId === 'string' ? itemOrId : itemOrId?.id;
            const decorate = (item, type) => ({ id: item.id, type, name: item.name ?? item.id, ...pickLinks(item) });
            const source = [...this.pages, ...this.items].find((it) => it.id === id);
            if (!source) return [];
            const ids = source.usage ?? [];
            return ids.map((usedId) => {
                const page = this.pages.find((p) => p.id === usedId);
                if (page) return decorate(page, 'page');
                const comp = this.items.find((c) => c.id === usedId);
                if (comp) return decorate(comp, 'component');
                return { id: usedId, type: null, name: usedId };
            });
        },
    },
});

function pickLinks(item) {
    const { asana, figma, drupal, web } = item;
    return { asana, figma, drupal, web };
}

// Re-exported so components that only need link resolution (LinkBar,
// OverviewView) don't need to import both catalog.js and externalLinks.js.
export { externalLinksFor };
