// Pure per-tile sizing math for VariantGrid.vue — split out from the
// component so the scaling rules (device preset applied uniformly to every
// tile, scaled down to fit each tile's own measured cell width) are testable
// without mounting a component or faking ResizeObserver/iframe navigation.
//
// `presetWidth`/`presetHeight` come straight from useViewportPreset's
// `effective` computed (the SAME preset the toolbar's viewport dropdown
// drives for the classic single preview — Full is `presetWidth === null`).
// `cellWidth` is the tile's own measured content-area width (VariantGrid's
// per-tile ResizeObserver registry). `rawContentHeight` is the tile's
// same-origin auto-measured iframe height (VariantGrid's `heights` registry)
// — only consulted when there's no canonical preset height (Full, or a
// fixed-width preset/Custom-width with no canonical height).
import { fitZoom } from './viewportMath.js';

// Full preset: fluid tile, no scaling, height is whatever the iframe's own
// content measures (or the caller's pre-measure floor before the first
// load/ResizeObserver tick).
export function computeTileGeometry({ presetWidth, presetHeight, cellWidth, rawContentHeight, minHeight }) {
    const contentHeight = rawContentHeight ?? minHeight ?? 0;
    if (presetWidth === null) {
        return {
            fluid: true, zoom: 1,
            iframeWidth: null, iframeHeight: contentHeight,
            wrapperWidth: null, wrapperHeight: null,
        };
    }
    // Only the WIDTH constrains the scale — a tile's cell has no fixed
    // height ceiling in either layout mode (rows: the row grows to fit;
    // grid: `align-items: start` lets each tile own its natural height), so
    // `availHeight` is omitted and fitZoom's height branch never engages.
    const zoom = fitZoom({ width: presetWidth, height: presetHeight, availWidth: cellWidth, availHeight: 0 });
    // Fixed-width preset without a canonical height (Custom width behaves
    // the same way) falls back to the tile's own measured content height,
    // exactly like the classic single preview's Custom-width mode.
    const logicalHeight = presetHeight ?? contentHeight;
    return {
        fluid: false,
        zoom,
        iframeWidth: presetWidth,
        iframeHeight: logicalHeight,
        wrapperWidth: Math.max(1, Math.round(presetWidth * zoom)),
        wrapperHeight: Math.max(1, Math.round(logicalHeight * zoom)),
    };
}

// "Auto" density (styleguide 2.0, replaces the old rows/grid toggle): the
// variant grid's minmax() basis derives from the shared viewport preset
// instead of a single fixed constant -- a Desktop preset (1280px effective
// width) shouldn't cram to the same per-row tile count as Mobile (375px).
// TILE_CHROME_PADDING_PX accounts for the fixed-width tile's own chrome
// around the scaled preview -- the content-area wrapper's `p-3` padding
// (12px each side, VariantGrid.vue) plus the scaled wrapper's `ring-1`
// border -- so a tile never has to zoom below 100% just to make room for
// its own frame. AUTO_GRID_FLUID_BASIS_PX is the Full-preset (no canonical
// width) fallback -- the original prototype's fixed 420px basis, kept
// unchanged since there's no preset width to derive a smarter one from.
export const AUTO_GRID_FLUID_BASIS_PX = 420;
export const TILE_CHROME_PADDING_PX = 32;

export function autoGridColumnBasis(presetWidth) {
    if (presetWidth === null || presetWidth === undefined) return AUTO_GRID_FLUID_BASIS_PX;
    return presetWidth + TILE_CHROME_PADDING_PX;
}
