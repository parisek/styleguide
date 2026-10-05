// Pure zoom maths of the board view (BoardView.vue). The surface scrolls
// natively and scales with one CSS transform, so every function here turns
// numbers into numbers: a zoom level and a scroll position.
export const ZOOM_MIN = 0.02;
export const ZOOM_MAX = 2;
export const ZOOM_STEP = 1.25;

export function clampZoom(zoom) {
    if (!Number.isFinite(zoom)) return 1;
    return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, zoom));
}

// A wheel with Ctrl or Cmd held (a trackpad pinch arrives the same way):
// a step that is smooth for a small delta and bounded for a mouse notch.
export function wheelZoom(zoom, deltaY) {
    const factor = Math.exp(-Math.max(-100, Math.min(100, deltaY)) * 0.01);
    return clampZoom(zoom * factor);
}

export function stepZoom(zoom, direction) {
    return clampZoom(direction > 0 ? zoom * ZOOM_STEP : zoom / ZOOM_STEP);
}

// The zoom that shows the surface in the view, never above 100 %. The width
// always fits. The height may run `heightSlack` views tall (1 = it fits too),
// so one very tall page cannot shrink a whole row of pages to a thumbnail.
export function fitAllZoom({ contentWidth, contentHeight, viewWidth, viewHeight, heightSlack = 1 }) {
    if (!(contentWidth > 0) || !(contentHeight > 0) || !(viewWidth > 0) || !(viewHeight > 0)) return 1;
    return clampZoom(Math.min(1, viewWidth / contentWidth, (viewHeight * heightSlack) / contentHeight));
}

// Zoom to `next` and keep the surface point under the cursor where it is.
// `px`/`py` are the cursor's offset inside the scrolling element; `offsetX`
// and `offsetY` are the unscaled padding between that element's edge and the
// surface.
export function zoomAround({ zoom, next, scrollLeft, scrollTop, px, py, offsetX = 0, offsetY = 0 }) {
    const target = clampZoom(next);
    const worldX = (scrollLeft + px - offsetX) / zoom;
    const worldY = (scrollTop + py - offsetY) / zoom;
    return {
        zoom: target,
        scrollLeft: Math.max(0, worldX * target + offsetX - px),
        scrollTop: Math.max(0, worldY * target + offsetY - py),
    };
}
