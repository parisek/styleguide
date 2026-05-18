import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('sidebar', () => ({
        sections: Alpine.$persist({
            basic: true,
            blocks: true,
            gutenberg: false,
            pages: false,
        }).as('sg-sections'),

        toggleSection(key) {
            this.sections[key] = !this.sections[key];
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
    }));
});
