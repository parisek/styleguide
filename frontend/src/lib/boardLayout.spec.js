import { describe, it, expect } from 'vitest';
import {
    boardLayout, BOARD_GAP_PX, BOARD_NAME_BAND_PX, BOARD_ROW_GAP_PX, BOARD_SPACE_ABOVE_PX,
    BOARD_SPACE_AFTER_SECTION_PX, BOARD_HEAD_HEIGHT_PX, BOARD_HEAD_GAP_PX,
} from './boardLayout.js';

const group = (key, level, ...ids) => ({ key, label: key, level, items: ids.map((id) => ({ key: `page:${id}`, id })) });
const H1 = BOARD_HEAD_HEIGHT_PX[1] + BOARD_HEAD_GAP_PX + BOARD_NAME_BAND_PX;

describe('boardLayout', () => {
    it('puts a group in one row, left to right, however many frames it has', () => {
        const out = boardLayout({ groups: [group('a', 1, 'one', 'two', 'three', 'four', 'five', 'six', 'seven')], width: 1000, heightOf: () => 500 });
        expect(new Set(out.frames.map((f) => f.y)).size).toBe(1);
        expect(out.frames[1].x).toBe(1000 + BOARD_GAP_PX);
        expect(out.frames.map((f) => f.item.id)).toEqual(['one', 'two', 'three', 'four', 'five', 'six', 'seven']);
    });

    it('opens with its first heading at the top, and no rule above it', () => {
        const out = boardLayout({ groups: [group('a', 1, 'one')], width: 1000, heightOf: () => 500 });
        expect(out.heads[0]).toMatchObject({ key: 'a', level: 1, y: 0, count: 1 });
        expect(out.frames[0].y).toBe(H1);
        expect(out.rules).toEqual([]);
    });

    it('puts more space above a heading than below it, so it belongs to what follows', () => {
        const out = boardLayout({ groups: [group('a', 1, 'one'), group('b', 1, 'two')], width: 1000, heightOf: () => 500 });
        const aboveSecond = out.heads[1].y - (out.frames[0].y + 500);
        const belowSecond = out.frames[1].y - (out.heads[1].y + BOARD_HEAD_HEIGHT_PX[1]);
        expect(aboveSecond).toBe(BOARD_ROW_GAP_PX + BOARD_SPACE_ABOVE_PX[1]);
        expect(aboveSecond).toBeGreaterThan(belowSecond * 2);
    });

    it('marks each section after the first with a rule in the gap above its heading', () => {
        const out = boardLayout({ groups: [group('a', 1, 'one'), group('b', 1, 'two')], width: 1000, heightOf: () => 500 });
        expect(out.rules).toHaveLength(1);
        expect(out.rules[0].y).toBeGreaterThan(out.frames[0].y + 500);
        expect(out.rules[0].y).toBeLessThan(out.heads[1].y);
    });

    it('lets a section heading stand for the level-2 groups after it', () => {
        const out = boardLayout({
            groups: [group('basic', 1, 'one'), group('pages', 1), group('home', 2, 'two'), group('pricing', 2, 'three')],
            width: 1000,
            heightOf: () => 500,
        });
        expect(out.heads.map((h) => [h.key, h.level, h.count])).toEqual([['basic', 1, 1], ['pages', 1, 0], ['home', 2, 1], ['pricing', 2, 1]]);
        const pages = out.heads[1];
        const home = out.heads[2];
        expect(home.y).toBe(pages.y + BOARD_HEAD_HEIGHT_PX[1] + BOARD_HEAD_GAP_PX + BOARD_SPACE_AFTER_SECTION_PX);
        const pricing = out.heads[3];
        const homeFrame = out.frames[1];
        expect(pricing.y).toBe(homeFrame.y + 500 + BOARD_ROW_GAP_PX + BOARD_SPACE_ABOVE_PX[2]);
        expect(out.rules).toHaveLength(1);
    });

    it('gives a level-2 group less space above than a section, and a smaller heading', () => {
        const out = boardLayout({ groups: [group('s', 1), group('a', 2, 'one'), group('b', 2, 'two')], width: 1000, heightOf: () => 500 });
        expect(BOARD_SPACE_ABOVE_PX[2]).toBeLessThan(BOARD_SPACE_ABOVE_PX[1]);
        expect(BOARD_HEAD_HEIGHT_PX[2]).toBeLessThan(BOARD_HEAD_HEIGHT_PX[1]);
        expect(out.heads.map((h) => h.level)).toEqual([1, 2, 2]);
    });

    it('makes a row as tall as its tallest frame and moves the next row down', () => {
        const heights = { 'page:one': 400, 'page:two': 900, 'page:three': 300 };
        const out = boardLayout({ groups: [group('a', 1, 'one', 'two'), group('b', 1, 'three')], width: 1000, heightOf: (k) => heights[k] });
        expect(out.frames[2].y).toBe(H1 + 900 + BOARD_ROW_GAP_PX + BOARD_SPACE_ABOVE_PX[1] + H1);
    });

    it('leaves room for the name above a frame, clear of the row above', () => {
        const out = boardLayout({ groups: [group('a', 1, 'one'), group('b', 1, 'two')], width: 1000, heightOf: () => 500 });
        const firstBottom = out.frames[0].y + out.frames[0].height;
        expect(out.frames[1].y - BOARD_NAME_BAND_PX).toBeGreaterThan(firstBottom);
    });

    it('skips an empty level-2 group and sizes the surface to the widest row', () => {
        const out = boardLayout({ groups: [group('s', 1), group('empty', 2), group('a', 2, 'one', 'two', 'three')], width: 100, heightOf: () => 100 });
        expect(out.heads.map((h) => h.key)).toEqual(['s', 'a']);
        expect(out.width).toBe(3 * 100 + 2 * BOARD_GAP_PX);
    });

    it('reads a group without a level as a section', () => {
        const out = boardLayout({ groups: [{ key: 'a', label: 'A', items: [{ key: 'page:one' }] }], width: 100, heightOf: () => 100 });
        expect(out.heads[0].level).toBe(1);
    });

    it('returns an empty surface for no groups', () => {
        const out = boardLayout({ groups: [], width: 1000, heightOf: () => 1 });
        expect(out.frames).toEqual([]);
        expect(out.width).toBe(0);
        expect(out.height).toBe(0);
    });
});
