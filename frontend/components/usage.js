import Alpine from 'alpinejs';

// Reads the current route's `usage` CSV (`page-header-image,gallery-slider,...`)
// and resolves each token against the components + pages stores so the panel
// can render real names + clickable navigation chips.
//
// Semantic difference by route kind:
//   - on a component view → `usage` lists OTHER components/pages that USE this one
//   - on a page view      → `usage` lists components THE PAGE USES
//
// The token resolver tries pages first, then components, mirroring the legacy
// `processUsage()` helper in static/index.php — pages and components share an
// id namespace so an unambiguous lookup is fine.

document.addEventListener('alpine:init', () => {
    Alpine.data('usagePanel', () => ({
        get visible() {
            const route = Alpine.store('ui').route;
            return (route.type === 'component' || route.type === 'page') && route.slug;
        },

        get current() {
            const route = Alpine.store('ui').route;
            if (!route.slug) return null;
            return Alpine.store('components').find(route.type, route.slug);
        },

        get label() {
            const route = Alpine.store('ui').route;
            const t = (key) => Alpine.store('i18n').t(key);
            return route.type === 'page' ? t('usage.components_in_page') : t('usage.used_in');
        },

        get items() {
            const cur = this.current;
            if (!cur?.usage) return [];
            const ids = String(cur.usage).split(',').map((s) => s.trim()).filter(Boolean);
            const components = Alpine.store('components');
            return ids.map((id) => {
                const page = components.pages.find((p) => p.id === id);
                if (page) return { id, type: 'page', name: page.name ?? id };
                const comp = components.items.find((c) => c.id === id);
                if (comp) return { id, type: 'component', name: comp.name ?? id };
                // Unknown reference — still show as a chip but greyed out + non-clickable.
                return { id, type: null, name: id };
            });
        },

        select(item) {
            if (!item.type) return;
            window.sgNavigate(`/styleguide/${item.type}/${item.id}`);
        },
    }));
});
