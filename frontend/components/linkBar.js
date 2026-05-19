import Alpine from 'alpinejs';

// Reads the current component / page's external-link fields (`asana`, `figma`,
// `drupal`, `web`) from the components store and exposes them as a list of
// renderable link descriptors. The SVG path data is owned by the template so
// markup stays inspectable; this module only resolves which links exist.
//
// Mirrors the legacy `styleguide-component.twig` badge row — same four targets,
// same SVG sources (Asana official, Figma official, Drupal teardrop, generic
// link glyph for "web"). The previous chrome rendered them above the iframe;
// the new chrome puts them inline with the usage panel.

document.addEventListener('alpine:init', () => {
    Alpine.data('linkBar', () => ({
        get visible() {
            const route = Alpine.store('ui').route;
            return (route.type === 'component' || route.type === 'page') && route.slug;
        },

        get links() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            if (!item) return [];
            // Order matches the legacy row: Asana → Figma → Drupal → Web. Empty
            // strings on the API side mean "no link declared in the .twig
            // metadata YAML" — those are filtered out by the truthy check.
            return [
                { key: 'asana',  url: item.asana,  label: 'Asana'  },
                { key: 'figma',  url: item.figma,  label: 'Figma'  },
                { key: 'drupal', url: item.drupal, label: 'Drupal' },
                { key: 'web',    url: item.web,    label: 'Web'    },
            ].filter((l) => l.url);
        },
    }));
});
