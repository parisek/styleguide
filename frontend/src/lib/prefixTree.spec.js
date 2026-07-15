import { describe, it, expect } from 'vitest';
import { buildTree, GROUP_MIN } from './prefixTree.js';

describe('GROUP_MIN', () => {
    it('is 3', () => {
        expect(GROUP_MIN).toBe(3);
    });
});

describe('buildTree', () => {
    it('groups a >=3 prefix cluster into a collapsible group node with suffix-only children', () => {
        const list = [
            { id: 'widget-one', name: 'Widget - one' },
            { id: 'widget-two', name: 'Widget - two' },
            { id: 'widget-three', name: 'Widget - three' },
        ];
        const tree = buildTree(list);
        expect(tree).toHaveLength(1);
        expect(tree[0].type).toBe('group');
        expect(tree[0].label).toBe('Widget');
        // Children keep the incoming (server/weight) order — no client re-sort.
        expect(tree[0].children.map((c) => c.leaf)).toEqual(['One', 'Two', 'Three']);
    });

    it('keeps a below-threshold (2-member) prefix cluster flat with full names', () => {
        const list = [
            { id: 'gadget-a', name: 'Gadget - a' },
            { id: 'gadget-b', name: 'Gadget - b' },
        ];
        const tree = buildTree(list);
        expect(tree.every((n) => n.type === 'item')).toBe(true);
        expect(tree.map((n) => n.item.name)).toEqual(['Gadget - a', 'Gadget - b']);
    });

    it('keeps a no-dash singleton flat with its full name', () => {
        const list = [{ id: 'gizmo', name: 'Gizmo' }];
        const tree = buildTree(list);
        expect(tree).toEqual([{ type: 'item', item: list[0], sortKey: 'Gizmo' }]);
    });

    it('preserves the incoming (server weight) order — a group sits where its first member appeared', () => {
        // The API already sorts by front-comment `weight` with a cs-collation
        // name tiebreak; re-sorting client-side would discard authored
        // weights (a weight-1 homepage rendered after "404").
        const list = [
            { id: 'z-item', name: 'Zebra' },
            { id: 'w1', name: 'Widget - one' },
            { id: 'w2', name: 'Widget - two' },
            { id: 'w3', name: 'Widget - three' },
            { id: 'a-item', name: 'Alfa' },
        ];
        const tree = buildTree(list);
        expect(tree.map((n) => n.sortKey)).toEqual(['Zebra', 'Widget', 'Alfa']);
    });

    it('falls back to id-keyed bucketing for items with no name', () => {
        const list = [{ id: 'no-name-item' }];
        const tree = buildTree(list);
        expect(tree[0]).toEqual({ type: 'item', item: list[0], sortKey: 'no-name-item' });
    });
});
