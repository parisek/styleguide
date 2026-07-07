import { describe, it, expect } from 'vitest';
import { computeTileGeometry, formatTileScaleLabel } from './tileGeometry.js';

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

describe('formatTileScaleLabel', () => {
    it('formats "W × H · pct %" for a scaled tile', () => {
        const label = formatTileScaleLabel({ fluid: false, zoom: 200 / 375, iframeWidth: 375, iframeHeight: 667 });
        expect(label).toBe('375 × 667 · 53 %');
    });

    it('rounds to 100 % (not upscaled) when zoom is exactly 1', () => {
        const label = formatTileScaleLabel({ fluid: false, zoom: 1, iframeWidth: 1280, iframeHeight: 800 });
        expect(label).toBe('1280 × 800 · 100 %');
    });

    it('is empty for a fluid (Full preset) tile', () => {
        expect(formatTileScaleLabel({ fluid: true, zoom: 1, iframeWidth: null, iframeHeight: 480 })).toBe('');
    });

    it('is empty when called with no geometry (defensive)', () => {
        expect(formatTileScaleLabel(null)).toBe('');
    });
});
