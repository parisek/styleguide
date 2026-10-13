import { defineStore } from 'pinia';
import { buildTree } from '../lib/prefixTree.js';
import { sectionOf, sectionOrder, configuredLabel } from '../lib/componentSections.js';
import { useI18nStore } from './i18n.js';
import { externalLinksFor } from '../lib/externalLinks.js';
import { url } from '../lib/runtimeConfig.js';

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
        // `components.group_by` from #sg-config, set by main.js before
        // init(): 'kind' or null (the legacy sections by category).
        componentsGroupBy: null,
        // `components.sections` from #sg-config, set by main.js before
        // init(): the resolved list `[{ id, label?, hidden?, fallback? }]`,
        // or null. It replaces `componentsGroupBy`.
        componentsSections: null,
        // Shows the components of hidden sections again (the debug toggle).
        showHidden: false,
    }),
    getters: {
        docEntries: (state) => state.docs,
        // The component sections in reading order, for the sidebar, the
        // grid chips and the overview alike.
        // With `sections`: configured order; hidden sections stay out unless
        // `showHidden`, and a section with no listed component is not shown.
        componentSectionKeys() {
            const keys = sectionOrder(this.componentsGroupBy, this.componentsSections);
            if (!Array.isArray(this.componentsSections)) return keys;
            const used = new Set(this.listedItems.map((c) => this.sectionOf(c)));
            return keys.filter((id) => used.has(id));
        },
        // The components the catalogue lists: not `has_styleguide: false`, and
        // not in a hidden section (unless `showHidden`). `items` keeps every
        // component, so a deep link to a hidden one still renders.
        listedItems() {
            return this.items.filter((c) => c.has_styleguide !== false && (this.showHidden || !this.isHidden(c)));
        },
        // How many listed-otherwise components sit in hidden sections.
        hiddenCount() {
            return this.items.filter((c) => c.has_styleguide !== false && this.isHidden(c)).length;
        },
        pagesTree: (state) => buildTree(state.pages.filter((p) => p.has_styleguide !== false)),
        // Gates the /fields nav entry (Sidebar.vue) and FieldsView's own
        // empty state — mirrors the config.hasIcons pattern but is derived
        // from live catalogue data instead of a server-injected flag, since
        // "does any component declare fields" isn't known until the
        // components API response lands. has_styleguide !== false must
        // match FieldsView's own groups filter, or the nav entry can point
        // at a view with nothing to show (only fields-bearing component is
        // itself hidden from the styleguide).
        hasFields: (state) => state.items.some((c) => Array.isArray(c.fields) && c.fields.length > 0 && c.has_styleguide !== false),
    },
    actions: {
        async init() {
            try {
                const [componentsRes, pagesRes, docsRes] = await Promise.all([
                    fetch(url('api/components')),
                    fetch(url('api/pages')),
                    fetch(url('api/docs')),
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
                const healthRes = await fetch(url('api/health'));
                const health = await healthRes.json();
                this.warnings = health.warnings ?? [];
            } catch (err) {
                console.error('[styleguide] failed to load health diagnostics', err);
            }
        },

        sectionOf(item, type = 'component') {
            if (type === 'page') return 'pages';
            return sectionOf(item, this.componentsGroupBy, this.componentsSections);
        },

        // True when the component sits in a section the config hides.
        isHidden(item) {
            if (!Array.isArray(this.componentsSections)) return false;
            const id = this.sectionOf(item);
            return this.componentsSections.some((s) => s.id === id && s.hidden === true);
        },

        isSectionHidden(id) {
            return Array.isArray(this.componentsSections)
                && this.componentsSections.some((s) => s.id === id && s.hidden === true);
        },

        // The section heading: the configured label first, then the SPA
        // locale's `sections.<id>`. One place for the sidebar, the grid, the
        // board, the overview and the toolbar.
        sectionLabel(id) {
            const i18n = useI18nStore();
            const entry = Array.isArray(this.componentsSections)
                ? this.componentsSections.find((s) => s.id === id)
                : null;
            const fallback = typeof document === 'undefined' ? '' : (document.documentElement.dataset.defaultLocale || '');
            return configuredLabel(entry, i18n.locale, fallback) ?? i18n.t(`sections.${id}`);
        },

        bySection(section) {
            return this.listedItems.filter((c) => this.sectionOf(c) === section);
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
                // A component of a hidden section is not in the catalogue
                // lists, so its chip names it and does not link (the chip of
                // a `has_styleguide: false` component stays a link).
                if (comp && !this.showHidden && this.isHidden(comp)) return { id: usedId, type: null, name: comp.name ?? usedId };
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
