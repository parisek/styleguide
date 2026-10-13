// Which sidebar section a component belongs to, and in what order the
// sections read. Two rules, picked by `components.group_by` in
// styleguide.yaml (#sg-config `componentsGroupBy`):
//
// - Legacy (key absent): the section comes from `category`. "gutenberg" has
//   its own section, "block", "blocks" and "layout" are Blocks, anything
//   else is Basic. `category` is a free label, so a project that uses it for
//   something else (a Relume category, say) lands nearly everything in Basic.
// - `kind`: the section comes from `kind`, the closed list the parser
//   validates. `category` is then free for what it names.
//   A component without a valid kind falls back to the legacy rule, so a
//   half-migrated catalogue loses nothing.
//
// - `sections` (#sg-config `componentsSections`): the server resolved the
//   section of every component and names it in `item.section`. The list is
//   the sidebar order. The tiers live in PHP (`ComponentSections`), once;
//   nothing here repeats them.
//
// Pages have their own section under every rule.
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

// The id every unmatched component gets from the server, last in the list.
export const OTHER_SECTION = 'other';

export function sectionOf(item, groupBy = null, sections = null) {
    if (Array.isArray(sections)) {
        const id = item?.section;
        return sections.some((s) => s.id === id) ? id : OTHER_SECTION;
    }
    if (groupBy === 'kind') {
        const section = KIND_SECTIONS[item?.kind];
        if (section) return section;
    }
    return legacySectionOf(item);
}

export function sectionOrder(groupBy = null, sections = null) {
    if (Array.isArray(sections)) return sections.map((s) => s.id);
    return groupBy === 'kind' ? KIND_ORDER : LEGACY_ORDER;
}

// The heading a configured section wrote, in `locale`: a string is the same
// in every language; a map reads the chrome language (`cs_CZ` reads `cs`),
// then the default locale's language, then its first value. Null when the
// entry wrote no label: the caller then uses the SPA locale's heading.
export function configuredLabel(entry, locale = '', fallbackLocale = '') {
    const label = entry?.label;
    if (typeof label === 'string') return label;
    if (!label || typeof label !== 'object') return null;
    const short = (code) => String(code || '').slice(0, 2).toLowerCase();
    for (const code of [short(locale), short(fallbackLocale)]) {
        if (code && typeof label[code] === 'string') return label[code];
    }
    const first = Object.values(label).find((v) => typeof v === 'string');
    return first ?? null;
}
