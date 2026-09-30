import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BootSplash from './BootSplash.vue';

describe('BootSplash', () => {
    it('is a status region with a label for assistive tech', () => {
        document.documentElement.lang = 'cs';
        const wrapper = mount(BootSplash);
        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.find('.sr-only').text()).toBe('Načítám\u2026');
    });

    it('falls back to English for another document language', () => {
        document.documentElement.lang = 'en';
        expect(mount(BootSplash).find('.sr-only').text()).toBe('Loading\u2026');
    });
});
