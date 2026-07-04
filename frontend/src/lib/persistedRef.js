import { ref, watch } from 'vue';

// Vue equivalent of `Alpine.$persist(defaultValue).as(key)`: a reactive ref
// that round-trips through localStorage as JSON, under the bare key name
// (no `_x_` prefix — matches @alpinejs/persist's actual convention, which
// the FOUC-prevention inline script in index.html also depends on for
// `sg-theme`). `deep: true` on the watcher covers plain-object values like
// `sg-groups` (`{ "<section>/<prefix>": bool }`) where a nested key changes
// without the ref's own identity changing.
export function usePersistedRef(key, defaultValue) {
    let initial = defaultValue;
    try {
        const stored = localStorage.getItem(key);
        if (stored !== null) initial = JSON.parse(stored);
    } catch (e) {
        // Safari private mode throws on localStorage access; fall back silently.
    }

    const state = ref(initial);

    watch(state, (value) => {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Safari private mode — persistence is best-effort.
        }
    }, { deep: true });

    return state;
}
