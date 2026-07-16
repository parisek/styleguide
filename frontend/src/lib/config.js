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
