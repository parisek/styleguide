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

export function baseUrl() {
    if (cached === null) {
        let config = {};
        try {
            config = readSpaConfig();
        } catch {
            // See the header: only reachable outside the served shell.
        }
        cached = normalise(config.baseUrl);
    }
    return cached;
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
