import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref, provide, defineComponent, h } from 'vue';
import LinkBar from './LinkBar.vue';

function mountBar(item) {
    const Host = defineComponent({
        setup() {
            provide('viewport', { currentItem: ref(item) });
            return () => h(LinkBar);
        },
    });
    return mount(Host);
}

describe('LinkBar', () => {
    it('renders links in Asana -> Figma -> Drupal -> Web order', () => {
        const wrapper = mountBar({ asana: 'https://a', figma: 'https://f', drupal: '', web: 'https://w' });
        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toEqual(['https://a', 'https://f', 'https://w']);
    });

    it('renders nothing when the current item has no link fields', () => {
        const wrapper = mountBar({ id: 'x' });
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('renders nothing when there is no current item', () => {
        const wrapper = mountBar(null);
        expect(wrapper.find('a').exists()).toBe(false);
    });
});
