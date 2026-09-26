import { describe, it, expect, afterEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import LandingView from './LandingView.vue';
import GridView from './GridView.vue';
import FoundationsView from './FoundationsView.vue';
import { resetRuntimeConfig } from '../lib/runtimeConfig.js';

function injectConfig(payload) {
    document.getElementById('sg-config')?.remove();
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify(payload);
    document.body.appendChild(el);
    resetRuntimeConfig();
}

afterEach(() => {
    document.getElementById('sg-config')?.remove();
    resetRuntimeConfig();
});

describe('LandingView', () => {
    it('shows Foundations by default', () => {
        injectConfig({});
        const wrapper = shallowMount(LandingView);
        expect(wrapper.findComponent(FoundationsView).exists()).toBe(true);
        expect(wrapper.findComponent(GridView).exists()).toBe(false);
    });

    it('shows the overview grid with `overview.default: grid`', () => {
        injectConfig({ landing: 'grid' });
        const wrapper = shallowMount(LandingView);
        expect(wrapper.findComponent(GridView).exists()).toBe(true);
        expect(wrapper.findComponent(FoundationsView).exists()).toBe(false);
    });
});
