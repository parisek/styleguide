import { defineStore } from 'pinia';
import { usePersistedRef } from '../lib/persistedRef.js';

// Single source of truth for the user's theme preference and the resolved
// (light/dark) theme. Three modes: 'light' / 'dark' / 'system' ('system'
// follows prefers-color-scheme live). Ported from frontend/stores/theme.js.
//
// Coupling note: the FOUC-prevention inline script in index.html reads the
// SAME localStorage key — bare `sg-theme`, JSON-encoded. If you rename the
// key here, update that inline script too.
export const useThemeStore = defineStore('theme', {
    state: () => ({
        mode: usePersistedRef('sg-theme', 'system'),
        systemDark: false,
    }),
    getters: {
        resolved: (state) => (state.mode === 'system' ? (state.systemDark ? 'dark' : 'light') : state.mode),
    },
    actions: {
        init() {
            try {
                const mq = window.matchMedia('(prefers-color-scheme: dark)');
                this.systemDark = mq.matches;
                const onChange = (e) => { this.systemDark = e.matches; };
                if (typeof mq.addEventListener === 'function') {
                    mq.addEventListener('change', onChange);
                } else if (typeof mq.addListener === 'function') {
                    mq.addListener(onChange);
                }
            } catch (e) {
                this.systemDark = false;
            }
        },
        apply() {
            document.documentElement.classList.toggle('dark', this.resolved === 'dark');
        },
        cycle() {
            const next = { light: 'dark', dark: 'system', system: 'light' };
            this.mode = next[this.mode] ?? 'system';
        },
    },
});
