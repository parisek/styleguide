import { baseUrl } from './runtimeConfig.js';

// A catalogue entry that a URL points at, or null.
//
// Content inside the preview iframe links to other entries the way a real
// site does: `<base>/page/about` (a data `url:` rebased onto `homeUrl`, or
// `homeUrl ~ 'page/about'` in Twig). The server answers such a click inside
// the iframe with the render (Router::synthesizeEmbeddedRoute()), but the SPA
// around it would stay on the old entry. PreviewPane uses this to move the
// SPA route instead. A render URL (`<base>/render/page/about`) counts too, so
// a link a canvas render rewrote, or a render the iframe already shows, maps
// back to its entry.
//
// `href` is resolved against `from` (the iframe document's own URL). A URL on
// another origin, outside the mount, or naming anything but one
// component/page/doc slug gives null. Of the query, the keys the render
// endpoint reads are kept when the link sets them: `variant` (the fixture),
// `locale` and `theme` (`light` or `dark`); null when absent. Anything else
// belongs to the frame, not the entry. `hash` is the fragment (`#team`) or
// null.
export function entryFromUrl(href, from, base = baseUrl()) {
    let target;
    let origin;
    try {
        origin = new URL(from).origin;
        target = new URL(href, from);
    } catch {
        return null;
    }
    if (target.origin !== origin) return null;

    const prefix = base.replace(/\/+$/, '');
    if (!target.pathname.startsWith(prefix + '/')) return null;
    const rest = target.pathname.slice(prefix.length + 1).replace(/\/+$/, '');
    const match = /^(?:render\/)?(component|page|doc)\/([A-Za-z0-9_-]+)$/.exec(rest);
    if (!match) return null;

    const variant = target.searchParams.get('variant');
    const locale = target.searchParams.get('locale');
    const theme = target.searchParams.get('theme');
    return {
        type: match[1],
        slug: match[2],
        variant: variant || null,
        locale: locale || null,
        theme: theme === 'dark' || theme === 'light' ? theme : null,
        hash: target.hash.length > 1 ? target.hash : null,
    };
}

const XLINK = 'http://www.w3.org/1999/xlink';

// The `<a>` or `<area>` a click is on, also in SVG and inside an open shadow
// root, or null. `event.target.closest()` misses both: at `document` the
// target of a click in a shadow root is its host, and an SVG `<a>` is found
// but has no string `href`.
export function anchorOf(event) {
    const path = typeof event?.composedPath === 'function' ? event.composedPath() : ancestors(event?.target);
    for (const node of path) {
        if (node?.nodeType === 1 && (node.localName === 'a' || node.localName === 'area') && rawHref(node) !== null) {
            return node;
        }
    }
    return null;
}

// The URL a link points at, resolved as the browser resolves it: against the
// document base URI, so a `<base href>` is honoured. Null when it does not
// parse. Works for HTML and SVG links (`href` or `xlink:href`).
export function linkUrl(link) {
    const raw = rawHref(link);
    if (raw === null) return null;
    try {
        // As the URL parser: strip leading and trailing C0 controls and spaces
        // only. `trim()` would also strip U+00A0, which the browser keeps.
        return new URL(raw.replace(/^[\u0000-\u0020]+|[\u0000-\u0020]+$/g, ''), link.ownerDocument?.baseURI).href;
    } catch {
        return null;
    }
}

// The `href` value, or null when the node has none. `href=""` is a link to
// the current document, as in the browser, and an SVG `href` wins over
// `xlink:href`.
function rawHref(node) {
    if (node.hasAttribute('href')) return node.getAttribute('href');
    return node.getAttributeNS?.(XLINK, 'href') ?? null;
}

function ancestors(node) {
    const path = [];
    for (let at = node; at; at = at.parentNode) path.push(at);
    return path;
}
