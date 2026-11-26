import { describe, expect, it } from 'vitest';
import { isSameDocument } from './useEntryLinks.js';

const here = 'https://example.test/styleguide/render/doc/intro?theme=dark&locale=en';

describe('isSameDocument', () => {
    it('is true for a place in the same render, query included', () => {
        expect(isSameDocument(`${here}#b`, here)).toBe(true);
        expect(isSameDocument('#b', here)).toBe(true);
    });

    it('ignores the fragment the frame already shows', () => {
        expect(isSameDocument(`${here}#b`, `${here}#a`)).toBe(true);
    });

    it('is false for a bare # (no place)', () => {
        expect(isSameDocument(`${here}#`, here)).toBe(false);
        expect(isSameDocument(here, here)).toBe(false);
    });

    it('is false for another query, path or origin', () => {
        expect(isSameDocument('https://example.test/styleguide/render/doc/intro?theme=dark#b', here)).toBe(false);
        expect(isSameDocument('https://example.test/#b', here)).toBe(false);
        expect(isSameDocument('https://other.test/styleguide/render/doc/intro?theme=dark&locale=en#b', here)).toBe(false);
    });

    it('is false for a URL it cannot parse', () => {
        expect(isSameDocument('http://[', here)).toBe(false);
    });
});
