import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import FieldsDrawer from './FieldsDrawer.vue';
import { useI18nStore } from '../stores/i18n.js';

beforeEach(() => {
    setActivePinia(createPinia());
    useI18nStore().strings = { nav: { fields: 'Fields' }, fields: { required: 'Required field', requiredLegend: '= Required field', detail: 'Field detail' } };
});

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
        ],
    });
}

// Canonical Field[] list (docs/API.md § Fields; ADR-0002) — matches the
// flattenFieldsTree() input shape produced by /api/components and /api/fields.
const FIELDS = [
    { key: 'title', type: 'text', label: 'Title', required: true },
    { key: 'items', type: 'array', children: [
        { key: 'label', type: 'text', label: 'Label' },
    ] },
];

// attachTo: document.body — jsdom only computes a live `display` value for
// v-show toggles when the element is connected to the document; detached
// mounts return a stale cached computed style (getComputedStyle keeps
// reporting the initial `display: none` even after the inline style is
// removed). Same workaround already used in PreviewPane.spec.js for its
// isVisible() assertions.
describe('FieldsDrawer', () => {
    it('renders the field count in the collapsed header', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.text()).toContain('Fields');
        expect(wrapper.text()).toContain('3'); // title + items + items.label
    });

    it('hides the table body until toggled open', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(false);
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('table').isVisible()).toBe(true);
    });

    it('indents a nested field row and shows the required-field dot on the required row', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        await wrapper.find('button').trigger('click');
        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[2].text()).toContain('label');
        expect(rows[0].find('[role="img"]').exists()).toBe(true); // title's required dot
        expect(rows[1].find('[role="img"]').exists()).toBe(false); // items has no required dot
    });

    it('renders an em-dash for a row with no declared type', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, {
            props: { fields: [{ key: 'untyped', label: 'X' }] },
            global: { plugins: [router] },
            attachTo: document.body,
        });
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('tbody tr').text()).toContain('—');
    });

    it('renders label from the canonical shape', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        await wrapper.find('button').trigger('click');
        expect(wrapper.text()).toContain('Title');
    });

    it('expands a row with extras into a verbatim detail block', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const fields = [
            { key: 'title', type: 'text', label: 'Title', maxlength: 120, mcp: ['hint'] },
            { key: 'plain', type: 'text', label: 'Plain' },
        ];
        const wrapper = mount(FieldsDrawer, { props: { fields }, global: { plugins: [router] }, attachTo: document.body });
        await wrapper.find('button').trigger('click');
        const rows = wrapper.findAll('tbody tr');
        // Row with extras carries the detail toggle; the plain row doesn't.
        expect(rows[0].find('button').exists()).toBe(true);
        expect(rows[1].find('button').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('"maxlength": 120');
        await rows[0].find('button').trigger('click');
        expect(wrapper.text()).toContain('"maxlength": 120');
    });

    it('starts open when the route query contains fields=1', async () => {
        const router = makeRouter();
        await router.push('/component/sample?fields=1');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(true);
    });

    it('collapses again after navigating to a route without ?fields=1', async () => {
        // Regression: the drawer instance persists across slug-only
        // navigations (App.vue renders it outside RouterView without
        // :key), so a stale `open` ref from a deep link used to leak
        // into every subsequently visited component.
        const router = makeRouter();
        await router.push('/component/sample?fields=1');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(true);

        await router.push('/component/other');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('table').isVisible()).toBe(false);
    });

    it('re-opens when navigating to another route with ?fields=1', async () => {
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(false);

        await router.push('/component/other?fields=1');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('table').isVisible()).toBe(true);
    });

    it('does NOT open when the route query is ?fields=0', async () => {
        // Regression: `'fields' in route.query` is true for ANY value of the
        // key, including '0' — only the literal '1' should open the drawer.
        const router = makeRouter();
        await router.push('/component/sample?fields=0');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(false);
    });

    it('collapses on a same-slug query-only navigation away from ?fields=1', async () => {
        // Regression: the watcher only tracked [route.params.slug, route.name],
        // so back/forward between /component/foo?fields=1 and /component/foo
        // (same slug, query-only change) never re-derived `open`.
        const router = makeRouter();
        await router.push('/component/sample?fields=1');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(true);

        await router.push('/component/sample');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('table').isVisible()).toBe(false);
    });

    it('keeps a manual toggle open across an unrelated re-render (no navigation)', async () => {
        // The watcher must only override `open` on actual navigation, not
        // clobber a manual toggle click.
        const router = makeRouter();
        await router.push('/component/sample');
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS }, global: { plugins: [router] }, attachTo: document.body });
        expect(wrapper.find('table').isVisible()).toBe(false);

        await wrapper.find('button').trigger('click');
        expect(wrapper.find('table').isVisible()).toBe(true);

        await wrapper.setProps({ fields: FIELDS });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('table').isVisible()).toBe(true);
    });
});
