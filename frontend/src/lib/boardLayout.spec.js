import { describe, it, expect } from 'vitest';
import {
    boardLayout, BOARD_GAP_PX, BOARD_GROUP_BAND_PX, BOARD_NAME_BAND_PX, BOARD_ROW_GAP_PX,
} from './boardLayout.js';

const group = (key, ...ids) => ({ key, label: key, items: ids.map((id) => ({ key: `page:${id}`, id })) });
const HEAD = BOARD_GROUP_BAND_PX + BOARD_NAME_BAND_PX;

describe('boardLayout', () => {
    it('puts a group in one row, left to right, however many frames it has', () => {
        const out = boardLayout({ groups: [group('a', 'one', 'two', 'three', 'four', 'five', 'six', 'seven')], width: 1000, heightOf: () => 500 });
        expect(new Set(out.frames.map((f) => f.y)).size).toBe(1);
        expect(out.frames[1].x).toBe(1000 + BOARD_GAP_PX);
        expect(out.frames.map((f) => f.item.id)).toEqual(['one', 'two', 'three', 'four', 'five', 'six', 'seven']);
    });

    it('starts each group on its own row, under a heading', () => {
        const out = boardLayout({ groups: [group('a', 'one'), group('b', 'two')], width: 1000, heightOf: () => 500 });
        expect(out.heads.map((h) => h.key)).toEqual(['a', 'b']);
        expect(out.frames[0].y).toBe(HEAD);
        expect(out.frames[1].y).toBe(HEAD + 500 + BOARD_ROW_GAP_PX + HEAD);
        expect(out.heads[1].y).toBe(HEAD + 500 + BOARD_ROW_GAP_PX);
    });

    it('makes a row as tall as its tallest frame and moves the next row down', () => {
        const heights = { 'page:one': 400, 'page:two': 900, 'page:three': 300 };
        const out = boardLayout({ groups: [group('a', 'one', 'two'), group('b', 'three')], width: 1000, heightOf: (k) => heights[k] });
        expect(out.frames[2].y).toBe(HEAD + 900 + BOARD_ROW_GAP_PX + HEAD);
    });

    it('leaves room for the name above a frame, clear of the row above', () => {
        const out = boardLayout({ groups: [group('a', 'one'), group('b', 'two')], width: 1000, heightOf: () => 500 });
        const firstBottom = out.frames[0].y + out.frames[0].height;
        const secondNameTop = out.frames[1].y - BOARD_NAME_BAND_PX;
        expect(secondNameTop).toBeGreaterThan(firstBottom);
    });

    it('sizes the surface to the widest row and skips an empty group', () => {
        const out = boardLayout({ groups: [group('empty'), group('a', 'one', 'two', 'three')], width: 100, heightOf: () => 100 });
        expect(out.heads).toHaveLength(1);
        expect(out.width).toBe(3 * 100 + 2 * BOARD_GAP_PX);
        expect(out.height).toBe(HEAD + 100);
    });

    it('returns an empty surface for no groups', () => {
        const out = boardLayout({ groups: [], width: 1000, heightOf: () => 1 });
        expect(out.frames).toEqual([]);
        expect(out.width).toBe(0);
        expect(out.height).toBe(0);
    });
});
