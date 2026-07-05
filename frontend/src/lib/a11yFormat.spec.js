import { describe, it, expect } from 'vitest';
import { formatAxeResults } from './a11yFormat.js';

const axeResults = (violations) => ({ violations, passes: [], incomplete: [], inapplicable: [] });

describe('formatAxeResults', () => {
    it('groups violations by impact in a stable critical→minor order', () => {
        const result = formatAxeResults(axeResults([
            { id: 'color-contrast', impact: 'serious', description: 'Contrast', help: 'Fix contrast', helpUrl: '#', nodes: [] },
            { id: 'image-alt', impact: 'critical', description: 'Alt text', help: 'Add alt', helpUrl: '#', nodes: [{ target: ['img'], html: '<img src="x">' }] },
        ]));
        expect(Object.keys(result.byImpact)).toEqual(['critical', 'serious', 'moderate', 'minor']);
        expect(result.byImpact.critical).toHaveLength(1);
        expect(result.byImpact.critical[0].id).toBe('image-alt');
        expect(result.byImpact.moderate).toEqual([]);
        expect(result.total).toBe(2);
    });

    it('preserves node targets for locating the offending element', () => {
        const result = formatAxeResults(axeResults([
            { id: 'image-alt', impact: 'critical', description: 'Alt text', help: 'Add alt', helpUrl: '#', nodes: [{ target: ['img.hero'], html: '<img class="hero" src="x">' }] },
        ]));
        expect(result.byImpact.critical[0].nodes[0].target).toEqual(['img.hero']);
    });

    it('returns all-empty groups and total 0 for a clean page', () => {
        const result = formatAxeResults(axeResults([]));
        expect(result.total).toBe(0);
        expect(Object.values(result.byImpact).every((g) => g.length === 0)).toBe(true);
    });

    it('treats a null/undefined impact as "minor" rather than dropping the violation', () => {
        const result = formatAxeResults(axeResults([
            { id: 'unknown-rule', impact: null, description: 'x', help: 'x', helpUrl: '#', nodes: [] },
        ]));
        expect(result.byImpact.minor).toHaveLength(1);
        expect(result.total).toBe(1);
    });
});
