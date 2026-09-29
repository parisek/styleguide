// Where the catalogue is mounted, read once from the server-injected
// #sg-config payload (`baseUrl`, built by Styleguide::dispatchSpa() from its
// mount path). Every URL the SPA builds — router history base, API and
// locale fetches, iframe sources, the theme cookie path — goes through
// url()/assetUrl() below, so the same dist/ works under any mount.
//
// Tolerant where config.js is strict: main.js calls readSpaConfig() first
// and fails loudly on a missing element, so production never reaches the
// fallback. The fallback exists for modules imported in isolation (unit
// tests), and for a payload from a server older than `baseUrl`.
import { readSpaConfig } from './config.js';

export const DEFAULT_BASE_URL = '/styleguide';

let cached = null;

function normalise(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
        return DEFAULT_BASE_URL;
    }
    const trimmed = value.replace(/\/+$/, '');
    return trimmed === '' ? DEFAULT_BASE_URL : trimmed;
}

// The payload, read once and reduced to the values this module serves.
function config() {
    if (cached === null) {
        let raw = {};
        try {
            raw = readSpaConfig();
        } catch {
            // See the header: only reachable outside the served shell.
        }
        cached = {
            baseUrl: normalise(raw.baseUrl),
            // The server sends `showSource: true` only when the fixture
            // source may be shown (Styleguide::showSource()). Anything else
            // hides the "Code" toggle; the API refuses on its own anyway.
            showSource: raw.showSource === true,
            // `highlight_source: false` in styleguide.yaml. The server sends
            // the key only to turn highlighting off.
            highlightSource: raw.highlightSource !== false,
            // `source_views` in styleguide.yaml, in the panel's order. An
            // older server sends none: the views it had, Data and HTML.
            sourceViews: normaliseSourceViews(raw.sourceViews),
            // `source_url` in styleguide.yaml: a template file's address in
            // the repository, `{path}` standing for its path.
            sourceUrl: typeof raw.sourceUrl === 'string' && /^https?:\/\//.test(raw.sourceUrl) && raw.sourceUrl.includes('{path}')
                ? raw.sourceUrl
                : null,
            compareWidths: normaliseCompareWidths(raw.compareWidths),
            // `overview.default: grid` in styleguide.yaml: the bare mount
            // lands on the overview grid. Anything else keeps Foundations.
            landing: raw.landing === 'grid' ? 'grid' : 'foundations',
        };
    }
    return cached;
}

// The server validates `viewports.compare` at boot; this only guards the
// shape, so a hand-edited or older payload can never produce a broken
// compare mode. Anything off -> null (no compare button).
function normaliseCompareWidths(value) {
    if (!Array.isArray(value) || value.length < 2 || value.length > 4) return null;
    return value.every((w) => Number.isInteger(w) && w > 0) ? [...value] : null;
}

export const SOURCE_VIEWS = ['data', 'twig', 'html', 'css', 'js'];

function normaliseSourceViews(value) {
    if (!Array.isArray(value)) return ['data', 'html'];
    const views = SOURCE_VIEWS.filter((view) => value.includes(view));
    return views.length ? views : ['data', 'html'];
}

// The Code panel's views, e.g. ['data', 'html', 'css', 'js'].
export function sourceViews() {
    return config().sourceViews;
}

// `viewports.compare` from styleguide.yaml, e.g. [1440, 768, 320], or null.
export function compareWidths() {
    return config().compareWidths;
}

// What the bare mount (`/styleguide/`) shows: 'grid' or 'foundations'.
export function landing() {
    return config().landing;
}

export function baseUrl() {
    return config().baseUrl;
}

export function showSource() {
    return config().showSource;
}

// `source_url` from styleguide.yaml, or null.
export function sourceUrl() {
    return config().sourceUrl;
}

// Whether the Code panel highlights the source (on unless turned off).
export function highlightSource() {
    return config().highlightSource;
}

// `path` is relative to the mount: 'api/components', 'render/component/card'.
export function url(path = '') {
    const relative = String(path).replace(/^\/+/, '');
    return relative === '' ? baseUrl() : `${baseUrl()}/${relative}`;
}

// `path` is relative to the served dist root: 'locales/en.json'.
export function assetUrl(path) {
    return url(`assets/${String(path).replace(/^\/+/, '')}`);
}

// Tests only: forget the cached value so the next call reads #sg-config again.
export function resetRuntimeConfig() {
    cached = null;
}
