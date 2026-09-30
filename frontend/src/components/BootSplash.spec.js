import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import BootSplash from './BootSplash.vue';

describe('BootSplash', () => {
    beforeEach(() => localStorage.clear());

    it('is a status region with a label for assistive tech', () => {
        localStorage.setItem('sg-locale', 'cs');
        const wrapper = mount(BootSplash);
        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.find('.sr-only').text()).toBe('Načítám\u2026');
    });

    it('follows the locale the interface will load, not the unset html lang', () => {
        localStorage.setItem('sg-locale', 'en');
        document.documentElement.lang = 'cs';
        expect(mount(BootSplash).find('.sr-only').text()).toBe('Loading\u2026');
    });
});
