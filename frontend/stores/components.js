import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.store('components', {
        items: [],
        pages: [],
        docs: [],
        loading: true,

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
        },

        // Categories ship through the API verbatim from each component's YAML
        // metadata (`category: ...`). Real projects use a wider vocabulary than
        // just Block/Gutenberg — Basic, Layout, Page, Other, plus empty for
        // un-categorised entries — so we fold related labels into three buckets
        // and route everything unrecognised into `basic` instead of silently
        // dropping it. Pages live in their own bucket regardless of category.
        sectionOf(item, type = 'component') {
            if (type === 'page') return 'pages';
            const cat = (item?.category ?? '').toLowerCase();
            if (cat === 'gutenberg') return 'gutenberg';
            if (['block', 'blocks', 'layout'].includes(cat)) return 'blocks';
            return 'basic';
        },

        // Filter out skeleton-only templates (no `styleguide.twig` AND no
        // `styleguide:` key in YAML metadata) — they render empty content
        // and would just clutter the sidebar. ComponentParser computes
        // `hasStyleguide` server-side; we honour it here.
        bySection(section) {
            return this.items.filter((c) => this.sectionOf(c) === section && c.hasStyleguide !== false);
        },

        // Group a section's components into a prefix tree (spec #38). A display
        // name shaped "<Prefix> - <Suffix>" joins a bucket keyed by <Prefix>; a
        // bucket with >= GROUP_MIN members becomes a collapsible `group` node
        // (each child carries `leaf` = the suffix), otherwise its members spill
        // back to flat `item` nodes carrying the full name. Names without " - "
        // are always flat. Pure derivation from `name`, computed at render time
        // (no metadata). Ordered by label/name; children ordered by suffix (cs).
        treeOf(section) {
            const GROUP_MIN = 3;
            const buckets = new Map();
            for (const it of this.bySection(section)) {
                const name = it.name ?? it.id;
                const sep = name.indexOf(' - ');
                const prefix = sep > 0 ? name.slice(0, sep) : null;
                const key = prefix ?? ` ${it.id}`;
                if (!buckets.has(key)) buckets.set(key, { prefix, items: [] });
                buckets.get(key).items.push(it);
            }
            const nodes = [];
            for (const b of buckets.values()) {
                if (b.prefix && b.items.length >= GROUP_MIN) {
                    const children = b.items
                        .map((it) => {
                            const name = it.name ?? it.id;
                            return { ...it, leaf: name.slice(name.indexOf(' - ') + 3) };
                        })
                        .sort((a, c) => a.leaf.localeCompare(c.leaf, 'cs'));
                    nodes.push({ type: 'group', label: b.prefix, sortKey: b.prefix, children });
                } else {
                    for (const it of b.items) nodes.push({ type: 'item', item: it, sortKey: it.name ?? it.id });
                }
            }
            return nodes.sort((a, c) => a.sortKey.localeCompare(c.sortKey, 'cs'));
        },

        // Docs are served in API order (server sorts by weight + cs collation).
        // Do NOT re-sort or filter by hasStyleguide — docs have a flat list with
        // no section bucketing; the server's order is intentional.
        get docEntries() {
            return this.docs;
        },

        find(type, slug) {
            // URL slugs (`/styleguide/component/<slug>`) match the component / page
            // `id` field server-side — the API doesn't ship a separate `slug` key.
            const list = type === 'page' ? this.pages : type === 'doc' ? this.docs : this.items;
            return list.find((c) => c.id === slug) ?? null;
        },
    });
});
