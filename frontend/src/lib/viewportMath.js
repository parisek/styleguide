// Pure viewport/zoom/orientation math, ported from
// frontend/components/preview.js (effectiveWidth/effectiveHeight/zoom/
// isPortrait/setPortrait getters) and frontend/stores/ui.js
// (parseWidthParam). No DOM, no store access — callers supply the raw
// numbers (current width/height/rotation flag, measured container size).

export const VIEWPORTS = [
    { key: 'mobile-s',   label: 'Mobile S',   category: 'mobile',  width: 320,  height: 568  },
    { key: 'mobile',     label: 'Mobile',     category: 'mobile',  width: 375,  height: 667  },
    { key: 'mobile-l',   label: 'Mobile L',   category: 'mobile',  width: 425,  height: 812  },
    { key: 'tablet',     label: 'Tablet',     category: 'tablet',  width: 768,  height: 1024 },
    { key: 'tablet-l',   label: 'Tablet L',   category: 'tablet',  width: 1024, height: 1366 },
    { key: 'desktop',    label: 'Desktop',    category: 'desktop', width: 1280, height: 800  },
    { key: 'desktop-l',  label: 'Desktop L',  category: 'desktop', width: 1536, height: 960  },
    { key: 'desktop-xl', label: 'Desktop XL', category: 'desktop', width: 1920, height: 1080 },
    { key: 'desktop-2k', label: 'Desktop 2K', category: 'desktop', width: 2560, height: 1440 },
    { key: 'full',       label: 'Full',       category: 'full',    width: null, height: null },
];

export const CUSTOM_WIDTH_MIN = 100;
export const CUSTOM_WIDTH_MAX = 4000;

export function findPresetByWidth(width) {
    return VIEWPORTS.find((v) => v.width === width) ?? null;
}

// Accepts 'full' / '100%' / a strict positive integer in [MIN, MAX]. The
// all-digits regex pre-check rejects '375.5' / '375px' / '375junk' (all of
// which `parseInt` would silently coerce to 375) so a malformed input never
// quietly resolves to an unintended width.
export function parseWidthParam(raw) {
    if (!raw) return null;
    if (raw === 'full' || raw === '100%') return '100%';
    if (!/^\d+$/.test(raw)) return null;
    const px = Number(raw);
    if (Number.isInteger(px) && px >= CUSTOM_WIDTH_MIN && px <= CUSTOM_WIDTH_MAX) return `${px}px`;
    return null;
}

// Effective (post-rotation) display dimensions. Rotation only applies when a
// canonical height exists (device presets) — Full (width null) and Custom
// (height null) pass through unchanged.
export function effectiveDims({ width, height, rotated }) {
    if (width === null || height === null) return { width, height };
    if (rotated) return { width: height, height: width };
    return { width, height };
}

// Fit-to-bounds scale factor: shrink (never enlarge — capped at 1) so the
// whole effective box fits inside the available container on both axes.
export function fitZoom({ width, height, availWidth, availHeight }) {
    if (!width || !availWidth) return 1;
    let z = availWidth / width;
    if (height && availHeight) z = Math.min(z, availHeight / height);
    return Math.min(1, z);
}

// Absolute portrait/landscape, derived from EFFECTIVE (post-rotation)
// dimensions — not the raw `rotated` flag, which is relative to the
// preset's own canonical shape (a landscape-canonical Desktop with
// rotated=true is actually portrait). Returns false when there is no
// canonical height (Full/Custom).
export function isPortraitOrientation({ width, height, rotated }) {
    if (height === null) return false;
    const dispW = rotated ? height : width;
    const dispH = rotated ? width : height;
    return dispH > dispW;
}

// Inverse of isPortraitOrientation: given a desired ABSOLUTE orientation,
// compute the `rotated` flag relative to the preset's canonical shape.
export function rotationForPortrait({ width, height, portrait }) {
    if (height === null) return false;
    const canonicalLandscape = width > height;
    return portrait ? canonicalLandscape : !canonicalLandscape;
}

// Compare mode shows 2 to COMPARE_MAX widths side by side. More columns
// shrink every iframe below legibility on a laptop screen.
export const COMPARE_MAX = 4;

const narrowestFirst = (a, b) => a - b;

// Checks or unchecks one width in the compared set. The result is narrowest
// first, the order the strip shows: mobile first, as the CSS is written. The last width cannot be unchecked:
// one width is the ordinary single preview, never an empty one.
export function toggleWidth(selected, width, max = COMPARE_MAX) {
    const set = new Set(selected);
    if (set.has(width)) {
        if (set.size > 1) set.delete(width);
    } else if (set.size < max) {
        set.add(width);
    }
    return [...set].sort(narrowestFirst);
}

// Guards a persisted set: localStorage outlives releases and can be edited
// by hand. Anything that is not 2 to COMPARE_MAX valid widths means no
// comparison.
export function sanitizeCompareWidths(value) {
    if (!Array.isArray(value)) return [];
    const valid = value.every((w) => Number.isInteger(w) && w >= CUSTOM_WIDTH_MIN && w <= CUSTOM_WIDTH_MAX);
    const unique = [...new Set(value)].sort(narrowestFirst);
    return valid && unique.length >= 2 && unique.length <= COMPARE_MAX ? unique : [];
}

// A row label for any width: the preset's own name, else the device class
// the width falls in. `viewports.compare` widths such as 1440 are rarely
// presets, and "1440 · Desktop" reads better than a bare number.
export function widthLabel(width) {
    const preset = findPresetByWidth(width);
    if (preset) return preset.label;
    return { mobile: 'Mobile', tablet: 'Tablet', desktop: 'Desktop' }[widthCategory(width)];
}

// The device class of any width, for the row icon: the preset's own
// category, else the class the width falls in.
export function widthCategory(width) {
    const preset = findPresetByWidth(width);
    if (preset) return preset.category;
    if (width < 768) return 'mobile';
    if (width < 1280) return 'tablet';
    return 'desktop';
}
