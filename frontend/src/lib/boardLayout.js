// Pure layout of the board (BoardSurface.vue): where each frame, each group
// heading and each section rule sits on the surface. All numbers are logical
// pixels, before the view scales the surface.
//
// One row per group, in the order given (the sidebar's), frames left to right
// inside the row. So zoomed out, the whole catalogue reads as a few rows. A row
// is as tall as its tallest frame, so a page measured taller than its
// neighbours moves the rows below it, not its neighbours.
//
// Two levels of group. A section (level 1: Basic elements, Blocks, Pages) is
// told from the groups inside it (level 2: the page categories) by space and by
// a rule. The gap above a heading is larger than the gap below it, so a heading
// reads as the start of what follows and never as the end of what came before.
// A level-1 group with no entries of its own is a heading for the level-2 groups
// that follow.
//
// Above each row sit, from the top: the gap, the heading, a small space, and the
// band for each frame's name. The bands are fixed and the view caps its text
// sizes to them, so a name can never reach the row above.
export const BOARD_GAP_PX = 120;
export const BOARD_NAME_BAND_PX = 90;
// What lies between a row's frames and whatever comes next.
export const BOARD_ROW_GAP_PX = 60;
// The space above a heading, by level; a level-2 heading right under its
// section heading needs less.
export const BOARD_SPACE_ABOVE_PX = { 1: 300, 2: 160 };
export const BOARD_SPACE_AFTER_SECTION_PX = 60;
// The height of a heading's line, by level, and the space under it.
export const BOARD_HEAD_HEIGHT_PX = { 1: 120, 2: 84 };
export const BOARD_HEAD_GAP_PX = 40;
// "Fit all" lets the surface run this many views tall (see fitAllZoom).
export const BOARD_FIT_HEIGHT_SLACK = 4;

// `groups` is [{ key, label, level, items }] in display order (`level` is 1
// when absent); each item has a `key`. `heightOf(key)` is the frame's height
// now: the measured one, or a floor before the page has loaded.
export function boardLayout({ groups, width, heightOf, gap = BOARD_GAP_PX }) {
    const frames = [];
    const heads = [];
    const rules = [];
    let y = 0;
    let widest = 0;
    let started = false;
    let afterSectionHead = false;
    for (const group of groups) {
        const level = group.level === 2 ? 2 : 1;
        if (group.items.length === 0 && level === 2) continue;
        let above = 0;
        if (started) above = level === 2 && afterSectionHead ? BOARD_SPACE_AFTER_SECTION_PX : BOARD_SPACE_ABOVE_PX[level];
        // A rule marks the start of a section, in the upper part of its gap.
        if (started && level === 1) rules.push({ key: `rule-${group.key}`, y: y + above * 0.35 });
        heads.push({ key: group.key, label: group.label, level, count: group.items.length, x: 0, y: y + above });
        y += above + BOARD_HEAD_HEIGHT_PX[level] + BOARD_HEAD_GAP_PX;
        started = true;
        if (group.items.length === 0) {
            afterSectionHead = true;
            continue;
        }
        afterSectionHead = false;
        y += BOARD_NAME_BAND_PX;
        let x = 0;
        let rowHeight = 0;
        for (const item of group.items) {
            const height = heightOf(item.key);
            frames.push({ key: item.key, item, x, y, width, height });
            x += width + gap;
            rowHeight = Math.max(rowHeight, height);
        }
        widest = Math.max(widest, x - gap);
        y += rowHeight + BOARD_ROW_GAP_PX;
    }
    return {
        frames, heads, rules, width: widest, height: Math.max(0, y - (afterSectionHead ? 0 : BOARD_ROW_GAP_PX)),
    };
}
