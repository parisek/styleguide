import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('sidebar', () => ({
        sections: Alpine.$persist({
            docs: true,
            basic: true,
            blocks: true,
            gutenberg: false,
            pages: false,
        }).as('sg-sections'),

        toggleSection(key) {
            this.sections[key] = !this.sections[key];
        },

        // Per-group collapse state, keyed "<section>/<prefix>", persisted like
        // sg-sections. Default open (spec #38); the active item's group is
        // always expanded so a deep link stays visible.
        groups: Alpine.$persist({}).as('sg-groups'),

        groupKey(section, prefix) {
            return `${section}/${prefix}`;
        },
        isGroupOpen(section, prefix, children) {
            if (children.some((c) => this.isActive('component', c.id))) return true;
            return this.groups[this.groupKey(section, prefix)] ?? true;
        },
        toggleGroup(section, prefix) {
            const key = this.groupKey(section, prefix);
            this.groups[key] = !(this.groups[key] ?? true);
        },

        isActive(type, slug) {
            const route = Alpine.store('ui').route;
            return route.type === type && route.slug === slug;
        },

        select(type, slug) {
            // Sectionless URLs (`/styleguide/overview`, `/styleguide/fields`)
            // don't carry a slug — the SPA router parses them with slug=null
            // and Renderer maps overview/fields to package-shipped templates.
            const path = slug ? `/styleguide/${type}/${slug}` : `/styleguide/${type}`;
            window.sgNavigate(path);
        },

        // Substring match against name (locale-tuned label) AND id (raw slug)
        // so users can find a component by either spelling. Diacritics-insensitive
        // via NFKD-normalise so "drobeckova" matches "Drobečková navigace".
        matchSearch(item) {
            const q = (Alpine.store('ui').searchQuery ?? '').trim().toLowerCase();
            if (!q) return true;
            const norm = (s) => (s ?? '').toString()
                .normalize('NFKD')
                .replace(/[̀-ͯ]/g, '')
                .toLowerCase();
            const needle = norm(q);
            return norm(item.name).includes(needle) || norm(item.id).includes(needle);
        },

        filterItems(items) {
            return items.filter((i) => this.matchSearch(i));
        },
    }));
});
