// `pages.group_by: category` (styleguide.yaml): the sidebar's page entries
// grouped by their `category` metadata, the way component sections group
// their entries.
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
