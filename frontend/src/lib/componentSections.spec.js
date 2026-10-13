import { describe, it, expect } from 'vitest';
import {
    sectionOf, legacySectionOf, sectionOrder, configuredLabel, OTHER_SECTION,
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

describe('components.sections', () => {
    const list = [{ id: 'sections' }, { id: 'basic' }, { id: 'eshop', label: { cs: 'E-shop', en: 'Shop' } }, { id: 'other', fallback: true }];

    it('reads the section the server named, and sends an unknown one to other', () => {
        expect(sectionOf({ id: 'cart', section: 'eshop' }, null, list)).toBe('eshop');
        expect(sectionOf({ id: 'x', section: 'gone' }, null, list)).toBe(OTHER_SECTION);
        expect(sectionOf({ id: 'bare' }, null, list)).toBe(OTHER_SECTION);
    });

    it('wins over group_by', () => {
        expect(sectionOf({ id: 'hero', kind: 'block', section: 'basic' }, 'kind', list)).toBe('basic');
    });

    it('orders by the list', () => {
        expect(sectionOrder('kind', list)).toEqual(['sections', 'basic', 'eshop', 'other']);
    });

    it('configuredLabel: a string is the same everywhere', () => {
        expect(configuredLabel({ label: 'Bloky' }, 'en')).toBe('Bloky');
    });

    it('configuredLabel: a map reads the chrome language, then the default locale, then the first value', () => {
        const entry = { label: { cs: 'E-shop', en: 'Shop' } };
        expect(configuredLabel(entry, 'en')).toBe('Shop');
        expect(configuredLabel(entry, 'cs_CZ')).toBe('E-shop');
        expect(configuredLabel(entry, 'de', 'en_US')).toBe('Shop');
        expect(configuredLabel(entry, 'de', 'fr')).toBe('E-shop');
    });

    it('configuredLabel: no label means the SPA locale heading', () => {
        expect(configuredLabel({ id: 'basic' }, 'en')).toBeNull();
        expect(configuredLabel(undefined, 'en')).toBeNull();
    });
});
