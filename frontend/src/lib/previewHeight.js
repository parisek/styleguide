// Shared height resolution for the two surfaces that size a same-origin iframe
// from its own content: VariantGrid's per-tile geometry (lib/tileGeometry.js)
// and the single preview (components/PreviewPane.vue).
//
// It lives in its own module precisely BECAUSE there are two of them. The first
// fix for #116 patched only the grid; the single preview kept its own copy of
// the same measure-and-feed-back logic and reproduced the identical runaway in
// Custom-width mode (820 x 33,554,400 px). A shared helper is what stops the
// next change from fixing one and missing the other again.

// Demo viewport height for a `render: chrome` entry whenever there is no
// canonical preset height to fall back on (Full, or a fixed-width/Custom-width
// preset).
//
// Such an entry declares `body { min-height: 200vh }` (render-cell.twig) so
// sticky and fixed chrome has something to scroll against — which means its
// content height is DERIVED FROM its viewport height. Sizing the iframe to that
// content would define the two heights in terms of each other, and the loop runs
// until the browser clamps the element at 2^25px and the renderer process dies.
//
// Pinning the viewport breaks the cycle at its source rather than damping it:
// the viewport stops depending on the content, so `200vh` resolves against a
// fixed number and settles. The iframe then scrolls internally with no extra
// CSS — that is an iframe's default behaviour once content exceeds its height —
// and the variants demo honestly: sticky pins, fixed holds, static and absolute
// scroll away.
export const CHROME_VIEWPORT_HEIGHT_PX = 640;

// Defensive ceiling on any measured content height. This is NOT what fixes
// #116 — `scrolls` below is.
//
// Exceeding it does not truncate anything: the iframe keeps its full document
// and simply scrolls internally, so the content stays reachable. What the
// ceiling buys is that a future feedback path degrades into a conspicuously
// tall pane someone reports, instead of a dead tab.
export const MAX_CONTENT_HEIGHT_PX = 20000;

// `scrolls` wins over any measurement: for a chrome entry the measurement is
// precisely the quantity that must not feed back into the height. Defaults to
// false so every caller and test double that predates it is unaffected.
export function resolveContentHeight({ rawContentHeight, minHeight, scrolls = false }) {
    if (scrolls) return CHROME_VIEWPORT_HEIGHT_PX;
    return Math.min(rawContentHeight ?? minHeight ?? 0, MAX_CONTENT_HEIGHT_PX);
}

// Single source of truth for "is this entry chrome?", so the grid and the
// single preview cannot answer it differently. An absent or legacy `render`
// value falls through to false — non-chrome behaviour, exactly as before.
export function entryScrolls(item) {
    return item?.render === 'chrome';
}

// The page shell render-cell.twig wraps a page render in when the consumer sets
// `iframe.page_wrapper_class` — typically a flex column with a `100dvh` min-height and `main`
// set to `grow`.
export const PAGE_SHELL_SELECTOR = '[data-sg-page-shell]';

function px(value) {
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : 0;
}

// The height of the content in a same-origin preview document — the number every
// surface that sizes an iframe to its content feeds back into that iframe (#255).
//
// The document's own scrollHeight is a RATCHET when the page shell is present:
// a `100dvh` min-height resolves against the iframe, so the document is never shorter than
// the iframe, and the iframe is sized from the document. Any moment the content
// is taller than its settled state — the first paint before Alpine.js collapses
// something, a late font — fixes the height there for good, and `main` grows to
// fill the difference as an empty band above the footer. Reading the shell
// instead, with its viewport floor lifted for one synchronous layout, gives the
// content's own height. The lift is set and restored inside one task, so the
// page never paints it and ResizeObserver, which samples sizes at the next
// rendering step, never sees it either.
//
// Without a shell nothing changes: the document measure stays as it was, so a
// `<dialog>` overlay (all top layer, an empty body) still gets a height.
export function measureContentHeight(doc) {
    const documentHeight = Math.max(doc?.documentElement?.scrollHeight ?? 0, doc?.body?.scrollHeight ?? 0);
    const shell = typeof doc?.querySelector === 'function' ? doc.querySelector(PAGE_SHELL_SELECTOR) : null;
    if (!shell || typeof shell.getBoundingClientRect !== 'function') return documentHeight;

    const style = shell.style;
    const saved = ['min-height', 'height'].map((prop) => [prop, style.getPropertyValue(prop), style.getPropertyPriority(prop)]);
    // `height` too: a shell that also carries `h-full` under a `body { height:
    // 100% }` is the same floor by another name.
    style.setProperty('min-height', '0px', 'important');
    style.setProperty('height', 'auto', 'important');
    try {
        const top = shell.getBoundingClientRect().top - doc.documentElement.getBoundingClientRect().top;
        const own = Math.max(shell.offsetHeight ?? 0, shell.scrollHeight ?? 0);
        // Whatever the document keeps below the shell: body padding, border and
        // margin, and the root's own padding.
        const view = doc.defaultView;
        let below = 0;
        if (view && doc.body) {
            const b = view.getComputedStyle(doc.body);
            const r = view.getComputedStyle(doc.documentElement);
            below = px(b.paddingBottom) + px(b.borderBottomWidth) + px(b.marginBottom)
                + px(r.paddingBottom) + px(r.borderBottomWidth);
        }
        return Math.ceil(top + own + below);
    } finally {
        for (const [prop, value, priority] of saved) {
            if (value) style.setProperty(prop, value, priority);
            else style.removeProperty(prop);
        }
    }
}

// Measure now and again whenever the content may have changed size; `onHeight`
// gets every positive measure. Returns an object with `disconnect()`, so callers
// keep the bookkeeping they had for a bare ResizeObserver.
//
// The document and body are not enough to observe once a shell is present: when
// content inside `main` shrinks, `main` grows by the same amount and nothing
// above it changes size. The shell's children and grandchildren — header, main,
// footer and the sections in them — do change, so they are observed too.
// `fonts.ready` catches a web font that lands after `load` and rewraps text.
export function observeContentHeight(doc, onHeight) {
    let live = true;
    const measure = () => {
        if (!live) return;
        const h = measureContentHeight(doc);
        if (h > 0) onHeight(h);
    };
    measure();

    let ro = null;
    if (typeof ResizeObserver === 'function') {
        ro = new ResizeObserver(measure);
        if (doc?.documentElement) ro.observe(doc.documentElement);
        if (doc?.body) ro.observe(doc.body);
        const shell = typeof doc?.querySelector === 'function' ? doc.querySelector(PAGE_SHELL_SELECTOR) : null;
        if (shell) {
            ro.observe(shell);
            for (const child of shell.children ?? []) {
                ro.observe(child);
                for (const grandchild of child.children ?? []) ro.observe(grandchild);
            }
        }
    }
    doc?.fonts?.ready?.then?.(measure, () => {});

    return {
        disconnect() {
            live = false;
            ro?.disconnect();
        },
    };
}
