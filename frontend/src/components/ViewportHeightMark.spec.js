import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import ViewportHeightMark from './ViewportHeightMark.vue';
import { useI18nStore } from '../stores/i18n.js';
import cs from '../../public/locales/cs.json';

const pin = { followsViewport: true, viewportDeltaPx: 800, contentDeltaPx: 320 };

describe('ViewportHeightMark', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useI18nStore().strings = cs;
    });

    it('renders nothing without a pin', () => {
        const wrapper = mount(ViewportHeightMark, { props: { pin: null } });
        expect(wrapper.find('[data-testid="viewport-height-label"]').exists()).toBe(false);
    });

    it('labels a pinned preview and names the measured deltas in the tooltip', () => {
        const wrapper = mount(ViewportHeightMark, { props: { pin } });
        const label = wrapper.get('[data-testid="viewport-height-label"]');
        expect(label.text()).toBe('Výška podle viewportu');
        expect(label.attributes('title')).toBe('Viewport +800 px → obsah +320 px. Náhled má pevnou výšku 640 px.');
        expect(label.attributes('aria-label')).toBe('Výška podle viewportu. Viewport +800 px → obsah +320 px. Náhled má pevnou výšku 640 px.');
    });

    it('uses only contract tokens for colour, border and radius', () => {
        const cls = mount(ViewportHeightMark, { props: { pin } }).get('span').classes();
        expect(cls).toEqual(expect.arrayContaining(['text-ui-muted', 'border-ui-control-border', 'rounded-ui-pill', 'h-ui-control']));
        expect(cls.some((c) => /amber|zinc|red/.test(c))).toBe(false);
    });

    it('keeps the full text for assistive tech in the chip form', () => {
        const chip = mount(ViewportHeightMark, { props: { pin, form: 'chip' } }).get('[data-testid="viewport-height-chip"]');
        expect(chip.attributes('aria-label')).toContain('Výška podle viewportu');
        expect(chip.find('.sr-only').text()).toBe('Výška podle viewportu');
    });
});
