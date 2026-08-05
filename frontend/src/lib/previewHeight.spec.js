import { describe, it, expect } from 'vitest';
import {
    resolveContentHeight, entryScrolls,
    CHROME_VIEWPORT_HEIGHT_PX, MAX_CONTENT_HEIGHT_PX,
} from './previewHeight.js';

// The measurement a runaway chrome surface actually reported before the fix
// (2^25, the browser's element-height clamp).
const RUNAWAY = 33554400;

describe('resolveContentHeight', () => {
    it('ignores the measurement entirely for a chrome surface', () => {
        expect(resolveContentHeight({ rawContentHeight: RUNAWAY, scrolls: true }))
            .toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('pins a chrome surface even before any measurement exists', () => {
        expect(resolveContentHeight({ rawContentHeight: null, minHeight: 96, scrolls: true }))
            .toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('measures normally for a non-chrome surface', () => {
        expect(resolveContentHeight({ rawContentHeight: 1234, minHeight: 96 })).toBe(1234);
    });

    it('falls back to minHeight before the first measurement', () => {
        expect(resolveContentHeight({ rawContentHeight: null, minHeight: 96 })).toBe(96);
    });

    it('caps an absurd measurement without truncating a long-but-real fixture', () => {
        expect(resolveContentHeight({ rawContentHeight: RUNAWAY })).toBe(MAX_CONTENT_HEIGHT_PX);
        expect(resolveContentHeight({ rawContentHeight: 8000 })).toBe(8000);
    });
});

// One predicate for both surfaces, so the grid and the single preview cannot
// disagree about what counts as chrome — the drift that made the first fix for
// #116 incomplete.
describe('entryScrolls', () => {
    it('is true only for the chrome render mode', () => {
        expect(entryScrolls({ render: 'chrome' })).toBe(true);
        for (const render of ['inset', 'bleed', 'overlay']) {
            expect(entryScrolls({ render })).toBe(false);
        }
    });

    it('treats an absent, unknown or nullish entry as non-chrome', () => {
        expect(entryScrolls({})).toBe(false);
        expect(entryScrolls({ render: 'some-future-mode' })).toBe(false);
        expect(entryScrolls(null)).toBe(false);
        expect(entryScrolls(undefined)).toBe(false);
    });
});
