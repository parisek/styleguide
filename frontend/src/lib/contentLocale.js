// Content-locale precedence: URL `?locale=` > localStorage > YAML
// `bootstrap.default_locale`. Distinct from stores/i18n.js's own `sg-locale`
// key, which persists the SPA CHROME's UI language (a closed ['cs','en']
// set, always present) — this key persists which CATALOGUE the rendered
// iframe content uses, which happens to be driven by the same switcher
// click today (see useContentLocale.js) but is conceptually separate and
// namespaced separately so the two can diverge later without a migration.
//
// Load-bearing constraint (design doc § "one switch for both" + the
// follow-up localStorage increment): the SERVER never reads localStorage
// and must never grow a way to — a direct `render/...` request with no
// `?locale=` always resolves to `default_locale` alone. Everything in this
// module runs client-side only, in the SPA's own precedence layer on top
// of that unconditional server default.
export const STORAGE_KEY = 'styleguide:locale';

// localStorage can throw (Safari private mode, quota, disabled storage) --
// every access is wrapped so a storage failure degrades to "no stored
// value" rather than breaking the render.
export function readStoredLocale() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function writeStoredLocale(locale) {
    try {
        localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        // Storage unavailable -- the URL and YAML-default layers still work,
        // the visitor just doesn't get a persisted choice this session.
    }
}

export function clearStoredLocale() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to clean up if storage was never writable to begin with.
    }
}

/**
 * Pure precedence resolver -- no localStorage/DOM access, so it's testable
 * without mocking either. `isKnown(locale)` is supplied by the caller (the
 * switcher's own offered/discovered set) rather than hardcoded here, so this
 * module stays agnostic to which locales a given project actually ships.
 *
 * @param {{ urlLocale: string|null, storedLocale: string|null, defaultLocale: string, isKnown: (locale: string) => boolean }} args
 * @returns {{ locale: string, clearStale: boolean }}
 */
export function resolveContentLocale({ urlLocale, storedLocale, defaultLocale, isKnown }) {
    if (urlLocale) {
        // URL wins outright, deterministic for shared links and the visual
        // harvester -- and the caller must NOT persist this value, so a
        // deep link never silently overwrites the visitor's own last choice
        // (see useContentLocale.js: the stored value is left untouched).
        return { locale: urlLocale, clearStale: false };
    }
    if (storedLocale) {
        if (isKnown(storedLocale)) {
            return { locale: storedLocale, clearStale: false };
        }
        // The switcher only ever writes a locale it currently offers, but a
        // stored value can outlive a renamed/removed catalogue between
        // visits. Degrade to the YAML default -- never a blank render, never
        // a 400 (that's the render route's own, unrelated, ambiguity check)
        // -- and tell the caller to clear the stale key so this same
        // fallback doesn't silently repeat on every future load.
        return { locale: defaultLocale, clearStale: true };
    }
    return { locale: defaultLocale, clearStale: false };
}
