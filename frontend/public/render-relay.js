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
