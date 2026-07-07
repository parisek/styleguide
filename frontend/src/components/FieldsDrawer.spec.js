import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import FieldsDrawer from './FieldsDrawer.vue';
import { useI18nStore } from '../stores/i18n.js';

beforeEach(() => {
    setActivePinia(createPinia());
    useI18nStore().strings = { nav: { fields: 'Fields' }, fields: { required: 'Required field', requiredLegend: '= Required field' } };
});

const FIELDS = {
    title: { type: 'text', title: 'Title', required: true },
    items: { type: 'array', fields: { label: { type: 'text', title: 'Label' } } },
};

// attachTo: document.body — jsdom only computes a live `display` value for
// v-show toggles when the element is connected to the document; detached
// mounts return a stale cached computed style (getComputedStyle keeps
// reporting the initial `display: none` even after the inline style is
// removed). Same workaround already used in PreviewPane.spec.js for its
// isVisible() assertions.
describe('FieldsDrawer', () => {
    it('renders the field count in the collapsed header', () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, attachTo: document.body });
        expect(wrapper.text()).toContain('Fields');
        expect(wrapper.text()).toContain('3'); // title + items + items.label
    });

    it('hides the table body until toggled open', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(false);
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('table').isVisible()).toBe(true);
    });

    it('indents a nested field row and shows the required-field dot on the required row', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, attachTo: document.body });
        await wrapper.find('button').trigger('click');
        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[2].text()).toContain('label');
        expect(rows[0].find('[role="img"]').exists()).toBe(true); // title's required dot
        expect(rows[1].find('[role="img"]').exists()).toBe(false); // items has no required dot
    });

    it('renders an em-dash for a row with no declared type', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: { untyped: { title: 'X' } } }, attachTo: document.body });
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('tbody tr').text()).toContain('—');
    });
});
