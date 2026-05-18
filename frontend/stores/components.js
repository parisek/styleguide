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

        bySection(section) {
            return this.items.filter((c) => this.sectionOf(c) === section);
        },

        find(type, slug) {
            // URL slugs (`/styleguide/component/<slug>`) match the component / page
            // `id` field server-side — the API doesn't ship a separate `slug` key.
            const list = type === 'page' ? this.pages : this.items;
            return list.find((c) => c.id === slug) ?? null;
        },
    });
});
