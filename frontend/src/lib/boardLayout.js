// Pure layout of the board (BoardSurface.vue): where each frame, and each
// group heading, sits on the surface. All numbers are logical pixels, before
// the view scales the surface.
//
// One row per group, in the order given (the sidebar's), frames left to right
// inside the row. So zoomed out, the whole catalogue reads as a few rows, one
// per group. A row is as tall as its tallest frame, so a page measured taller
// than its neighbours moves the rows below it, not its neighbours.
//
// Above each row sit two bands: the group heading, then, right above the
// frames, room for each frame's name. The bands are fixed, and the view caps
// its text sizes to fit them, so a name can never reach the row above.
export const BOARD_GAP_PX = 120;
export const BOARD_GROUP_BAND_PX = 300;
export const BOARD_NAME_BAND_PX = 90;
// What lies between a row's frames and the next group's heading.
export const BOARD_ROW_GAP_PX = 60;
// "Fit all" lets the surface run this many views tall (see fitAllZoom).
export const BOARD_FIT_HEIGHT_SLACK = 2;

// `groups` is [{ key, label, items }] in display order; each item has a `key`.
// `heightOf(key)` is the frame's height now: the measured one, or a floor
// before the page has loaded. A group with no items has no row.
export function boardLayout({ groups, width, heightOf, gap = BOARD_GAP_PX }) {
    const frames = [];
    const heads = [];
    let y = 0;
    let widest = 0;
    for (const group of groups) {
        if (group.items.length === 0) continue;
        heads.push({ key: group.key, label: group.label, x: 0, y });
        y += BOARD_GROUP_BAND_PX + BOARD_NAME_BAND_PX;
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
    return { frames, heads, width: widest, height: Math.max(0, y - BOARD_ROW_GAP_PX) };
}
