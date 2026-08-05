import { describe, it, expect } from 'vitest';
import {
    computeTileGeometry, autoGridColumnBasis,
    AUTO_GRID_FLUID_BASIS_PX, TILE_CHROME_PADDING_PX,
} from './tileGeometry.js';
import { CHROME_VIEWPORT_HEIGHT_PX, MAX_CONTENT_HEIGHT_PX } from './previewHeight.js';

describe('computeTileGeometry', () => {
    it('is fluid (no scaling) for the Full preset (presetWidth null)', () => {
        const g = computeTileGeometry({ presetWidth: null, presetHeight: null, cellWidth: 600, rawContentHeight: 480, minHeight: 96 });
        expect(g).toEqual({ fluid: true, zoom: 1, iframeWidth: null, iframeHeight: 480, wrapperWidth: null, wrapperHeight: null });
    });

    it('falls back to minHeight when a Full tile has not measured any content height yet', () => {
        const g = computeTileGeometry({ presetWidth: null, presetHeight: null, cellWidth: 600, rawContentHeight: null, minHeight: 96 });
        expect(g.iframeHeight).toBe(96);
    });

    it('scales a fixed-width/fixed-height preset down to fit a narrower cell, never upscaling', () => {
        const g = computeTileGeometry({ presetWidth: 375, presetHeight: 667, cellWidth: 200, rawContentHeight: null, minHeight: 96 });
        expect(g.fluid).toBe(false);
        expect(g.zoom).toBeCloseTo(200 / 375, 10);
        expect(g.iframeWidth).toBe(375);
        expect(g.iframeHeight).toBe(667);
        expect(g.wrapperWidth).toBe(Math.round(375 * (200 / 375)));
        expect(g.wrapperHeight).toBe(Math.round(667 * (200 / 375)));
    });

    it('caps zoom at 1 (never upscales) when the cell is wider than the preset', () => {
        const g = computeTileGeometry({ presetWidth: 375, presetHeight: 667, cellWidth: 900, rawContentHeight: null, minHeight: 96 });
        expect(g.zoom).toBe(1);
        expect(g.wrapperWidth).toBe(375);
        expect(g.wrapperHeight).toBe(667);
    });

    it('uses the measured content height for a fixed-width preset with no canonical height (Custom width)', () => {
        const g = computeTileGeometry({ presetWidth: 500, presetHeight: null, cellWidth: 250, rawContentHeight: 800, minHeight: 96 });
        expect(g.iframeHeight).toBe(800);
        expect(g.zoom).toBeCloseTo(0.5, 10);
        expect(g.wrapperHeight).toBe(400);
    });

    it('treats a zero/unmeasured cell width as "not fitting" (fitZoom returns 1 when availWidth is falsy)', () => {
        const g = computeTileGeometry({ presetWidth: 375, presetHeight: 667, cellWidth: 0, rawContentHeight: null, minHeight: 96 });
        expect(g.zoom).toBe(1);
    });
});

// Auto density (styleguide 2.0): the variant grid's "Auto" column mode
// derives its minmax() basis from the shared preset instead of one fixed
// constant for every preset.
describe('autoGridColumnBasis', () => {
    it('falls back to the original 420px basis for the Full preset (no canonical width)', () => {
        expect(autoGridColumnBasis(null)).toBe(AUTO_GRID_FLUID_BASIS_PX);
        expect(autoGridColumnBasis(undefined)).toBe(AUTO_GRID_FLUID_BASIS_PX);
    });

    it('adds the fixed tile-chrome padding to a device preset\'s effective width', () => {
        expect(autoGridColumnBasis(375)).toBe(375 + TILE_CHROME_PADDING_PX);
        expect(autoGridColumnBasis(1280)).toBe(1280 + TILE_CHROME_PADDING_PX);
    });

    it('scales with the preset -- Mobile fits more per row than Desktop on the same canvas', () => {
        // Both bases are what auto-fit's minmax() divides the canvas width
        // by, so a SMALLER basis (Mobile) always packs at least as many
        // (usually more) columns into the same available width as a LARGER
        // one (Desktop).
        expect(autoGridColumnBasis(375)).toBeLessThan(autoGridColumnBasis(1280));
    });
});

// Regression: #116 -- a `render: chrome` component declares
// `body { min-height: 200vh }`, so its measured content height is a function
// of its viewport height. Sizing the tile to that measurement defines the two
// heights in terms of each other; the loop ran until Chrome clamped the
// element at 2^25px and killed the renderer. `scrolls` pins the tile so the
// viewport stops depending on the content.
describe('computeTileGeometry -- chrome tiles (scrolls)', () => {
    // The measurement a runaway chrome tile actually reported before the fix.
    const RUNAWAY = 33554400;

    it('ignores a runaway content height on the Full preset', () => {
        const g = computeTileGeometry({
            presetWidth: null, presetHeight: null, cellWidth: 620,
            rawContentHeight: RUNAWAY, scrolls: true,
        });
        expect(g.iframeHeight).toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('ignores a runaway content height on a fixed-width preset with no canonical height', () => {
        const g = computeTileGeometry({
            presetWidth: 375, presetHeight: null, cellWidth: 375,
            rawContentHeight: RUNAWAY, scrolls: true,
        });
        expect(g.iframeHeight).toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('still prefers a canonical preset height when the preset has one', () => {
        // This path never diverged -- it is why fixed-height presets were
        // unaffected -- so `scrolls` must not disturb it.
        const g = computeTileGeometry({
            presetWidth: 375, presetHeight: 812, cellWidth: 375,
            rawContentHeight: RUNAWAY, scrolls: true,
        });
        expect(g.iframeHeight).toBe(812);
    });

    it('leaves non-chrome tiles measuring their own content', () => {
        const g = computeTileGeometry({
            presetWidth: null, presetHeight: null, cellWidth: 620,
            rawContentHeight: 1234,
        });
        expect(g.iframeHeight).toBe(1234);
    });
});

// The cap is insurance, not the fix for #116: it turns any future feedback
// path into a reportable tile instead of a dead renderer.
describe('computeTileGeometry -- defensive content-height cap', () => {
    it('caps an absurd measured height', () => {
        const g = computeTileGeometry({
            presetWidth: null, presetHeight: null, cellWidth: 620,
            rawContentHeight: 33554400,
        });
        expect(g.iframeHeight).toBe(MAX_CONTENT_HEIGHT_PX);
    });

    it('does not truncate a legitimately long fixture', () => {
        const g = computeTileGeometry({
            presetWidth: null, presetHeight: null, cellWidth: 620,
            rawContentHeight: 8000,
        });
        expect(g.iframeHeight).toBe(8000);
    });
});
