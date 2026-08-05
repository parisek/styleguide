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
