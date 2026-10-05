import { describe, it, expect } from 'vitest';
import { clampZoom, wheelZoom, stepZoom, fitAllZoom, zoomAround, ZOOM_MIN, ZOOM_MAX } from './boardZoom.js';

describe('boardZoom', () => {
    it('clamps to the range and survives garbage', () => {
        expect(clampZoom(0)).toBe(ZOOM_MIN);
        expect(clampZoom(99)).toBe(ZOOM_MAX);
        expect(clampZoom(NaN)).toBe(1);
    });

    it('zooms in on a negative wheel delta and out on a positive one', () => {
        expect(wheelZoom(1, -10)).toBeGreaterThan(1);
        expect(wheelZoom(1, 10)).toBeLessThan(1);
        expect(wheelZoom(1, 0)).toBe(1);
    });

    it('bounds one huge wheel delta', () => {
        expect(wheelZoom(1, -100000)).toBe(wheelZoom(1, -100));
    });

    it('steps by a fixed factor and never leaves the range', () => {
        expect(stepZoom(1, 1)).toBeCloseTo(1.25);
        expect(stepZoom(1, -1)).toBeCloseTo(0.8);
        expect(stepZoom(ZOOM_MAX, 1)).toBe(ZOOM_MAX);
        expect(stepZoom(ZOOM_MIN, -1)).toBe(ZOOM_MIN);
    });

    it('fits the whole surface and never above 100 %', () => {
        expect(fitAllZoom({ contentWidth: 4000, contentHeight: 1000, viewWidth: 1000, viewHeight: 800 })).toBeCloseTo(0.25);
        expect(fitAllZoom({ contentWidth: 100, contentHeight: 100, viewWidth: 1000, viewHeight: 800 })).toBe(1);
        expect(fitAllZoom({ contentWidth: 0, contentHeight: 0, viewWidth: 1000, viewHeight: 800 })).toBe(1);
    });

    it('lets the height run over when slack is given', () => {
        const args = { contentWidth: 1000, contentHeight: 4000, viewWidth: 1000, viewHeight: 1000 };
        expect(fitAllZoom(args)).toBeCloseTo(0.25);
        expect(fitAllZoom({ ...args, heightSlack: 2 })).toBeCloseTo(0.5);
    });

    it('keeps the point under the cursor fixed', () => {
        const before = { zoom: 0.5, scrollLeft: 300, scrollTop: 100, px: 200, py: 150 };
        const out = zoomAround({ ...before, next: 1 });
        const worldBefore = [(before.scrollLeft + before.px) / before.zoom, (before.scrollTop + before.py) / before.zoom];
        const worldAfter = [(out.scrollLeft + before.px) / out.zoom, (out.scrollTop + before.py) / out.zoom];
        expect(worldAfter[0]).toBeCloseTo(worldBefore[0]);
        expect(worldAfter[1]).toBeCloseTo(worldBefore[1]);
    });

    it('keeps the point under the cursor fixed with padding around the surface', () => {
        const before = { zoom: 0.5, scrollLeft: 300, scrollTop: 100, px: 200, py: 150, offsetX: 40, offsetY: 24 };
        const out = zoomAround({ ...before, next: 1 });
        const world = (z, sl, st) => [(sl + before.px - 40) / z, (st + before.py - 24) / z];
        expect(world(out.zoom, out.scrollLeft, out.scrollTop)[0]).toBeCloseTo(world(0.5, 300, 100)[0]);
        expect(world(out.zoom, out.scrollLeft, out.scrollTop)[1]).toBeCloseTo(world(0.5, 300, 100)[1]);
    });

    it('does not scroll to a negative position', () => {
        const out = zoomAround({ zoom: 1, next: 0.5, scrollLeft: 0, scrollTop: 0, px: 400, py: 300 });
        expect(out.scrollLeft).toBe(0);
        expect(out.scrollTop).toBe(0);
    });
});
