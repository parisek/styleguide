import { describe, it, expect } from 'vitest';
import { groupPagesByCategory, DEFAULT_GROUP_KEY } from './pageGroups.js';

const page = (id, category, weight = 50) => ({ id, name: id, category, weight });

describe('groupPagesByCategory', () => {
    it('groups pages by category and keeps their order inside a group', () => {
        const groups = groupPagesByCategory([
            page('home', 'Marketing', 10),
            page('boat', 'Catalogue', 20),
            page('about', 'Marketing', 30),
        ], 'Other');

        expect(groups.map((g) => g.label)).toEqual(['Marketing', 'Catalogue']);
        expect(groups[0].items.map((p) => p.id)).toEqual(['home', 'about']);
    });

    it('orders groups by their lowest weight, then by label', () => {
        const groups = groupPagesByCategory([
            page('a', 'Zeta', 5),
            page('b', 'Beta', 40),
            page('c', 'Alpha', 40),
            page('d', 'Beta', 60),
        ], 'Other');

        expect(groups.map((g) => g.label)).toEqual(['Zeta', 'Alpha', 'Beta']);
    });

    it('puts pages without a category into one default group, last', () => {
        const groups = groupPagesByCategory([
            page('orphan', '', 1),
            page('home', 'Marketing', 10),
            page('nocat', undefined, 2),
            page('spaces', '   ', 3),
        ], 'Other');

        expect(groups.map((g) => g.label)).toEqual(['Marketing', 'Other']);
        expect(groups[1].key).toBe(DEFAULT_GROUP_KEY);
        expect(groups[1].items.map((p) => p.id)).toEqual(['orphan', 'nocat', 'spaces']);
    });

    it('matches categories case-insensitively and labels the group as its first page writes it', () => {
        const groups = groupPagesByCategory([
            page('a', 'Blog ', 10),
            page('b', 'blog', 20),
        ], 'Other');

        expect(groups).toHaveLength(1);
        expect(groups[0].label).toBe('Blog');
        expect(groups[0].key).toBe('blog');
    });

    it('treats a missing weight as the parser default (50)', () => {
        const groups = groupPagesByCategory([
            { id: 'x', category: 'Late' },
            page('y', 'Early', 49),
        ], 'Other');

        expect(groups.map((g) => g.label)).toEqual(['Early', 'Late']);
    });

    it('returns no groups for no pages', () => {
        expect(groupPagesByCategory([], 'Other')).toEqual([]);
        expect(groupPagesByCategory(undefined, 'Other')).toEqual([]);
    });
});
