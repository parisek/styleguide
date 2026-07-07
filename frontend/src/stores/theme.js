import { defineStore } from 'pinia';
import { watch } from 'vue';
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
            // Legacy Alpine ran `Alpine.effect(() => this.apply())` here so
            // <html class="dark"> stayed in sync with every mode/system-pref
            // change; Pinia has no auto-tracking effect, so watch() plays the
            // same role. `immediate: true` also covers boot — the inline
            // FOUC script in index.html only prevents the first-paint flash,
            // it doesn't keep classList in sync afterwards.
            watch(() => this.resolved, () => this.apply(), { immediate: true });
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
