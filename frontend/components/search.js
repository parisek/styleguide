import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('search', () => ({
        query: '',

        init() {
            window.addEventListener('keydown', (e) => {
                if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
                    e.preventDefault();
                    this.$refs.input?.focus();
                }
                if (e.key === 'Escape') {
                    this.query = '';
                    this.$refs.input?.blur();
                }
            });
        },

        filter(items) {
            const q = this.query.trim().toLowerCase();
            if (!q) return items;
            return items.filter((c) =>
                (c.name ?? c.slug ?? '').toLowerCase().includes(q)
            );
        },
    }));
});
