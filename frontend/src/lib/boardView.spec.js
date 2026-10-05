import { describe, it, expect } from 'vitest';
import { encodeView, decodeView, centerOn, viewCenter, neighbour, scrollToShow, zoomToFrame } from './boardView.js';

describe('the link to a view', () => {
    it('writes the zoom in percent, the middle point and the selection', () => {
        expect(encodeView({ zoom: 0.35, at: { x: 1200.4, y: 299.6 }, sel: 'page:home' })).toEqual({ zoom: '35', at: '1200,300', sel: 'page:home' });
        expect(encodeView({ zoom: 1 })).toEqual({ zoom: '100' });
    });

    it('reads back what it wrote', () => {
        const out = decodeView(encodeView({ zoom: 0.35, at: { x: 1200, y: 300 }, sel: 'page:home' }));
        expect(out).toEqual({ zoom: 0.35, at: { x: 1200, y: 300 }, sel: 'page:home' });
    });

    it('drops what does not read back, and says nothing when nothing does', () => {
        expect(decodeView({ zoom: '9999', at: 'x,y', sel: '<script>' })).toBeNull();
        expect(decodeView({ zoom: '2', sel: 'page:ok' })).toEqual({ zoom: null, at: null, sel: 'page:ok' });
        expect(decodeView({})).toBeNull();
        expect(decodeView(undefined)).toBeNull();
    });

    it('centres a point and finds it again', () => {
        const base = { zoom: 0.5, viewWidth: 1000, viewHeight: 600, padX: 40, padTop: 16 };
        const { scrollLeft, scrollTop } = centerOn({ at: { x: 4000, y: 2000 }, ...base });
        const back = viewCenter({ ...base, scrollLeft, scrollTop });
        expect(back.x).toBeCloseTo(4000);
        expect(back.y).toBeCloseTo(2000);
    });
});

describe('neighbour', () => {
    const frames = [
        { key: 'a', x: 0, y: 0, width: 100, height: 50 }, { key: 'b', x: 200, y: 0, width: 100, height: 50 }, { key: 'c', x: 400, y: 0, width: 100, height: 50 },
        { key: 'd', x: 0, y: 500, width: 100, height: 50 }, { key: 'e', x: 200, y: 500, width: 100, height: 50 },
    ];

    it('steps through the reading order with Left and Right, and stops at the ends', () => {
        expect(neighbour(frames, 'b', 'right')).toBe('c');
        expect(neighbour(frames, 'c', 'right')).toBe('d');
        expect(neighbour(frames, 'a', 'left')).toBe('a');
        expect(neighbour(frames, 'e', 'right')).toBe('e');
    });

    it('goes to the nearest frame in the next row with Up and Down', () => {
        expect(neighbour(frames, 'c', 'down')).toBe('e');
        expect(neighbour(frames, 'e', 'up')).toBe('b');
        expect(neighbour(frames, 'a', 'up')).toBe('a');
        expect(neighbour(frames, 'd', 'down')).toBe('d');
    });

    it('selects the first frame when nothing is selected, and nothing for no frames', () => {
        expect(neighbour(frames, null, 'right')).toBe('a');
        expect(neighbour([], null, 'right')).toBeNull();
    });
});

describe('scrollToShow', () => {
    const view = { zoom: 1, viewWidth: 1000, viewHeight: 600, padX: 0, padTop: 0, margin: 0 };

    it('does not move when the frame is in', () => {
        const out = scrollToShow({ frame: { x: 100, y: 200, width: 300, height: 200 }, scrollLeft: 0, scrollTop: 100, ...view });
        expect(out).toEqual({ scrollLeft: 0, scrollTop: 100 });
    });

    it('moves the least that shows a frame on the right', () => {
        const out = scrollToShow({ frame: { x: 1200, y: 200, width: 300, height: 200 }, scrollLeft: 0, scrollTop: 100, ...view });
        expect(out.scrollLeft).toBe(500);
    });
});

describe('zoomToFrame', () => {
    it('fits the width, never above 100 %, and centres the frame', () => {
        const out = zoomToFrame({ frame: { x: 1560, y: 400, width: 1440, height: 3000 }, viewWidth: 1312, viewHeight: 700, padX: 40, padTop: 16 });
        expect(out.zoom).toBeCloseTo((1312 - 80) / 1440);
        expect(out.scrollLeft).toBeGreaterThan(0);
        expect(zoomToFrame({ frame: { x: 0, y: 0, width: 200, height: 200 }, viewWidth: 1312, viewHeight: 700 }).zoom).toBe(1);
    });
});
