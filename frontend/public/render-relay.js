// Error relay for the catalogue's previews. templates/render-cell.twig loads
// it as the first script in <head>, synchronously, so it is listening before
// any project script can throw at start-up (the catalogue itself can only
// attach after `load`, and start-up errors are the common ones).
//
// A file, not an inline script: a project whose Content-Security-Policy
// allows only `script-src 'self'` would block an inline one, and the
// feature would go quiet without a word. Plain ES5, no module: it must run
// before anything else and in any browser the previews run in.
//
// It posts only when framed, only to its own origin, strings only. The
// first 50 errors of a document are sent, then one "more" marker. Besides
// the errors it sends "start" when a document begins, so the catalogue
// drops the frame's older errors. (A "leave" on pagehide would not help: the
// parent receives it with no `source`, so it cannot tell which frame left.
// The catalogue checks each frame's `load` for a document without this
// relay instead.) console.error still reaches
// the console; the wrapper only copies the message.
//
// The second part keeps a fragment link in its document. `iframe.base_href`
// puts a `<base>` into the render so relative asset URLs resolve, but the
// browser resolves a bare `#b` against it too: with `base_href: "/"` the
// link opens the site root. On the live site there is no `<base>` and it
// scrolls. A click on such a link becomes a fragment navigation of this
// document instead, so it scrolls (also on a repeat click), fires
// `hashchange` and sets `:target`, as on the live site. The markup is not
// changed, so a project script reads the `href` it wrote.
(function () {
    // The `<a>` of the click, also inside an open shadow root, or null.
    function anchorOf(e) {
        var path = e.composedPath ? e.composedPath() : [e.target];
        for (var i = 0; i < path.length; i++) {
            var node = path[i];
            if (node && node.nodeType === 1 && node.localName === 'a' && node.hasAttribute('href')) return node;
        }
        return null;
    }

    // The place a fragment-only link names, when the `<base>` would send it
    // to another document; else null.
    function fragmentOf(e, link) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
        if (link.hasAttribute('download')) return null;
        var target = link.getAttribute('target');
        if (target && target.toLowerCase() !== '_self') return null;
        // A browser strips leading and trailing whitespace from a URL. `#`
        // alone names no place and is a common script hook.
        var raw = link.getAttribute('href').replace(/^\s+|\s+$/g, '');
        if (raw.length < 2 || raw.charAt(0) !== '#') return null;
        // `getAttribute`, not `link.href`: an SVG `<a>` has no string `href`.
        var resolved;
        try { resolved = new URL(raw, document.baseURI).href; } catch (err) { return null; }
        var here = location.href.split('#')[0];
        // Without a `<base>` elsewhere, the link already names this document.
        if (resolved.split('#')[0] === here) return null;
        return here + raw;
    }

    function follow(e, url) {
        e.preventDefault();
        // `location.href`, not `location.hash`: setting the hash it already
        // has does nothing, and a "back to top" link must scroll again.
        location.href = url;
    }

    // In the capture phase only to find the link. The decision waits for the
    // project's own handlers, so a script that prevents the default wins.
    window.addEventListener('click', function (e) {
        var link = anchorOf(e);
        if (!link || !fragmentOf(e, link)) return;
        // A handler on the link that stops propagation (Alpine `@click.stop`)
        // keeps the click from `window`. This listener, added last to the link
        // itself, still sees it then.
        link.addEventListener('click', function last(ev) {
            link.removeEventListener('click', last);
            if (ev !== e || !ev.cancelBubble) return;
            var url = fragmentOf(ev, link);
            if (url) follow(ev, url);
        });
    }, true);

    // In the bubble phase on `window`: after the handlers on the link and on
    // `document`, and before the handlers on `window` that a project script
    // adds later.
    window.addEventListener('click', function (e) {
        var link = anchorOf(e);
        var url = link ? fragmentOf(e, link) : null;
        if (url) follow(e, url);
    });
})();

(function () {
    if (window.parent === window) return;
    var sent = 0;
    var LIMIT = 50;
    function post(payload) {
        try { window.parent.postMessage({ sgRender: payload }, location.origin); } catch (e) { /* parent gone */ }
    }
    function text(value) {
        if (value instanceof Error) return value.name + ': ' + value.message;
        if (typeof value === 'string') return value;
        try { return JSON.stringify(value); } catch (e) { return String(value); }
    }
    function report(kind, message, source, line) {
        sent++;
        if (sent > LIMIT) {
            if (sent === LIMIT + 1) post({ type: 'more' });
            return;
        }
        post({
            type: 'error',
            kind: kind,
            message: String(message).slice(0, 500),
            source: source ? String(source).slice(0, 500) : '',
            line: line || 0,
        });
    }
    post({ type: 'start' });
    window.addEventListener('error', function (e) {
        var el = e.target;
        if (el && el !== window && (el.src || el.href)) {
            report('resource', el.tagName.toLowerCase(), el.src || el.href, 0);
            return;
        }
        report('error', e.message || 'Error', e.filename, e.lineno);
    }, true);
    window.addEventListener('unhandledrejection', function (e) { report('rejection', text(e.reason), '', 0); });
    var original = console.error;
    console.error = function () {
        report('console', Array.prototype.map.call(arguments, text).join(' '), '', 0);
        return original.apply(this, arguments);
    };
})();
