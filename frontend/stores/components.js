import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.store('components', {
        items: [],
        pages: [],
        loading: true,

        async init() {
            try {
                const [componentsRes, pagesRes] = await Promise.all([
                    fetch('/styleguide/api/components'),
                    fetch('/styleguide/api/pages'),
                ]);
                this.items = await componentsRes.json();
                this.pages = await pagesRes.json();
            } catch (err) {
                console.error('[styleguide] failed to load components', err);
            } finally {
                this.loading = false;
            }
        },

        bySection(section) {
            // Categories ship through the API verbatim from each component's YAML
            // metadata (`category: ...`). Real projects use a wider vocabulary than
            // just Block/Gutenberg — Basic, Layout, Page, Other, plus empty for
            // un-categorised entries — so the matcher folds related labels into
            // three buckets and routes everything unrecognised into `basic` instead
            // of silently dropping it from the sidebar.
            const norm = (c) => (c.category ?? '').toLowerCase();
            const isGutenberg = (c) => norm(c) === 'gutenberg';
            const isBlock     = (c) => ['block', 'blocks', 'layout'].includes(norm(c));
            const matchers = {
                gutenberg: isGutenberg,
                blocks:    isBlock,
                basic:     (c) => !isGutenberg(c) && !isBlock(c),
            };
            const match = matchers[section] ?? (() => false);
            return this.items.filter(match);
        },

        find(type, slug) {
            // URL slugs (`/styleguide/component/<slug>`) match the component / page
            // `id` field server-side — the API doesn't ship a separate `slug` key.
            const list = type === 'page' ? this.pages : this.items;
            return list.find((c) => c.id === slug) ?? null;
        },
    });
});
