import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import HealthWarningBadge from './HealthWarningBadge.vue';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('HealthWarningBadge', () => {
    it('renders nothing when there are no parser warnings', () => {
        const wrapper = mount(HealthWarningBadge);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('renders a badge with the warning count when the catalogue has warnings', () => {
        const catalog = useCatalogStore();
        catalog.warnings = [
            { file: 'component/broken/broken.twig', error: 'boom' },
            { file: 'component/other/other.twig', error: 'kaboom' },
        ];
        const wrapper = mount(HealthWarningBadge);
        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.text()).toBe('2');
    });
});
