import { describe, it, expect } from 'vitest';
import {
    computeTileGeometry, autoGridColumnBasis,
    AUTO_GRID_FLUID_BASIS_PX, TILE_CHROME_PADDING_PX,
} from './tileGeometry.js';

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
