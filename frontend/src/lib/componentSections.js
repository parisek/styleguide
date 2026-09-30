// Which sidebar section a component belongs to, and in what order the
// sections read. Two rules, picked by `components.group_by` in
// styleguide.yaml (#sg-config `componentsGroupBy`):
//
// - Legacy (key absent): the section comes from `category`. "gutenberg" has
//   its own section, "block", "blocks" and "layout" are Blocks, anything
//   else is Basic. `category` is a free label, so a project that uses it for
//   something else (a Relume category, say) lands nearly everything in Basic.
// - `kind`: the section comes from `kind`, the closed list the parser
//   validates. `category` is then free to name the groups inside a section.
//   A component without a valid kind falls back to the legacy rule, so a
//   half-migrated catalogue loses nothing.
//
// Pages have their own section under both rules.
export const KIND_SECTIONS = {
    block: 'blocks',
    section: 'sections',
    element: 'basic',
    part: 'parts',
    utility: 'utilities',
};

const LEGACY_ORDER = ['basic', 'blocks', 'gutenberg'];
// Atomic before composite, the way a page is built: the elements, the
// blocks made of them, then the page chrome that frames the blocks. Pages
// follow as their own section. `gutenberg` stays for the legacy fallback of
// a component without a kind, next to the blocks.
const KIND_ORDER = ['basic', 'parts', 'blocks', 'gutenberg', 'sections', 'utilities'];

export function legacySectionOf(item) {
    const cat = (item?.category ?? '').toLowerCase();
    if (cat === 'gutenberg') return 'gutenberg';
    if (['block', 'blocks', 'layout'].includes(cat)) return 'blocks';
    return 'basic';
}

export function sectionOf(item, groupBy = null) {
    if (groupBy === 'kind') {
        const section = KIND_SECTIONS[item?.kind];
        if (section) return section;
    }
    return legacySectionOf(item);
}

export function sectionOrder(groupBy = null) {
    return groupBy === 'kind' ? KIND_ORDER : LEGACY_ORDER;
}

// With `group_by: kind`, the entries of one section grouped by `category`.
// Same node shape as prefixTree.buildTree(), so the sidebar renders both the
// same way. A category with fewer than CATEGORY_GROUP_MIN entries stays a
// flat item: twenty groups of one entry each are harder to scan than a
// list. Categories match case-insensitively after trimming; a group takes
// the spelling of its first entry and sits where that entry first appears,
// so the server order (weight, then name) survives. A child's `leaf` is its
// full name: a category is not a prefix of the name.
export const CATEGORY_GROUP_MIN = 2;

export function buildCategoryTree(list) {
    const buckets = new Map();
    for (const it of list ?? []) {
        const raw = typeof it?.category === 'string' ? it.category.trim() : '';
        const key = raw === '' ? ` ${it.id}` : raw.toLocaleLowerCase();
        if (!buckets.has(key)) buckets.set(key, { label: raw, items: [] });
        buckets.get(key).items.push(it);
    }
    const nodes = [];
    for (const b of buckets.values()) {
        if (b.label !== '' && b.items.length >= CATEGORY_GROUP_MIN) {
            const children = b.items.map((it) => ({ ...it, leaf: it.name ?? it.id }));
            nodes.push({ type: 'group', label: b.label, sortKey: b.label, children });
        } else {
            for (const it of b.items) nodes.push({ type: 'item', item: it, sortKey: it.name ?? it.id });
        }
    }
    return nodes;
}
