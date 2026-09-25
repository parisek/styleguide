import { baseUrl } from './runtimeConfig.js';

// Minimal cookie writer — no third-party dependency needed for a single
// same-origin key/value pair. Scoped to the catalogue's mount path (every
// route this package serves lives under it; `/styleguide` by default) and `SameSite=Lax` (first-party
// navigation only; the iframe's in-content links are same-site, so Lax
// still attaches the cookie on the in-iframe navigations this exists for).
export function setCookie(name, value, { path = baseUrl(), sameSite = 'Lax' } = {}) {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=${path}; SameSite=${sameSite}`;
}
