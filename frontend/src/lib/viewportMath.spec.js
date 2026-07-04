import { describe, it, expect } from 'vitest';
import {
    VIEWPORTS,
    CUSTOM_WIDTH_MIN,
    CUSTOM_WIDTH_MAX,
    findPresetByWidth,
    parseWidthParam,
    effectiveDims,
    fitZoom,
    isPortraitOrientation,
    rotationForPortrait,
} from './viewportMath.js';

describe('VIEWPORTS', () => {
    it('has 10 presets including the unconstrained Full preset', () => {
        expect(VIEWPORTS).toHaveLength(10);
        expect(VIEWPORTS.find((v) => v.key === 'full')).toEqual({
            key: 'full', label: 'Full', category: 'full', width: null, height: null,
        });
    });
});

describe('findPresetByWidth', () => {
    it('finds the Desktop preset by exact width', () => {
        expect(findPresetByWidth(1280)?.key).toBe('desktop');
    });

    it('returns null for a non-preset width', () => {
        expect(findPresetByWidth(999)).toBeNull();
    });
});

describe('parseWidthParam', () => {
    it('accepts a valid integer width in range', () => {
        expect(parseWidthParam('375')).toBe('375px');
    });

    it('accepts "full" and "100%" as the Full preset', () => {
        expect(parseWidthParam('full')).toBe('100%');
        expect(parseWidthParam('100%')).toBe('100%');
    });

    it('rejects a decimal (would otherwise silently truncate via parseInt)', () => {
        expect(parseWidthParam('375.5')).toBeNull();
    });

    it('rejects a value below the minimum', () => {
        expect(parseWidthParam('99')).toBeNull();
    });

    it('rejects a value above the maximum', () => {
        expect(parseWidthParam('4001')).toBeNull();
    });

    it('rejects garbage suffixed onto digits', () => {
        expect(parseWidthParam('375px')).toBeNull();
    });

    it('returns null for empty/falsy input', () => {
        expect(parseWidthParam('')).toBeNull();
        expect(parseWidthParam(null)).toBeNull();
    });
});

describe('effectiveDims', () => {
    it('swaps width/height when rotated (Desktop 1280x800 -> 800x1280)', () => {
        expect(effectiveDims({ width: 1280, height: 800, rotated: true })).toEqual({ width: 800, height: 1280 });
    });

    it('keeps canonical order when not rotated', () => {
        expect(effectiveDims({ width: 1280, height: 800, rotated: false })).toEqual({ width: 1280, height: 800 });
    });

    it('is a no-op for Full mode (width null)', () => {
        expect(effectiveDims({ width: null, height: null, rotated: true })).toEqual({ width: null, height: null });
    });

    it('is a no-op for Custom widths (height null) even when rotated is true', () => {
        expect(effectiveDims({ width: 500, height: null, rotated: true })).toEqual({ width: 500, height: null });
    });
});

describe('fitZoom', () => {
    it('caps at 1 when the preset fits within the container', () => {
        expect(fitZoom({ width: 375, height: 667, availWidth: 1200, availHeight: 900 })).toBe(1);
    });

    it('fits both axes uniformly (Desktop 2K on a 1280x800 laptop pane)', () => {
        const z = fitZoom({ width: 2560, height: 1440, availWidth: 1280, availHeight: 800 });
        expect(z).toBeCloseTo(0.5, 5);
    });

    it('returns 1 for Full mode (width falsy)', () => {
        expect(fitZoom({ width: null, height: null, availWidth: 1280, availHeight: 800 })).toBe(1);
    });

    it('returns 1 when the container has not been measured yet (availWidth 0)', () => {
        expect(fitZoom({ width: 1920, height: 1080, availWidth: 0, availHeight: 0 })).toBe(1);
    });
});

describe('isPortraitOrientation', () => {
    it('reports portrait for a rotated Desktop 1280x800 (-> 800x1280)', () => {
        expect(isPortraitOrientation({ width: 1280, height: 800, rotated: true })).toBe(true);
    });

    it('reports landscape for an un-rotated Desktop 1280x800', () => {
        expect(isPortraitOrientation({ width: 1280, height: 800, rotated: false })).toBe(false);
    });

    it('reports portrait for a canonically-portrait Mobile 375x667', () => {
        expect(isPortraitOrientation({ width: 375, height: 667, rotated: false })).toBe(true);
    });

    it('returns false (landscape) when there is no canonical height (Full/Custom)', () => {
        expect(isPortraitOrientation({ width: 500, height: null, rotated: false })).toBe(false);
    });
});

describe('rotationForPortrait', () => {
    it('computes rotated=false to reach portrait on a portrait-canonical Mobile preset', () => {
        expect(rotationForPortrait({ width: 375, height: 667, portrait: true })).toBe(false);
    });

    it('computes rotated=true to reach portrait on a landscape-canonical Desktop preset', () => {
        expect(rotationForPortrait({ width: 1280, height: 800, portrait: true })).toBe(true);
    });

    it('is a no-op (false) when there is no canonical height', () => {
        expect(rotationForPortrait({ width: 500, height: null, portrait: true })).toBe(false);
    });
});

describe('constants', () => {
    it('CUSTOM_WIDTH_MIN/MAX match the legacy sanity range', () => {
        expect(CUSTOM_WIDTH_MIN).toBe(100);
        expect(CUSTOM_WIDTH_MAX).toBe(4000);
    });
});
