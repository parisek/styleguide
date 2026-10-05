// Pure helpers of the board's view state (BoardSurface.vue): the link to a view,
// the keyboard walk between frames, and the jump to a frame.
import { clampZoom, ZOOM_MIN, ZOOM_MAX } from './boardZoom.js';
import { BOARD_NAME_BAND_PX } from './boardLayout.js';

// ---- The link to a view -------------------------------------------------

// `?zoom=35&at=1200,300&sel=page:homepage`: the zoom in percent, the point of
// the surface at the middle of the window (logical pixels), and the selected
// frame. Only what the reader changed is written, and anything that does not
// read back is dropped, so a hand-edited address cannot break the board.
const SELECTION = /^(component|page|doc):[A-Za-z0-9._-]{1,120}$/;

export function encodeView({ zoom, at = null, sel = null }) {
    const query = { zoom: String(Math.round(clampZoom(zoom) * 100)) };
    if (at) query.at = `${Math.round(at.x)},${Math.round(at.y)}`;
    if (sel) query.sel = sel;
    return query;
}

export function decodeView(query) {
    const out = { zoom: null, at: null, sel: null };
    const zoom = Number.parseInt(query?.zoom ?? '', 10);
    if (Number.isFinite(zoom) && zoom >= ZOOM_MIN * 100 && zoom <= ZOOM_MAX * 100) out.zoom = zoom / 100;
    const at = /^(-?\d{1,7}),(-?\d{1,7})$/.exec(query?.at ?? '');
    if (at) out.at = { x: Number(at[1]), y: Number(at[2]) };
    if (SELECTION.test(query?.sel ?? '')) out.sel = query.sel;
    return out.zoom === null && out.at === null && out.sel === null ? null : out;
}

// The scroll position that puts the surface point `at` in the middle of the
// window. `pad*` is the unscaled space around the surface.
export function centerOn({ at, zoom, viewWidth, viewHeight, padX = 0, padTop = 0 }) {
    return {
        scrollLeft: Math.max(0, padX + at.x * zoom - viewWidth / 2),
        scrollTop: Math.max(0, padTop + at.y * zoom - viewHeight / 2),
    };
}

// The surface point at the middle of the window: the inverse of centerOn.
export function viewCenter({ zoom, scrollLeft, scrollTop, viewWidth, viewHeight, padX = 0, padTop = 0 }) {
    return {
        x: (scrollLeft + viewWidth / 2 - padX) / zoom,
        y: (scrollTop + viewHeight / 2 - padTop) / zoom,
    };
}

// ---- The walk between frames --------------------------------------------

// The frame to select after an arrow key. `frames` is the layout's list, in
// reading order: Left and Right step through it, Up and Down go to the nearest
// frame, by its middle, in the row above or below. At an edge the selection
// stays where it is. With nothing selected, any arrow selects the first frame.
export function neighbour(frames, key, direction) {
    if (frames.length === 0) return null;
    const index = frames.findIndex((f) => f.key === key);
    if (index === -1) return frames[0].key;
    if (direction === 'left') return frames[Math.max(0, index - 1)].key;
    if (direction === 'right') return frames[Math.min(frames.length - 1, index + 1)].key;
    const rows = [...new Set(frames.map((f) => f.y))].sort((a, b) => a - b);
    const row = rows.indexOf(frames[index].y) + (direction === 'down' ? 1 : -1);
    if (row < 0 || row >= rows.length) return key;
    const middle = frames[index].x + frames[index].width / 2;
    const candidates = frames.filter((f) => f.y === rows[row]);
    return candidates.reduce((best, f) => (
        Math.abs(f.x + f.width / 2 - middle) < Math.abs(best.x + best.width / 2 - middle) ? f : best
    )).key;
}

// The scroll that brings `frame` fully into the window with the least move:
// none when it is already in. Used after an arrow key, which keeps the zoom.
export function scrollToShow({ frame, zoom, scrollLeft, scrollTop, viewWidth, viewHeight, padX = 0, padTop = 0, margin = 24 }) {
    const left = padX + frame.x * zoom;
    const right = left + frame.width * zoom;
    const top = padTop + (frame.y - BOARD_NAME_BAND_PX) * zoom;
    const bottom = padTop + (frame.y + frame.height) * zoom;
    let nextLeft = scrollLeft;
    let nextTop = scrollTop;
    if (left - margin < scrollLeft) nextLeft = left - margin;
    else if (right + margin > scrollLeft + viewWidth) nextLeft = right + margin - viewWidth;
    if (top - margin < scrollTop) nextTop = top - margin;
    else if (bottom + margin > scrollTop + viewHeight) nextTop = Math.min(bottom + margin - viewHeight, top - margin);
    return { scrollLeft: Math.max(0, nextLeft), scrollTop: Math.max(0, nextTop) };
}

// The jump to a frame: it fills the width of the window (never above 100 %),
// centred, with its name at the top.
export function zoomToFrame({ frame, viewWidth, viewHeight, padX = 0, padTop = 0 }) {
    const zoom = clampZoom(Math.min(1, (viewWidth - 2 * padX) / frame.width));
    const left = padX + frame.x * zoom;
    const top = padTop + (frame.y - BOARD_NAME_BAND_PX) * zoom;
    return {
        zoom,
        scrollLeft: Math.max(0, left - (viewWidth - frame.width * zoom) / 2),
        scrollTop: Math.max(0, top - Math.min(24, viewHeight * 0.05)),
    };
}
