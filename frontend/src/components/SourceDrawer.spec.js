import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import SourceDrawer from './SourceDrawer.vue';
import { useI18nStore } from '../stores/i18n.js';
import { resetRuntimeConfig } from '../lib/runtimeConfig.js';

let fetchMock;

beforeEach(() => {
    setActivePinia(createPinia());
    resetRuntimeConfig();
    useI18nStore().strings = { source: { toggle: 'Code', copy: 'Copy', loading: 'Loading…' } };
    fetchMock = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ file: 'f', source: 'x' }) }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('SourceDrawer', () => {
    it('starts closed and fetches nothing until opened', async () => {
        const wrapper = mount(SourceDrawer, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        expect(wrapper.get('[data-testid="source-drawer-toggle"]').attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="source-panel"]').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="source-drawer-toggle"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="source-panel"]').exists()).toBe(true);
        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/source/component/multi?variant=secondary');
    });

    it('closes again when the preview moves to another entry or tile', async () => {
        const wrapper = mount(SourceDrawer, { props: { type: 'component', slug: 'multi', variant: null } });
        await wrapper.get('[data-testid="source-drawer-toggle"]').trigger('click');
        await wrapper.setProps({ variant: 'dark-bg' });
        expect(wrapper.find('[data-testid="source-panel"]').exists()).toBe(false);
    });
});
