import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import FieldsTable from './FieldsTable.vue';
import { useI18nStore } from '../stores/i18n.js';

beforeEach(() => {
    setActivePinia(createPinia());
    useI18nStore().strings = { fields: { required: 'Required field', detail: 'Field detail' } };
});

// Row shape matches flattenFieldsTree()'s output (fieldsTree.js) — path is
// the row's identity within a single component's field tree, so the same
// path can legitimately appear twice on a page when multiple components
// with same-shaped fields render side by side (FieldsView.vue).
const ROW_WITH_EXTRAS = { path: 'title', key: 'title', depth: 0, type: 'text', label: 'Title', description: '', required: false, extras: { maxlength: 120 }, hasExtras: true };

describe('FieldsTable', () => {
    // keyPrefix disambiguates the internal `expanded` map when two
    // FieldsTable instances render rows that share the same row.path — the
    // scenario that motivated extracting this component out of FieldsView,
    // which stacks one table per component on a single page. Without the
    // prefix, expanding a row in one component's table would also expand
    // the same-keyed row in every other component's table.
    it('keeps expanded state isolated between two tables sharing the same row.path, via keyPrefix', async () => {
        const a = mount(FieldsTable, { props: { rows: [ROW_WITH_EXTRAS], keyPrefix: 'comp-a:' } });
        const b = mount(FieldsTable, { props: { rows: [ROW_WITH_EXTRAS], keyPrefix: 'comp-b:' } });

        await a.find('button').trigger('click');

        expect(a.text()).toContain('"maxlength": 120');
        expect(b.text()).not.toContain('"maxlength": 120');
    });

    it('defaults keyPrefix to empty string, matching FieldsDrawer.vue\'s single-table usage', () => {
        const wrapper = mount(FieldsTable, { props: { rows: [ROW_WITH_EXTRAS] } });
        expect(wrapper.find('button').exists()).toBe(true);
    });
});
