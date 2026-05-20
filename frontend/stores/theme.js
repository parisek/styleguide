import Alpine from 'alpinejs';

// Theme store — single source of truth for the user's preference and the
// currently-applied theme on <html>. Three modes: 'light' / 'dark' / 'system'.
// The `system` mode follows `prefers-color-scheme` live; light / dark are
// hard overrides.
//
// Coupling note: the FOUC-prevention inline script in `index.html` <head>
// reads the SAME localStorage key (`_x_sg-theme`, where `_x_` is the
// @alpinejs/persist namespace and `sg-theme` matches the `.as()` arg below).
// If you rename either side, rename both.
document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        mode: Alpine.$persist('system').as('sg-theme'),
        systemDark: false,

        init() {
            try {
                const mq = window.matchMedia('(prefers-color-scheme: dark)');
                this.systemDark = mq.matches;
                mq.addEventListener('change', (e) => { this.systemDark = e.matches; });
            } catch (e) {
                // matchMedia missing (extremely old browsers) — treat as light.
                this.systemDark = false;
            }
            // Re-apply whenever `mode` or `systemDark` changes. Captured as an
            // effect rather than a `$watch` so a single reactive dep graph
            // covers both axes without double-binding.
            Alpine.effect(() => this.apply());
        },

        get resolved() {
            return this.mode === 'system' ? (this.systemDark ? 'dark' : 'light') : this.mode;
        },

        apply() {
            document.documentElement.classList.toggle('dark', this.resolved === 'dark');
        },

        cycle() {
            const next = { light: 'dark', dark: 'system', system: 'light' };
            this.mode = next[this.mode] ?? 'system';
        },
    });
});
