import { useRoute, useRouter } from 'vue-router';
import { entryFromUrl } from '../lib/entryLink.js';
import { useUiStore } from '../stores/ui.js';

// Links between entries inside a preview iframe (#250). A page's menu links
// to `<base>/page/about`; followed inside the iframe, the server answers with
// the render (Router::synthesizeEmbeddedRoute()), but the SPA around it
// would stay on the old entry: URL, sidebar, toolbar and the content locale
// (`?locale=` is not part of the link). `follow(iframe)`, called from each
// preview iframe's `load` (single preview, variant grid tiles, compare
// columns, the active board frame), makes a plain click on such a link move
// the SPA route instead; the iframes then follow with their own render src.
// A new tab, a modifier key, a download or another target is left to the
// browser.
//
// The load check is the fallback for a navigation the click handler did not
// see (a script, a form): when an iframe ends up on another entry than the
// src the SPA gave it, the route follows with `replace`, because the iframe
// navigation already added a history entry. After the route change the
// iframe's src is that entry's render again, so this does not loop.
//
// The route keeps `?locale=`, unless the link names its own; a link's
// `?theme=` sets the iframe theme, as the toggle does. The route does not keep
// `?variant=`: a variant belongs to one entry, as with a sidebar click (see
// useVariant.js); a link can still name one. These are the precedence rules of
// the canvas rewrite on the server (CanvasLinks).
export function useEntryLinks() {
    const route = useRoute();
    const router = useRouter();

    function routeTo(entry) {
        const query = {};
        if (entry.variant) query.variant = entry.variant;
        const locale = entry.locale ?? route?.query?.locale;
        if (typeof locale === 'string' && locale !== '') query.locale = locale;
        if (entry.theme) {
            // Only on a click that names a theme: a preview mounted without a
            // store (a unit test) never gets here.
            const ui = useUiStore();
            if (entry.theme !== ui.iframeTheme) ui.setIframeTheme(entry.theme);
        }
        return { path: `/${entry.type}/${entry.slug}`, query, hash: entry.hash ?? '' };
    }

    // A fragment in the SPA URL (`/page/about#team`, from a link or a pasted
    // deep link) scrolls to that element of the preview once it has loaded.
    // The iframe is as tall as its content, so the pane scrolls, not the frame.
    function scrollToHash(doc, landed) {
        const hash = route?.hash ?? '';
        if (hash.length < 2 || !landed) return;
        if (landed.type !== route.name || landed.slug !== route.params?.slug) return;
        scrollToId(doc, safeDecode(hash.slice(1)));
    }

    function follow(iframe) {
        let win;
        let doc;
        try {
            win = iframe?.contentWindow;
            doc = iframe?.contentDocument;
        } catch {
            return;
        }
        if (!win?.location || typeof doc?.addEventListener !== 'function') return;

        let here;
        try {
            here = win.location.href;
        } catch {
            return;
        }
        const given = entryFromUrl(iframe.getAttribute('src') ?? '', here);
        const landed = entryFromUrl(here, here);
        if (given && landed && movedFrom(given, landed)) {
            router.replace(routeTo(landed));
            return;
        }
        scrollToHash(doc, landed);

        doc.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target?.closest?.('a[href]');
            if (!link || link.hasAttribute('download')) return;
            const target = link.getAttribute('target');
            if (target && target.toLowerCase() !== '_self') return;
            // `link.href` is what the browser would follow: resolved against
            // the document's base URI, so a `<base href>` is honoured.
            const entry = entryFromUrl(link.href, here);
            if (!entry) return;
            if (isSelf(entry, landed)) {
                // `<base>/page/x#team` on page x: the same document under its SPA
                // URL. Followed, it would reload the frame and lose the place.
                if (entry.hash && withoutHash(link.href) !== withoutHash(here)) {
                    event.preventDefault();
                    scrollToId(doc, safeDecode(entry.hash.slice(1)));
                }
                return;
            }
            event.preventDefault();
            router.push(routeTo(entry));
        });
    }

    return { follow };
}

function withoutHash(url) {
    const at = url.indexOf('#');
    return at === -1 ? url : url.slice(0, at);
}

function safeDecode(value) {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}

function scrollToId(doc, id) {
    if (!id) return;
    const target = doc.getElementById(id) ?? doc.getElementsByName(id)[0];
    target?.scrollIntoView?.({ block: 'start' });
}

// The iframe left the entry, variant, locale or theme the SPA gave it. A
// locale or theme counts only when the landed URL names one: a native
// navigation to `<base>/page/x` carries neither, and that is not a change.
function movedFrom(given, landed) {
    if (given.type !== landed.type || given.slug !== landed.slug) return true;
    if ((given.variant ?? null) !== (landed.variant ?? null)) return true;
    if (landed.locale !== null && landed.locale !== given.locale) return true;
    return landed.theme !== null && landed.theme !== given.theme;
}

// A link to what the iframe already shows: a fragment, `href="#"`, or the same
// entry with no variant, locale or theme of its own. Left to the browser.
function isSelf(entry, landed) {
    if (!landed || entry.type !== landed.type || entry.slug !== landed.slug) return false;
    if (entry.locale !== null || entry.theme !== null) return false;
    return entry.variant === null || entry.variant === landed.variant;
}
