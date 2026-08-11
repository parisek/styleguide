import { computed, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { SUPPORTED } from '../stores/i18n.js';
import {
    readStoredLocale, writeStoredLocale, clearStoredLocale, resolveContentLocale,
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
export function useContentLocale() {
    const route = useRoute();

    const defaultLocale = document.documentElement.dataset.defaultLocale || 'en';

    const contentLocale = computed(() => {
        const urlLocale = typeof route.query.locale === 'string' && route.query.locale !== ''
            ? route.query.locale
            : null;
        const storedLocale = readStoredLocale();
        const { locale } = resolveContentLocale({
            urlLocale,
            storedLocale,
            defaultLocale,
            isKnown: (loc) => SUPPORTED.includes(loc),
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
        const storedLocale = readStoredLocale();
        const { clearStale } = resolveContentLocale({
            urlLocale,
            storedLocale,
            defaultLocale,
            isKnown: (loc) => SUPPORTED.includes(loc),
        });
        if (clearStale) clearStoredLocale();
    });

    // The switcher's write path -- explicit user choice, always persisted.
    // Deliberately does NOT touch the URL query: an explicit `?locale=` on
    // the current address is a separate, higher-precedence signal (see
    // resolveContentLocale()) that this action must not fight with by
    // rewriting the route out from under it.
    function setContentLocale(locale) {
        writeStoredLocale(locale);
    }

    return { contentLocale, setContentLocale };
}
