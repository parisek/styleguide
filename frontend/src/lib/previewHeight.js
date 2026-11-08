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
// `iframe.page_wrapper_class` — typically a flex column with a `100dvh`
// min-height and `main` set to `grow`.
export const PAGE_SHELL_SELECTOR = '[data-sg-page-shell]';

// The viewport height a shelled page is measured at. Zero, so that no floor in
// the page (the shell's, or one on body) can add height of its own: what is left
// is the content.
export const PROBE_HEIGHT_PX = 0;

const FLOOR_STYLE_ID = 'sg-shell-floor';

export function findPageShell(doc) {
    return typeof doc?.querySelector === 'function' ? doc.querySelector(PAGE_SHELL_SELECTOR) : null;
}

function documentHeight(doc) {
    return Math.max(doc?.documentElement?.scrollHeight ?? 0, doc?.body?.scrollHeight ?? 0);
}

// The height of the content in a same-origin preview document: the number every
// surface that sizes an iframe to its content feeds back into that iframe.
//
// The document's scrollHeight is never smaller than its viewport, and a shelled
// page's `100dvh` floor holds the content at the viewport height too. Sized from
// that, the iframe can grow and never shrink: any moment the content is taller
// than its settled state (the first paint before Alpine.js collapses something,
// a late font) fixes the height for good, with `main` grown into an empty band
// above the footer.
//
// So a shelled page is measured with its iframe briefly set to PROBE_HEIGHT_PX.
// Every viewport floor then resolves to nothing, wherever it is declared, and
// scrollHeight reads the content, including anything in body outside the shell.
// Nothing in the page's own DOM is touched: only the iframe element in the SPA
// changes, and it is restored in the same task, so neither frame paints the
// probe and ResizeObserver, which samples at the next rendering step, never
// sees it.
//
// A document without a shell, or a double without a frame, keeps the plain
// document measure, so a `<dialog>` overlay (all top layer, an empty body) still
// gets a height.
export function measureContentHeight(doc, shell = findPageShell(doc)) {
    return probe(doc, shell).height;
}

// The iframe element's own floors are lifted with its height, so a `min-h-*`
// on the frame cannot hold the probe open.
const PROBED = ['height', 'min-height'];

function probe(doc, shell) {
    const frame = doc?.defaultView?.frameElement;
    if (!shell || !frame?.style) return { height: documentHeight(doc), shellHeight: null };
    const style = frame.style;
    const saved = PROBED.map((prop) => [prop, style.getPropertyValue(prop), style.getPropertyPriority(prop)]);
    for (const prop of PROBED) style.setProperty(prop, `${PROBE_HEIGHT_PX}px`, 'important');
    try {
        return { height: documentHeight(doc), shellHeight: shell.offsetHeight ?? 0 };
    } finally {
        for (const [prop, value, priority] of saved) {
            if (value) style.setProperty(prop, value, priority);
            else style.removeProperty(prop);
        }
    }
}

// The iframe ends up exactly as tall as the content, plus whatever the document
// keeps around the shell (body padding for a fixed header, margins). A `100dvh`
// shell floor would then resolve to that whole height, taller than the shell's
// content by exactly that amount, and `main` would grow into a band of that size
// above the footer while the document scrolled by it inside the iframe. Taking
// the surround off the floor keeps the shell flush with its content when the
// page is taller than the floor, and still fills a short page down to the
// iframe's bottom edge.
//
// Only a shell whose floor is the viewport gets this, judged once at load, so a
// shell without one keeps its own min-height.
function shellHasViewportFloor(doc, shell) {
    const view = doc.defaultView;
    if (!view?.getComputedStyle) return false;
    const floor = parseFloat(view.getComputedStyle(shell).minHeight);
    return Number.isFinite(floor) && floor > 0 && Math.abs(floor - view.innerHeight) <= 1;
}

function setFloorSurround(doc, surround) {
    let el = doc.getElementById?.(FLOOR_STYLE_ID);
    if (!el) {
        if (!doc.head || typeof doc.createElement !== 'function') return;
        el = doc.createElement('style');
        el.id = FLOOR_STYLE_ID;
        doc.head.appendChild(el);
    }
    const css = `${PAGE_SHELL_SELECTOR}{min-height:calc(100dvh - ${surround}px) !important}`;
    if (el.textContent !== css) el.textContent = css;
}

// Measure now and again whenever the content may have changed size; `onHeight`
// gets every positive measure. Returns an object with `disconnect()`, so callers
// keep the bookkeeping they had for a bare ResizeObserver.
//
// Size changes alone miss a shrink inside a shelled page: `main` grows by what a
// section loses, and no box above it changes. So DOM changes anywhere in the
// document are a trigger too, batched to one measure per frame, and so is every
// web-font batch that finishes loading (`loadingdone`, not the `ready` promise,
// which is replaced each time a new batch starts).
export function observeContentHeight(doc, onHeight) {
    const shell = findPageShell(doc);
    const fixFloor = !!shell && shellHasViewportFloor(doc, shell);
    let live = true;

    const measure = () => {
        if (!live) return;
        const { height: h, shellHeight } = probe(doc, shell);
        if (h <= 0) return;
        if (fixFloor && shellHeight != null) setFloorSurround(doc, Math.max(0, h - shellHeight));
        onHeight(h);
    };

    const view = doc?.defaultView;
    let queued = false;
    const schedule = () => {
        if (queued || !live) return;
        queued = true;
        const run = () => { queued = false; measure(); };
        if (typeof view?.requestAnimationFrame === 'function') view.requestAnimationFrame(run);
        else run();
    };

    measure();

    let ro = null;
    if (typeof ResizeObserver === 'function') {
        ro = new ResizeObserver(measure);
        if (doc?.documentElement) ro.observe(doc.documentElement);
        if (doc?.body) ro.observe(doc.body);
    }
    let mo = null;
    // The page's own MutationObserver: a node of one document is observed
    // through that document's realm.
    const MO = view?.MutationObserver;
    if (shell && typeof MO === 'function' && doc?.documentElement) {
        mo = new MO(schedule);
        mo.observe(doc.documentElement, { subtree: true, childList: true, attributes: true, characterData: true });
    }
    const fonts = doc?.fonts;
    if (typeof fonts?.addEventListener === 'function') fonts.addEventListener('loadingdone', schedule);

    return {
        disconnect() {
            live = false;
            ro?.disconnect();
            mo?.disconnect();
            if (typeof fonts?.removeEventListener === 'function') fonts.removeEventListener('loadingdone', schedule);
        },
    };
}
