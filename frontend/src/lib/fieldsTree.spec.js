import { describe, it, expect } from 'vitest';
import { flattenFieldsTree } from './fieldsTree.js';

describe('flattenFieldsTree', () => {
    it('flattens a nested fields map into a depth-first list with dotted paths', () => {
        const fields = {
            title: { type: 'text', title: 'Title', required: true },
            items: {
                type: 'array',
                fields: {
                    label: { type: 'text', title: 'Label' },
                },
            },
        };
        const rows = flattenFieldsTree(fields);
        expect(rows).toEqual([
            { path: 'title', key: 'title', depth: 0, type: 'text', title: 'Title', description: '', required: true },
            { path: 'items', key: 'items', depth: 0, type: 'array', title: '', description: '', required: false },
            { path: 'items.label', key: 'label', depth: 1, type: 'text', title: 'Label', description: '', required: false },
        ]);
    });

    it('coerces a truthy/falsy YAML `required` (1/0) to a real boolean', () => {
        const rows = flattenFieldsTree({ a: { required: 1 }, b: { required: 0 } });
        expect(rows.map((r) => r.required)).toEqual([true, false]);
    });

    it('returns an empty array for null, non-object, or array input', () => {
        expect(flattenFieldsTree(null)).toEqual([]);
        expect(flattenFieldsTree(undefined)).toEqual([]);
        expect(flattenFieldsTree('nope')).toEqual([]);
        expect(flattenFieldsTree([])).toEqual([]);
    });

    it('skips a map entry whose value is not an object', () => {
        const rows = flattenFieldsTree({ bogus: 'not-an-object', real: { type: 'text' } });
        expect(rows.map((r) => r.key)).toEqual(['real']);
    });
});
