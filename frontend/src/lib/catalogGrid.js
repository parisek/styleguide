// Pure data and layout rules of the overview grid (GridView.vue, route
// /grid): which entries it shows, how the filter bar narrows them, and how
// many columns fit.
import { matchesQuery } from './searchMatch.js';

// Sidebar order, so the filter chips read like the sidebar sections. The
// default is the legacy order; with `components.group_by: kind` the view
// passes the store's own `componentSectionKeys` plus 'pages'.
export const GRID_SECTIONS = ['basic', 'blocks', 'gutenberg', 'pages'];

// Every renderable component and page, as one flat list of tiles: sidebar
// section order, then the server's order (weight, name) inside a section.
// `sectionOf` is the catalog store's own rule, passed in so the grid and the
// sidebar can never disagree about where an entry belongs.
//
// A tile previews the entry's default fixture. An entry with only named
// variants has no default, so its tile shows the first variant (the order of
// `variants` already honours `variants_order`). `variantCount` counts every
// tile the entry's own variant grid would show, the default included; 0
// means the entry has no variants at all.
export function gridEntries({ items = [], pages = [] }, sectionOf, order = GRID_SECTIONS) {
    const entries = [
        ...items.filter((i) => i.has_styleguide !== false).map((item) => toEntry(item, 'component', sectionOf(item, 'component'))),
        ...pages.filter((p) => p.has_styleguide !== false).map((page) => toEntry(page, 'page', 'pages')),
    ];
    const rank = (section) => {
        const index = order.indexOf(section);
        return index === -1 ? order.length : index;
    };
    // Array.prototype.sort is stable, so the server order survives.
    return entries.sort((a, b) => rank(a.section) - rank(b.section));
}

function toEntry(item, type, section) {
    const variants = Array.isArray(item.variants) ? item.variants : [];
    const hasDefault = item.has_default_variant !== false;
    return {
        key: `${type}:${item.id}`,
        type,
        id: item.id,
        name: item.name ?? item.id,
        section,
        item,
        variant: hasDefault ? null : (variants[0]?.id ?? null),
        variantCount: variants.length === 0 ? 0 : variants.length + (hasDefault ? 1 : 0),
    };
}

// `section` null means every section. The text filter is the sidebar's own
// substring match (name, id, aliases), so the two find the same entries.
export function filterGridEntries(entries, { section = null, query = '' } = {}) {
    return entries.filter((entry) => (!section || entry.section === section) && matchesQuery(entry.item, query));
}

// One count per section that has entries, in `order`, for the filter chips.
// A section with no entries gets no chip.
export function sectionCounts(entries, order = GRID_SECTIONS) {
    return order
        .map((section) => ({ section, count: entries.filter((e) => e.section === section).length }))
        .filter(({ count }) => count > 0);
}

export const GRID_GAP_PX = 16;
export const GRID_MIN_TILE_PX = 260;

// How many equal columns of at least `minTile` px fit into `containerWidth`,
// and how wide each one is. Computed here rather than left to CSS
// `auto-fill` because every tile needs its width to scale its iframe, and
// one measurement of the container is cheaper than one ResizeObserver per
// tile when there are hundreds.
export function gridLayout(containerWidth, { minTile = GRID_MIN_TILE_PX, gap = GRID_GAP_PX } = {}) {
    if (!(containerWidth > 0)) return { columns: 1, tileWidth: 0 };
    const columns = Math.max(1, Math.floor((containerWidth + gap) / (minTile + gap)));
    return { columns, tileWidth: (containerWidth - gap * (columns - 1)) / columns };
}

// The widths the grid's width toggle offers: the project's compare widths
// (`viewports.compare`), smallest first, or these when the project sets none.
export const GRID_DEFAULT_WIDTHS = [375, 768, 1280];

export function gridWidthOptions(projectWidths = null) {
    const widths = Array.isArray(projectWidths) && projectWidths.length > 0 ? projectWidths : GRID_DEFAULT_WIDTHS;
    return [...new Set(widths)].sort((a, b) => a - b);
}

// The stored choice if it is one of the options, else the widest: the
// desktop view, which is what a page is judged at first.
export function resolveGridWidth(stored, options) {
    return options.includes(stored) ? stored : options[options.length - 1];
}

// The logical frame a tile renders its entry in at `width`, before it is
// scaled to the tile. A desktop-wide frame is 16:10; a narrower one is
// square, so a phone tile shows the top of the page and stays as tall as
// the others.
export function gridPreviewSize(width) {
    return { width, height: Math.round(width >= 1024 ? width * 0.625 : width) };
}
