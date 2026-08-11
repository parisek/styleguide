import { computed, ref, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import {
    readStoredLocale, writeStoredLocale, clearStoredLocale, resolveContentLocale, readDiscoveredLocales,
} from '../lib/contentLocale.js';

// Wires lib/contentLocale.js's pure precedence resolver (URL > localStorage
// > YAML default) to the SPA's actual URL and storage -- mirrors
// useVariant.js's split between "pure resolution" and "read the route /
// write the route" for the same reason: the pure half is trivially
// unit-testable, this half only needs a thin integration smoke test.
//
// `?locale=` here is the SPA's OWN address-bar query param (e.g. a visitor
// on `/styleguide/component/registration?locale=cs_CZ`), separate from the
// iframe's `?locale=` this composable's caller (useViewportPreset.js)
// builds FROM the resolved value below -- one flows in, the other flows
// out, and this module is the seam between them.
//
// The stored value is mirrored in a MODULE-level ref, not read straight from
// localStorage inside the computed. localStorage is not a reactive source, so
// a computed that reads it has nothing to recompute on: the switcher wrote the
// new locale and the iframe URL kept the old one until the next navigation.
// Module scope rather than per-call is what makes the mirror shared — Sidebar
// owns the switcher and App owns the iframe URL, so a per-instance ref would
// leave each holding its own copy and reintroduce the same silence.
const storedLocale = ref(readStoredLocale());

// Re-reads storage into the mirror. The tests drive localStorage directly, and
// a module-level ref initialised at import time would otherwise hold whatever
// the first test left behind.
export function syncStoredLocale() {
    storedLocale.value = readStoredLocale();
}

export function useContentLocale() {
    const route = useRoute();

    const defaultLocale = document.documentElement.dataset.defaultLocale || 'en';

    const contentLocale = computed(() => {
        const urlLocale = typeof route.query.locale === 'string' && route.query.locale !== ''
            ? route.query.locale
            : null;
        const { locale } = resolveContentLocale({
            urlLocale,
            storedLocale: storedLocale.value,
            defaultLocale,
            isKnown: (loc) => readDiscoveredLocales().includes(loc),
        });
        return locale;
    });

    // Side effect kept separate from the pure `contentLocale` computed above
    // -- clearing localStorage is an effect, not a derivation, and running
    // it inside computed() would fire on every re-evaluation (including
    // ones triggered by something else entirely) rather than only when the
    // stale value actually changes.
    watchEffect(() => {
        const urlLocale = typeof route.query.locale === 'string' && route.query.locale !== ''
            ? route.query.locale
            : null;
        const { clearStale } = resolveContentLocale({
            urlLocale,
            storedLocale: storedLocale.value,
            defaultLocale,
            isKnown: (loc) => readDiscoveredLocales().includes(loc),
        });
        if (clearStale) {
            clearStoredLocale();
            storedLocale.value = null;
        }
    });

    // The switcher's write path -- explicit user choice, always persisted.
    // Deliberately does NOT touch the URL query: an explicit `?locale=` on
    // the current address is a separate, higher-precedence signal (see
    // resolveContentLocale()) that this action must not fight with by
    // rewriting the route out from under it.
    function setContentLocale(locale) {
        writeStoredLocale(locale);
        storedLocale.value = locale;
    }

    return { contentLocale, setContentLocale };
}
