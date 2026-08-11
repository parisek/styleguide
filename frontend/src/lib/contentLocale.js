// Content-locale precedence: URL `?locale=` > localStorage > YAML
// `bootstrap.default_locale`. Shares its storage key with stores/i18n.js's
// SPA CHROME switcher (`sg-locale`) -- one visitor choice now drives both
// which catalogue the rendered iframe content uses AND which chrome UI
// strings load (see stores/i18n.js's own English-fallback handling for the
// latter). The two used to live under separate keys (`sg-locale` /
// `styleguide:locale`) so they could diverge later; that divergence never
// happened and the two-key split only cost every reader an extra "which key
// means what" lookup, so they were collapsed back into one (§ migrateLegacyKey
// below moves any value a pre-collapse session already stored).
//
// Load-bearing constraint (design doc § "one switch for both" + the
// follow-up localStorage increment): the SERVER never reads localStorage
// and must never grow a way to — a direct `render/...` request with no
// `?locale=` always resolves to `default_locale` alone. Everything in this
// module runs client-side only, in the SPA's own precedence layer on top
// of that unconditional server default.
export const STORAGE_KEY = 'sg-locale';

// Retired key from before the two locale stores were collapsed into one
// (see module doc comment). Migrated on first read below, then removed --
// this branch itself can be deleted once no shipped session is expected to
// still carry the old key.
const LEGACY_STORAGE_KEY = 'styleguide:locale';

// Runs on every readStoredLocale() call -- cheap (two localStorage hits,
// no-op once migrated) and idempotent, so there's no separate "run once at
// boot" wiring to forget. Leaves the canonical key untouched if it's
// already set, even when a legacy value is also present, so a value
// written by THIS session's own switcher click is never clobbered by a
// stale value from before the collapse.
function migrateLegacyKey() {
    try {
        const legacy = localStorage.getItem(LEGACY_STORAGE_KEY);
        if (legacy === null) return;
        if (localStorage.getItem(STORAGE_KEY) === null) {
            localStorage.setItem(STORAGE_KEY, legacy);
        }
        localStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch {
        // Storage unavailable -- nothing to migrate, nothing to clean up.
    }
}

// localStorage can throw (Safari private mode, quota, disabled storage) --
// every access is wrapped so a storage failure degrades to "no stored
// value" rather than breaking the render.
export function readStoredLocale() {
    try {
        migrateLegacyKey();
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

// The switcher's offered/discovered set -- every `.mo` catalogue code the
// server found under `translations_path`, stamped onto <html
// data-locales> by lib/documentChrome.js from the #sg-config payload (see
// that module's own comment; mirrors how data-default-locale reaches this
// same element). A data-* attribute only ever holds a string, so the value
// is JSON-encoded on the way in and parsed back out here. Malformed/missing
// markup (a pre-rewrite dist/index.html, a test harness that never called
// applyDocumentChrome) degrades to an empty list rather than throwing --
// same "unknown project shape must not break the render" posture as every
// other localStorage/DOM read in this module.
export function readDiscoveredLocales(doc = document) {
    try {
        const raw = doc.documentElement.dataset.locales;
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
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
