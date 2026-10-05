import { describe, it, expect } from 'vitest';
import { boardGroups, groupPagesByCategory, DEFAULT_GROUP_KEY } from './pageGroups.js';

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

describe('boardGroups', () => {
    const entry = (id, type, section, category = '') => ({ type, id, section, item: { id, name: id, category, weight: 50 } });
    const label = (section) => `L:${section}`;

    it('makes one group per section, in the order the entries arrive', () => {
        const entries = [entry('a', 'component', 'basic'), entry('b', 'component', 'basic'), entry('c', 'component', 'gutenberg'), entry('p', 'page', 'pages')];
        const out = boardGroups(entries, { sectionLabel: label });
        expect(out.map((g) => [g.key, g.label, g.items.map((e) => e.id)])).toEqual([
            ['basic', 'L:basic', ['a', 'b']],
            ['gutenberg', 'L:gutenberg', ['c']],
            ['pages', 'L:pages', ['p']],
        ]);
    });

    it('splits the pages by category, after the components, with group_by category', () => {
        const entries = [entry('btn', 'component', 'basic'), entry('p1', 'page', 'pages', 'Site'), entry('p2', 'page', 'pages', 'Help'), entry('p3', 'page', 'pages', 'Site')];
        const out = boardGroups(entries, { groupBy: 'category', sectionLabel: label, defaultLabel: 'Other' });
        expect(out.map((g) => [g.label, g.items.map((e) => e.id)])).toEqual([
            ['L:basic', ['btn']],
            ['Help', ['p2']],
            ['Site', ['p1', 'p3']],
        ]);
    });
});
