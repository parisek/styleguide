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
// A content whose height follows its viewport (below, `followsViewport`) gets
// the same pinned height, for the same reason: a `100dvh` min-height on a component root
// makes its height a function of the iframe height in any render mode, not only
// in `chrome`.
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
// So a preview is measured with its iframe briefly set to PROBE_HEIGHT_PX.
// Every viewport floor then resolves to nothing, wherever it is declared, and
// scrollHeight reads the content, including anything in body outside the shell.
// Nothing in the page's own DOM is touched: only the iframe element in the SPA
// changes, and it is restored in the same task, so neither frame paints the
// probe and ResizeObserver, which samples at the next rendering step, never
// sees it.
//
// A component render takes the same probe: measured at its own height, a short
// component would grow to the pre-measure floor of the surface and never come
// back down. One whose content is all out of flow (a `<dialog>` overlay in the
// top layer, a fixed panel) measures zero at the probe and keeps the plain
// document measure, so it still gets a height. So does a double without a
// frame.
export function measureContentHeight(doc, shell = findPageShell(doc)) {
    return probe(doc, shell).height;
}

// The frame's own floors and ceiling are lifted with its height, so a
// `min-h-*` or `max-h-*` on the frame cannot hold a probe.
const PROBED_RANGE = ['height', 'min-height', 'max-height'];

function withFrameHeight(frame, px, read) {
    const style = frame.style;
    const saved = PROBED_RANGE.map((prop) => [prop, style.getPropertyValue(prop), style.getPropertyPriority(prop)]);
    style.setProperty('height', `${px}px`, 'important');
    style.setProperty('min-height', `${px}px`, 'important');
    style.setProperty('max-height', 'none', 'important');
    try {
        return read();
    } finally {
        for (const [prop, value, priority] of saved) {
            if (value) style.setProperty(prop, value, priority);
            else style.removeProperty(prop);
        }
    }
}

function probe(doc, shell) {
    const frame = doc?.defaultView?.frameElement;
    if (!frame?.style) return { height: documentHeight(doc), shellHeight: null };
    const probed = withFrameHeight(frame, PROBE_HEIGHT_PX, () => ({
        height: documentHeight(doc),
        shellHeight: shell ? shell.offsetHeight ?? 0 : null,
    }));
    if (!shell && probed.height <= 0) return { height: documentHeight(doc), shellHeight: null };
    return probed;
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

// The two iframe heights a component render is probed at to learn whether its
// content follows the viewport. The owner measured them on 96 renderable
// components of a Drupal consumer on v1.16.1, reading each component's
// own root (`[class*="component-"]`): the two that loop grew by 320 and 483 px
// between these heights, and nothing else by more than 4 px. The probe below
// reads the same roots (`contentElements()`), found by structure instead of by
// a class name.
export const VIEWPORT_PROBE_LOW_PX = 400;
export const VIEWPORT_PROBE_HIGH_PX = 1200;

// How much an element may grow between the two probes and still count as not
// following the viewport. A vertical scrollbar that shows at one probe and not
// at the other narrows the content and reflows text by a line or two, so the
// margin is well above a few pixels and well below the 320 px a looping
// component grows by.
export const VIEWPORT_TOLERANCE_PX = 64;

// The component roots: the in-flow children of the inset wrapper of
// render-cell.twig (`data-sg-inset`), or of body in the other render modes.
// Not the wrapper: when the project's body is a flex row or a grid with a
// viewport min-height, the wrapper stretches with the viewport while the
// component inside it keeps its own height. A `position: fixed` root adds
// nothing to the document height, so one that fills the viewport (an overlay)
// is not a loop; the hidden standalone bar, scripts and styles measure zero.
export const INSET_SELECTOR = '[data-sg-inset]';

function contentElements(doc) {
    const view = doc.defaultView;
    const inset = typeof doc.querySelector === 'function' ? doc.querySelector(INSET_SELECTOR) : null;
    const parent = inset ?? doc.body;
    return Array.from(parent?.children ?? []).filter((el) => {
        if (typeof view?.getComputedStyle !== 'function') return true;
        return view.getComputedStyle(el).position !== 'fixed';
    });
}

function heights(elements) {
    return elements.map((el) => el.getBoundingClientRect?.().height ?? 0);
}

// Whether the height of a component render follows the height of its iframe:
// `true`, `false`, or `null` when there is no answer (no frame to probe, no
// content, or content that measures zero at both heights, such as a popup
// hidden until it opens).
//
// The document is the wrong thing to ask. Its scrollHeight is never less than
// its viewport, so every component shorter than the high probe would answer
// yes. Its in-flow elements are the right thing: an element with a `100dvh`
// floor grows with the iframe, a short one keeps its own height, and one that
// is taller than any viewport never meets its floor and keeps its height too.
//
// Only the iframe element in the SPA changes, restored in the same task, as in
// `probe()` above.
export function followsViewport(doc) {
    const frame = doc?.defaultView?.frameElement;
    if (!frame?.style || !doc.body) return null;
    const elements = contentElements(doc);
    if (elements.length === 0) return null;
    const low = withFrameHeight(frame, VIEWPORT_PROBE_LOW_PX, () => heights(elements));
    const high = withFrameHeight(frame, VIEWPORT_PROBE_HIGH_PX, () => heights(elements));
    if (low.every((h) => h <= 0) && high.every((h) => h <= 0)) return null;
    return high.some((h, i) => h - low[i] > VIEWPORT_TOLERANCE_PX);
}

// Only a component render is asked whether it follows the viewport. A page
// render, with or without a shell, keeps its content height: measured at zero
// height it cannot loop, and a page is meant to be read whole, not pinned at a
// demo height. The SPA builds the render URLs, so the path says which kind a
// document is. A document without a location (a test double) counts as a
// component.
const PAGE_RENDER_PATH = /\/render\/page\//;

export function isComponentRender(doc) {
    const path = doc?.defaultView?.location?.pathname;
    return typeof path !== 'string' || !PAGE_RENDER_PATH.test(path);
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
//
// A component render whose content follows the viewport reports
// CHROME_VIEWPORT_HEIGHT_PX instead of its measure: sized from its own measure,
// the iframe would grow by the content's surround (the inset padding, say) on
// every measure until MAX_CONTENT_HEIGHT_PX. The question is asked of every
// document on every measure, so each grid tile gets its own answer, and a
// component that only starts to fill the viewport after its script runs is
// caught too. The second argument of `onHeight` says which of the two it was.
// A page render is not asked (`isComponentRender()`).
export function observeContentHeight(doc, onHeight) {
    const shell = findPageShell(doc);
    const fixFloor = !!shell && shellHasViewportFloor(doc, shell);
    let live = true;

    const measure = () => {
        if (!live) return;
        if (!shell && isComponentRender(doc) && followsViewport(doc) === true) {
            onHeight(CHROME_VIEWPORT_HEIGHT_PX, { followsViewport: true });
            return;
        }
        const { height: h, shellHeight } = probe(doc, shell);
        if (h <= 0) return;
        if (fixFloor && shellHeight != null) setFloorSurround(doc, Math.max(0, h - shellHeight));
        onHeight(h, { followsViewport: false });
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
