import { describe, it, expect } from 'vitest';
import { flattenFieldsTree, fieldsTypePill } from './fieldsTree.js';

const FIELDS = [
    { key: 'title', label: 'Nadpis', type: 'text', description: '', required: true, children: null, maxlength: 120, mcp: ['hint'] },
    { key: 'items', label: 'Položky', type: 'repeater', description: '', required: false, add_label: 'Přidat', children: [
        { key: 'label', label: 'Popisek', type: 'text', description: '', required: false, children: null },
    ] },
];

describe('flattenFieldsTree', () => {
    it('flattens the canonical list depth-first with dotted paths', () => {
        const rows = flattenFieldsTree(FIELDS);
        expect(rows.map((r) => r.path)).toEqual(['title', 'items', 'items.label']);
        expect(rows[2].depth).toBe(1);
    });

    it('exposes label and collects non-core keys into extras', () => {
        const rows = flattenFieldsTree(FIELDS);
        expect(rows[0].label).toBe('Nadpis');
        expect(rows[0].extras).toEqual({ maxlength: 120, mcp: ['hint'] });
        expect(rows[0].hasExtras).toBe(true);
        expect(rows[2].hasExtras).toBe(false);
    });

    it('tolerates junk input', () => {
        expect(flattenFieldsTree(null)).toEqual([]);
        expect(flattenFieldsTree({})).toEqual([]);
        expect(flattenFieldsTree([null, 'x'])).toEqual([]);
    });
});

describe('fieldsTypePill', () => {
    it('maps known types (case-insensitive) and falls back to neutral', () => {
        expect(fieldsTypePill('TEXT')).toContain('blue');
        expect(fieldsTypePill('media')).toContain('emerald');
        expect(fieldsTypePill('somenewtype')).toContain('zinc');
    });
});
