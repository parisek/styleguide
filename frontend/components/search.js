import Alpine from 'alpinejs';

// Query state lives in `Alpine.store('ui').searchQuery` so sidebar templates
// in a sibling scope can read it reactively. This component is the thin shell
// that binds the input + ⌘K / Esc keyboard shortcuts to that store.
document.addEventListener('alpine:init', () => {
    Alpine.data('search', () => ({
        init() {
            window.addEventListener('keydown', (e) => {
                if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
                    e.preventDefault();
                    this.$refs.input?.focus();
                }
                if (e.key === 'Escape') {
                    Alpine.store('ui').searchQuery = '';
                    this.$refs.input?.blur();
                }
            });
        },
    }));
});
