import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import FieldsView from './FieldsView.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/fields', name: 'fields', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
        ],
    });
}

async function mountFieldsView() {
    setActivePinia(createPinia());
    const catalog = useCatalogStore();
    catalog.items = [
        {
            id: 'defkit-card',
            name: 'Defkit Card',
            has_styleguide: true,
            fields: [
                { key: 'title', label: 'Title', type: 'text', description: 'Card title', required: true },
                {
                    key: 'media',
                    label: 'Media',
                    type: 'group',
                    children: [
                        { key: 'src', label: 'Source', type: 'image', extra_hint: 'square crop' },
                    ],
                },
            ],
        },
        { id: 'no-fields-widget', name: 'No Fields Widget', has_styleguide: true },
    ];
    useI18nStore().strings = {
        fields: {
            overviewTitle: 'Fields overview',
            filterPlaceholder: 'Filter fields…',
            empty: 'No fields match the filter.',
            required: 'Required field',
            detail: 'Field detail',
        },
    };
    const router = makeRouter();
    await router.push('/fields');
    const wrapper = mount(FieldsView, { global: { plugins: [router] } });
    await router.isReady();
    return { wrapper, catalog };
}

describe('FieldsView', () => {
    it('lists only components that declare fields, with their flattened rows', async () => {
        const { wrapper } = await mountFieldsView();
        expect(wrapper.text()).toContain('Defkit Card');
        expect(wrapper.text()).not.toContain('No Fields Widget');
        // Flattened rows include both the top-level field and its nested child.
        expect(wrapper.text()).toContain('title');
        expect(wrapper.text()).toContain('media');
        expect(wrapper.text()).toContain('src');
    });

    it('filters rows by key/label/type, case-insensitively; component name match keeps all rows', async () => {
        const { wrapper } = await mountFieldsView();
        const filter = wrapper.find('[data-testid="fields-filter"]');

        // Row-level match (case-insensitive on the field key): narrows to the
        // matching row only, dropping unrelated siblings.
        await filter.setValue('TITLE');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('title');
        expect(wrapper.text()).not.toContain('src');

        // Component-name match: keeps every row for that component, even
        // ones that don't individually match the query text.
        await filter.setValue('defkit');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('title');
        expect(wrapper.text()).toContain('src');
    });

    it('links each component heading to /component/<id>?fields=1', async () => {
        const { wrapper } = await mountFieldsView();
        const heading = wrapper.findAll('a').find((a) => a.text() === 'Defkit Card');
        expect(heading).toBeDefined();
        expect(heading.attributes('href')).toBe('/component/defkit-card?fields=1');
    });

    it('shows the empty message when the filter matches nothing', async () => {
        const { wrapper } = await mountFieldsView();
        const filter = wrapper.find('[data-testid="fields-filter"]');
        await filter.setValue('nonexistent-query-xyz');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('No fields match the filter.');
        expect(wrapper.text()).not.toContain('Defkit Card');
    });
});
