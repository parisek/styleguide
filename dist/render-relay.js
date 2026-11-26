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
// scrolls. Such a navigation becomes a fragment navigation of this document
// instead, so it scrolls (also on a repeat click), fires `hashchange` and
// sets `:target`, as on the live site. The markup is not changed, so a
// project script reads the `href` it wrote.
(function () {
    var XLINK = 'http://www.w3.org/1999/xlink';

    function hrefOf(node) {
        var value = node.getAttribute('href');
        if (value === null && node.getAttributeNS) value = node.getAttributeNS(XLINK, 'href');
        return value || null;
    }

    // The `<a>` or `<area>` of the click, also in SVG and inside an open
    // shadow root, or null.
    function anchorOf(e) {
        var path = e.composedPath ? e.composedPath() : [e.target];
        for (var i = 0; i < path.length; i++) {
            var node = path[i];
            if (node && node.nodeType === 1 && (node.localName === 'a' || node.localName === 'area') && hrefOf(node) !== null) {
                return node;
            }
        }
        return null;
    }

    // For a fragment-only link that the `<base>` sends to another document:
    // where the browser would go (`from`) and where it should go (`to`).
    function fragmentOf(e, link) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
        if (link.hasAttribute('download')) return null;
        var target = link.getAttribute('target');
        if (target && target.toLowerCase() !== '_self') return null;
        // A browser strips leading and trailing whitespace from a URL. `#`
        // alone names no place and is a common script hook.
        var raw = hrefOf(link).replace(/^\s+|\s+$/g, '');
        if (raw.length < 2 || raw.charAt(0) !== '#') return null;
        // A detached `<a>` resolves against the document base in every
        // browser; an SVG `<a>` has no string `href` of its own.
        var probe = document.createElement('a');
        probe.setAttribute('href', raw);
        var here = location.href.split('#')[0];
        // Without a `<base>` elsewhere, the link already names this document.
        if (probe.href.split('#')[0] === here) return null;
        return { from: probe.href, fragment: raw };
    }

    // `location.href`, not `location.hash`: setting the hash it already has
    // does nothing, and a "back to top" link must scroll again. The URL is
    // read now, because a project handler may have moved it in this click.
    function go(fragment) {
        location.href = location.href.split('#')[0] + fragment;
    }

    // With the Navigation API the relay acts on the navigation itself, after
    // every click handler of the project has run. It does not touch the click
    // event, so a project handler sees `defaultPrevented` and propagation as
    // on the live site, and one that prevents the default keeps its click.
    var navigation = window.navigation;
    if (navigation && typeof navigation.addEventListener === 'function') {
        // Capture on `window`, the first listener of the document: it only
        // notes the link, and adds a `navigate` listener for this click alone.
        // Added now, after the project's own `navigate` listeners, it runs
        // last, so a project router sees the navigation first.
        window.addEventListener('click', function (click) {
            var link = anchorOf(click);
            var noted = link ? fragmentOf(click, link) : null;
            if (!noted) return;
            // Only the link's own navigation counts. A project handler may move
            // the URL itself in the same click (`history.pushState()` or
            // `replaceState()`, with or without preventing the default): that
            // navigation passes, and the listener waits for the link's one.
            function isTheLink(e) {
                if (e.destination.url !== noted.from) return false;
                if ('sourceElement' in e) return e.sourceElement === link;
                return e.userInitiated !== false;
            }
            function onNavigate(e) {
                if (click.defaultPrevented || !isTheLink(e)) return;
                navigation.removeEventListener('navigate', onNavigate);
                if (e.defaultPrevented || !e.cancelable || e.hashChange) return;
                try { e.preventDefault(); } catch (err) { return; }
                go(noted.fragment);
            }
            navigation.addEventListener('navigate', onNavigate);
            setTimeout(function () { navigation.removeEventListener('navigate', onNavigate); }, 0);
        }, true);
        return;
    }

    // Without it, the relay acts on the click, as late as it can: on `window`
    // in the bubble phase. A handler that stops propagation on the way up
    // (Alpine `@click.stop` on the link, inside it or on a panel around it)
    // keeps the click from `window`, so the relay also adds a last listener to
    // each node on the path, in both phases. Two gaps stay: a
    // `stopImmediatePropagation()` before the relay's listener, and a project
    // listener on `window` added after the relay, which sees the click as
    // prevented. The first of them that sees propagation stopped
    // acts; the listeners go away after the click.
    window.addEventListener('click', function (e) {
        var link = anchorOf(e);
        if (!link || !fragmentOf(e, link)) return;
        var path = e.composedPath ? e.composedPath() : [];
        var nodes = [];
        for (var i = 0; i < path.length; i++) {
            if (path[i] !== window && path[i].addEventListener) nodes.push(path[i]);
        }
        if (!nodes.length) nodes.push(link);
        function stopped(ev) {
            if (ev !== e || !ev.cancelBubble || ev.sgFragmentDone) return;
            ev.sgFragmentDone = true;
            var next = fragmentOf(ev, link);
            if (next) { ev.preventDefault(); go(next.fragment); }
        }
        // In both phases: a stop in the capture phase (`@click.capture.stop`)
        // never reaches the bubble listeners.
        for (var j = 0; j < nodes.length; j++) {
            nodes[j].addEventListener('click', stopped, true);
            nodes[j].addEventListener('click', stopped);
        }
        setTimeout(function () {
            for (var k = 0; k < nodes.length; k++) {
                nodes[k].removeEventListener('click', stopped, true);
                nodes[k].removeEventListener('click', stopped);
            }
        }, 0);
    }, true);
    window.addEventListener('click', function (e) {
        if (e.sgFragmentDone) return;
        var link = anchorOf(e);
        var next = link ? fragmentOf(e, link) : null;
        if (next) { e.preventDefault(); go(next.fragment); }
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
