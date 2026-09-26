import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import SourcePanel from './SourcePanel.vue';
import { useI18nStore } from '../stores/i18n.js';
import { resetRuntimeConfig } from '../lib/runtimeConfig.js';

function respond(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

let fetchMock;

beforeEach(() => {
    setActivePinia(createPinia());
    resetRuntimeConfig();
    useI18nStore().strings = {
        source: { copy: 'Copy', copied: 'Copied', loading: 'Loading…', unavailable: 'No source' },
    };
    fetchMock = vi.fn(() => respond(200, {
        file: 'component/multi/styleguide.secondary.twig',
        source: '<div class="multi">{{ x }}</div>\n',
    }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('SourcePanel', () => {
    it('fetches the source of the tile it belongs to and shows it as text', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/source/component/multi?variant=secondary');
        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('<div class="multi">{{ x }}</div>');
        expect(wrapper.get('[data-testid="source-file"]').text()).toBe('component/multi/styleguide.secondary.twig');
        // Text, never markup.
        expect(wrapper.find('[data-testid="source-code"] div').exists()).toBe(false);
    });

    it('asks for the default fixture when the tile has no variant', async () => {
        mount(SourcePanel, { props: { type: 'page', slug: 'landing', variant: null } });
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/source/page/landing');
    });

    it('copies the source and confirms it', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();

        const button = wrapper.get('[data-testid="source-copy"]');
        expect(button.text()).toBe('Copy');
        await button.trigger('click');
        await flushPromises();
        expect(writeText).toHaveBeenCalledWith('<div class="multi">{{ x }}</div>\n');
        expect(button.text()).toBe('Copied');
    });

    it('says so when the server has no source for the tile', async () => {
        fetchMock.mockImplementation(() => respond(404, { error: 'No fixture source for this entry' }));
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'sample', variant: null } });
        await flushPromises();

        expect(wrapper.text()).toContain('No source');
        expect(wrapper.find('[data-testid="source-code"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="source-copy"]').exists()).toBe(false);
    });

    it('refetches when it is pointed at another tile', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();
        await wrapper.setProps({ variant: 'dark-bg' });
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/styleguide/api/source/component/multi?variant=dark-bg');
    });
});
