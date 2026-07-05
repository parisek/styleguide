// Reads the server-injected config payload out of the single
// <script id="sg-config" type="application/json"> element that
// Styleguide::dispatchSpa() substitutes into dist/index.html. Throws
// (rather than falling back to a default) when the element is missing or
// unparsable — the PHP side made the matching decision (throw instead of
// silently no-op'ing 6+ separate regexes), so a build/deploy mismatch fails
// loudly on both ends instead of shipping a half-configured shell.
export function readSpaConfig(elementId = 'sg-config') {
    const el = document.getElementById(elementId);
    if (!el) throw new Error(`[styleguide] missing #${elementId} script element`);
    return JSON.parse(el.textContent || '{}');
}

// Sets document.title from the server-injected config as soon as it's
// parsed, before the router/i18n/catalog stores exist to drive the
// route-aware `syncTitle()` in main.js. Without this, dist/index.html's
// static `<title>Styleguide</title>` (no server-side title patch since
// dispatchSpa() dropped the six preg_replace calls) is the visible tab
// title for however long store init + the first route resolution take —
// most visible on a slow/offline load, and briefly on every load either
// way. Called again by `syncTitle()` once routing is ready, which is the
// source of truth after boot.
export function seedTitle(config) {
    if (config && typeof config.title === 'string' && config.title !== '') {
        document.title = config.title;
    }
}
