// `pages.group_by: category` (styleguide.yaml): the sidebar's page entries
// grouped by their `category` metadata.
//
// - Categories match case-insensitively after trimming; a group's label is
//   the spelling of its first page (pages arrive sorted by weight, then name,
//   from ComponentParser::parseAll()).
// - Groups sort by the lowest `weight` among their pages, then by label.
// - Pages keep the order they arrived in inside their group.
// - Pages without a category form one default group, always last: it holds
//   the leftovers, and its place must not depend on the translated label.
export const DEFAULT_GROUP_KEY = '';

export function groupPagesByCategory(pages, defaultLabel) {
    const groups = new Map();
    for (const page of pages ?? []) {
        const raw = typeof page?.category === 'string' ? page.category.trim() : '';
        const key = raw.toLocaleLowerCase();
        if (!groups.has(key)) {
            groups.set(key, { key, label: raw === '' ? defaultLabel : raw, items: [], minWeight: Infinity });
        }
        const group = groups.get(key);
        group.items.push(page);
        const weight = Number.isFinite(page?.weight) ? page.weight : 50;
        group.minWeight = Math.min(group.minWeight, weight);
    }

    const named = [...groups.values()].filter((g) => g.key !== DEFAULT_GROUP_KEY);
    named.sort((a, b) => (a.minWeight - b.minWeight) || a.label.localeCompare(b.label, 'cs'));
    const fallback = groups.get(DEFAULT_GROUP_KEY);

    return [...named, ...(fallback ? [fallback] : [])]
        .map(({ key, label, items }) => ({ key, label, items }));
}

// The grid's entries as the board's rows: one group per sidebar section
// (`sections.<section>` names it), in the order the entries arrive. With
// `pages.group_by: category` the pages follow the sidebar's category groups,
// one row each: the Pages section is then a heading (level 1, no entries of its
// own) and each category a group inside it (level 2). A section with no entries
// has no group.
export function boardGroups(entries, { groupBy = null, sectionLabel = (section) => section, defaultLabel = '' } = {}) {
    const groups = [];
    for (const entry of entries) {
        if (entry.type === 'page' && groupBy === 'category') continue;
        let group = groups.find((g) => g.key === entry.section);
        if (!group) {
            group = { key: entry.section, label: sectionLabel(entry.section), level: 1, items: [] };
            groups.push(group);
        }
        group.items.push(entry);
    }
    if (groupBy !== 'category') return groups;
    const pages = entries.filter((e) => e.type === 'page');
    const byId = new Map(pages.map((e) => [e.id, e]));
    if (pages.length === 0) return groups;
    const pageGroups = groupPagesByCategory(pages.map((e) => e.item), defaultLabel)
        .map((g) => ({ key: `category:${g.key}`, label: g.label, level: 2, items: g.items.map((item) => byId.get(item.id)) }));
    return [...groups, { key: 'pages', label: sectionLabel('pages'), level: 1, items: [] }, ...pageGroups];
}
