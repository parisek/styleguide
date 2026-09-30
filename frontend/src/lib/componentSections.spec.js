import { describe, it, expect } from 'vitest';
import {
    sectionOf, legacySectionOf, sectionOrder,
} from './componentSections.js';

const c = (id, kind, category) => ({ id, name: id, kind, category });

describe('sectionOf', () => {
    it('keeps the legacy rule without the key', () => {
        expect(sectionOf(c('hero', 'block', 'Hero'))).toBe('basic');
        expect(sectionOf(c('heading', 'element', 'Layout'))).toBe('blocks');
        expect(sectionOf(c('quote', 'block', 'Gutenberg'))).toBe('gutenberg');
    });

    it('reads the section from kind with group_by kind', () => {
        expect(sectionOf(c('hero', 'block', 'Hero'), 'kind')).toBe('blocks');
        expect(sectionOf(c('footer', 'section', 'Footer'), 'kind')).toBe('sections');
        expect(sectionOf(c('heading', 'element', 'Layout'), 'kind')).toBe('basic');
        expect(sectionOf(c('menu', 'part', 'Basic'), 'kind')).toBe('parts');
        expect(sectionOf(c('component', 'utility', 'Other'), 'kind')).toBe('utilities');
        expect(sectionOf(c('quote', 'block', 'Gutenberg'), 'kind')).toBe('blocks');
    });

    it('falls back to the legacy rule for a component without a valid kind', () => {
        expect(sectionOf(c('old', '', 'Blocks'), 'kind')).toBe('blocks');
        expect(sectionOf(c('typo', 'blok', 'Basic'), 'kind')).toBe('basic');
        expect(sectionOf({ id: 'bare' }, 'kind')).toBe('basic');
    });

    it('legacySectionOf ignores kind', () => {
        expect(legacySectionOf(c('hero', 'block', 'Hero'))).toBe('basic');
    });
});

describe('sectionOrder', () => {
    it('keeps the legacy order without the key', () => {
        expect(sectionOrder()).toEqual(['basic', 'blocks', 'gutenberg']);
    });

    it('reads atomic before composite with group_by kind', () => {
        expect(sectionOrder('kind')).toEqual(['basic', 'parts', 'blocks', 'gutenberg', 'sections', 'utilities']);
    });
});
