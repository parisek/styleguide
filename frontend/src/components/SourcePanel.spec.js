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

// #sg-config for one test; removed again in afterEach.
function injectConfig(payload) {
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify(payload);
    document.body.appendChild(el);
    resetRuntimeConfig();
}

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
    document.getElementById('sg-config')?.remove();
    resetRuntimeConfig();
    vi.unstubAllGlobals();
});

describe('SourcePanel', () => {
    it('numbers the lines as rows and keeps the text intact', async () => {
        fetchMock.mockImplementation((address) => respond(200, address.includes('/api/files/')
            ? { files: [] }
            : { file: 'component/a/styleguide.twig', source: 'one\ntwo\n' }));
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'a' } });
        await flushPromises();
        expect(wrapper.findAll('.sg-code-line')).toHaveLength(2);
        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('one\ntwo');
    });

    it('shows the rendered HTML on its tab, fetched only when opened', async () => {
        fetchMock.mockImplementation((address) => respond(200, address.includes('/api/markup/')
            ? { html: '<a class="btn">Koupit</a>\n' }
            : { file: 'component/button/styleguide.twig', source: "{{ component_button({ title: 'Koupit' }) }}\n" }));
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'button' } });
        await flushPromises();
        expect(fetchMock.mock.calls.some(([a]) => a.includes('/api/markup/'))).toBe(false);

        await wrapper.get('[data-testid="source-tab-html"]').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/markup/component/button');
        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('<a class="btn">Koupit</a>');
        expect(wrapper.find('[data-testid="source-code"] a').exists()).toBe(false);
    });

    it('links the file behind the open view when source_url is set', async () => {
        fetchMock.mockImplementation((address) => respond(200, address.includes('/api/markup/')
            ? { html: '<div></div>\n' }
            : { file: 'component/multi/styleguide.secondary.twig', source: 'x\n' }));
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.type = 'application/json';
        el.textContent = JSON.stringify({ showSource: true, sourceUrl: 'https://github.com/acme/site/blob/main/templates/{path}' });
        document.body.appendChild(el);
        resetRuntimeConfig();

        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();
        const link = () => wrapper.get('[data-testid="source-repo-link"]');
        expect(link().text()).toContain('GitHub');
        expect(link().attributes('href'))
            .toBe('https://github.com/acme/site/blob/main/templates/component/multi/styleguide.secondary.twig');
        expect(link().attributes('target')).toBe('_blank');

        await wrapper.get('[data-testid="source-tab-html"]').trigger('click');
        await flushPromises();
        expect(link().attributes('href')).toBe('https://github.com/acme/site/blob/main/templates/component/multi/multi.twig');
        el.remove();
        resetRuntimeConfig();
    });

    it('shows no link without source_url, and no file name', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi' } });
        await flushPromises();
        expect(wrapper.find('[data-testid="source-repo-link"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('styleguide.secondary.twig');
    });

    it('offers the Twig, CSS and JS views only when listed and the entry has them', async () => {
        fetchMock.mockImplementation((address) => respond(200, address.includes('/api/files/')
            ? { files: [
                { path: 'component/tabs/tabs.twig', language: 'twig', source: '<div>{{ content.title }}</div>\n' },
                { path: 'component/tabs/js/tabs.js', language: 'js', source: 'export default 1;\n' },
                { path: 'component/tabs/js/tabs.alpine.js', language: 'js', source: 'export const a = 2;\n' },
            ] }
            : { file: 'component/tabs/styleguide.twig', source: 'x\n' }));
        injectConfig({ showSource: true, sourceViews: ['data', 'twig', 'html', 'css', 'js'] });
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'tabs' } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/files/component/tabs');
        expect(wrapper.find('[data-testid="source-tab-twig"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="source-tab-js"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="source-tab-css"]').exists()).toBe(false);

        await wrapper.get('[data-testid="source-tab-twig"]').trigger('click');
        expect(wrapper.get('[data-testid="source-code"]').text()).toContain('{{ content.title }}');

        await wrapper.get('[data-testid="source-tab-js"]').trigger('click');
        const headings = wrapper.findAll('[data-testid="source-file-heading"]').map((h) => h.text());
        expect(headings).toEqual(['component/tabs/js/tabs.js', 'component/tabs/js/tabs.alpine.js']);
        expect(wrapper.get('[data-testid="source-code"]').text()).toContain('export const a = 2;');
    });

    it('keeps Data and HTML when the files cannot be loaded', async () => {
        injectConfig({ showSource: true, sourceViews: ['data', 'html', 'css', 'js'] });
        fetchMock.mockImplementation((address) => (address.includes('/api/files/')
            ? respond(404, { error: 'No files' })
            : respond(200, { file: 'component/a/styleguide.twig', source: 'x\n' })));
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'a' } });
        await flushPromises();
        expect(wrapper.findAll('[role="group"] button').map((b) => b.attributes('data-testid')))
            .toEqual(['source-tab-data', 'source-tab-html']);
    });

    it('shows only the views source_views lists, and starts on the first one', async () => {
        fetchMock.mockImplementation((address) => respond(200, address.includes('/api/markup/')
            ? { html: '<p>x</p>\n' }
            : { files: [{ path: 'component/a/a.twig', language: 'twig', source: '<p>{{ x }}</p>\n' }] }));
        injectConfig({ showSource: true, sourceViews: ['twig', 'html'] });
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'a' } });
        await flushPromises();

        expect(fetchMock.mock.calls.some(([a]) => a.includes('/api/source/'))).toBe(false);
        expect(wrapper.findAll('[role="group"] button').map((b) => b.text())).toEqual(['Twig', 'HTML']);
        expect(wrapper.get('[data-testid="source-tab-twig"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('<p>{{ x }}</p>');
    });

    it('does not ask for the files when no file view is listed', async () => {
        injectConfig({ showSource: true, sourceViews: ['data', 'html'] });
        mount(SourcePanel, { props: { type: 'component', slug: 'multi' } });
        await flushPromises();
        expect(fetchMock.mock.calls.some(([a]) => a.includes('/api/files/'))).toBe(false);
    });

    it('wraps long lines', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi' } });
        await flushPromises();
        expect(wrapper.get('pre').classes()).toContain('whitespace-pre-wrap');
    });

    it('shows plain text without loading the highlighter when highlight_source is off', async () => {
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.type = 'application/json';
        el.textContent = JSON.stringify({ showSource: true, highlightSource: false });
        document.body.appendChild(el);
        resetRuntimeConfig();

        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('<div class="multi">{{ x }}</div>');
        expect(wrapper.findAll('[data-testid="source-code"] .sg-code-line span').every((sp) => sp.classes().length === 0)).toBe(true);
        el.remove();
        resetRuntimeConfig();
    });

    it('fetches the source of the tile it belongs to and shows it as text', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/source/component/multi?variant=secondary');
        expect(wrapper.get('[data-testid="source-code"]').text()).toBe('<div class="multi">{{ x }}</div>');
        // Text, never markup.
        expect(wrapper.find('[data-testid="source-code"] div').exists()).toBe(false);
        // Highlighted once the lazily loaded highlighter arrives: the Twig
        // delimiters get their own span and colour.
        await vi.waitFor(() => {
            if (!wrapper.find('[data-testid="source-code"] .text-rose-400').exists()) throw new Error('not highlighted yet');
        });
        const spans = wrapper.findAll('[data-testid="source-code"] span');
        expect(spans.some((sp) => sp.text() === '{{' && sp.classes().includes('text-rose-400'))).toBe(true);
    });

    it('asks for the default fixture when the tile has no variant', async () => {
        mount(SourcePanel, { props: { type: 'page', slug: 'landing', variant: null } });
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('/styleguide/api/source/page/landing');
    });

    it('says so when the server has no source for the tile', async () => {
        fetchMock.mockImplementation(() => respond(404, { error: 'No fixture source for this entry' }));
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'sample', variant: null } });
        await flushPromises();

        expect(wrapper.text()).toContain('No source');
        expect(wrapper.find('[data-testid="source-code"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="source-tab-html"]').exists()).toBe(false);
    });

    it('refetches when it is pointed at another tile', async () => {
        const wrapper = mount(SourcePanel, { props: { type: 'component', slug: 'multi', variant: 'secondary' } });
        await flushPromises();
        await wrapper.setProps({ variant: 'dark-bg' });
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith('/styleguide/api/source/component/multi?variant=dark-bg');
    });
});
