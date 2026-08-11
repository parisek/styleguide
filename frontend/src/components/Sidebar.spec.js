import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import Sidebar from './Sidebar.vue';
import { GENERIC_FAVICON } from '../lib/documentChrome.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
            { path: '/overview', name: 'overview', component: { template: '<div/>' } },
            { path: '/foundations', name: 'foundations', component: { template: '<div/>' } },
            { path: '/icons', name: 'icons', component: { template: '<div/>' } },
            { path: '/fields', name: 'fields', component: { template: '<div/>' } },
        ],
    });
}

// Sidebar.vue reads the SPA config via readSpaConfig(), which throws if the
// #sg-config script element isn't present in the document — mirror the real
// PHP-injected payload here so every mount() below has something to read.
// Tests that care about specific favicon/projectName values call this again
// with overrides before mounting (removes-then-reappends, so it's safe to
// call more than once per test).
function stubSgConfig(overrides = {}) {
    document.getElementById('sg-config')?.remove();
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify({
        locale: 'cs', projectName: 'Styleguide', favicon: '', title: 'Styleguide', baseUrl: '/styleguide', ...overrides,
    });
    document.body.appendChild(el);
}

async function mountSidebar(initialPath = '/foundations', mountOptions = {}) {
    setActivePinia(createPinia());
    const catalog = useCatalogStore();
    catalog.items = [
        { id: 'widget-one', name: 'Widget - one', category: 'Block', has_styleguide: true },
        { id: 'widget-two', name: 'Widget - two', category: 'Block', has_styleguide: true },
        { id: 'widget-three', name: 'Widget - three', category: 'Block', has_styleguide: true },
        { id: 'gizmo', name: 'Gizmo', category: '', has_styleguide: true },
    ];
    catalog.pages = [{ id: 'homepage', name: 'Homepage', has_styleguide: true }];
    catalog.docs = [];
    catalog.loading = false;
    useI18nStore().strings = { nav: { docs: 'Docs', overview: 'Overview', foundations: 'Foundations', icons: 'Icons', fields: 'Fields', styleguide: 'Styleguide' }, sections: { basic: 'Basic', blocks: 'Blocks', gutenberg: 'Gutenberg', pages: 'Pages' }, search: { label: 'Search', placeholder: 'Search...' } };

    const router = makeRouter();
    await router.push(initialPath);
    const wrapper = mount(Sidebar, { global: { plugins: [router] }, ...mountOptions });
    await router.isReady();
    return { wrapper, router };
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), addListener: vi.fn() }));
    localStorage.clear();
    stubSgConfig();
    delete document.documentElement.dataset.locales;
    delete document.documentElement.dataset.defaultLocale;
});

describe('Sidebar', () => {
    it('renders a Widget group for a >=3 prefix cluster with suffix-only children', async () => {
        const { wrapper } = await mountSidebar();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget');
        expect(wrapper.text()).toContain('One');
        expect(wrapper.text()).not.toContain('Widget - one');
    });

    it('renders the Gizmo singleton flat with its full name', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.text()).toContain('Gizmo');
    });

    // Chevron-less group rows: the count badge alone signals a group now --
    // no arrow glyph pushing the label out of alignment with flat sibling
    // items. The whole row remains the toggle (aria-expanded carries the
    // state now that there's no visual chevron cue).
    it('renders the Widget group toggle with no chevron svg, aria-expanded wired to its open state', async () => {
        const { wrapper } = await mountSidebar();
        const groupToggle = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Widget');
        expect(groupToggle.find('svg').exists()).toBe(false);
        expect(groupToggle.attributes('aria-expanded')).toBe('true');

        await groupToggle.trigger('click');
        await wrapper.vm.$nextTick();
        expect(groupToggle.attributes('aria-expanded')).toBe('false');
    });

    it('navigates via router.push when a component link is clicked, then closes the sidebar on mobile', async () => {
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), addListener: vi.fn() }));
        const { wrapper, router } = await mountSidebar();
        const ui = useUiStore();
        ui.sidebarOpen = true;
        const gizmoLink = wrapper.findAll('a').find((a) => a.text() === 'Gizmo');
        await gizmoLink.trigger('click');
        // router.push() resolves its navigation guard chain over many
        // microtask ticks — a single trigger()-awaited Vue.nextTick() is not
        // enough to observe the completed navigation. flushPromises() (a
        // macrotask wait) lets that chain fully drain first; this is the
        // documented vue-router + vue-test-utils pattern for asserting on
        // router.currentRoute after a programmatic push.
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/component/gizmo');
        expect(ui.sidebarOpen).toBe(false);
    });

    it('flattens the Widget group to full names while a search query is active', async () => {
        const { wrapper } = await mountSidebar();
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget - one');
    });

    // Review finding baked in (Task 5): the old global Escape-clears-the-
    // filter behavior (useSearchShortcuts.js, now retired -- the command
    // palette owns the global ⌘K/Ctrl+K shortcut) survives, but deliberately
    // narrowed to only fire while this specific input has focus (see
    // onFilterEscape's WHY comment in Sidebar.vue). attachTo: document.body
    // is required here (existing convention, see useSearchShortcuts.spec.js
    // in git history) because document.activeElement only reflects reality
    // for elements actually attached to the document.
    it('Escape on the focused filter input clears the query and blurs it', async () => {
        const { wrapper } = await mountSidebar('/foundations', { attachTo: document.body });
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        await wrapper.vm.$nextTick();
        const input = wrapper.find('input[type="text"]');
        input.element.focus();
        expect(document.activeElement).toBe(input.element);

        await input.trigger('keydown', { key: 'Escape' });

        expect(ui.searchQuery).toBe('');
        expect(document.activeElement).not.toBe(input.element);
        wrapper.unmount();
    });

    it('toggleSection persists to sg-sections', async () => {
        const { wrapper } = await mountSidebar();
        // Target the label span, not the whole button: the button now also
        // renders a sibling count-badge span (e.g. "Basic" + "1"), so
        // button.text() would concatenate both and never equal 'Basic'.
        const toggle = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Basic');
        await toggle.trigger('click');
        await wrapper.vm.$nextTick();
        expect(JSON.parse(localStorage.getItem('sg-sections')).basic).toBe(false);
    });

    it('renders the section header count badge with the filtered item count', async () => {
        const { wrapper } = await mountSidebar();
        // Fixture: 3 items categorised 'Block' -> Blocks section, 1 ('gizmo',
        // uncategorised) -> Basic section.
        const blocksButton = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Blocks');
        const basicButton = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Basic');
        expect(blocksButton.findAll('span')[1].text()).toBe('3');
        expect(basicButton.findAll('span')[1].text()).toBe('1');
    });

    it("wires the header favicon/name from config's favicon/projectName", async () => {
        stubSgConfig({ projectName: 'Acme', favicon: '/f.svg' });
        const { wrapper } = await mountSidebar();
        const favicon = wrapper.find('#sg-favicon');
        expect(favicon.attributes('src')).toBe('/f.svg');
        expect(favicon.attributes('alt')).toBe('Acme');
        expect(wrapper.find('#sg-project-name').text()).toBe('Acme');
    });

    it('swaps the header favicon to the generic glyph when the image fails to load', async () => {
        stubSgConfig({ projectName: 'Acme', favicon: '/broken.svg' });
        const { wrapper } = await mountSidebar();
        const favicon = wrapper.find('#sg-favicon');
        await favicon.trigger('error');
        expect(favicon.attributes('src')).toBe(GENERIC_FAVICON);
    });

    it('marks the Overview nav item active when on /overview', async () => {
        const { wrapper } = await mountSidebar('/overview');
        const overviewLink = wrapper.findAll('a').find((a) => a.text() === 'Overview');
        expect(overviewLink.classes()).toContain('bg-red-600/10');
        expect(overviewLink.classes()).toContain('text-red-700');
    });

    // Standalone icon catalog (#87): the nav entry is gated on the
    // server-side yaml-shape check injected as sg-config `hasIcons`, so
    // projects without an icons: block never render a dead menu item.
    it('hides the Icons nav item when sg-config carries no hasIcons flag', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.findAll('a').find((a) => a.text() === 'Icons')).toBeUndefined();
    });

    it('shows the Icons nav item when hasIcons is true and marks it active on /icons', async () => {
        stubSgConfig({ hasIcons: true });
        const { wrapper } = await mountSidebar('/icons');
        const iconsLink = wrapper.findAll('a').find((a) => a.text() === 'Icons');
        expect(iconsLink).toBeDefined();
        expect(iconsLink.classes()).toContain('bg-red-600/10');
        expect(iconsLink.classes()).toContain('text-red-700');
    });

    // Cross-component fields overview (#95): unlike Icons above, this entry
    // is gated on live catalogue data (catalog.hasFields), not a
    // server-injected config flag, since "any component declares fields"
    // is only knowable once the components API response has landed.
    it('hides the Fields nav item when no catalog item declares fields', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.findAll('a').find((a) => a.text() === 'Fields')).toBeUndefined();
    });

    it('shows the Fields nav item when a component has fields and marks it active on /fields', async () => {
        const { wrapper } = await mountSidebar('/fields');
        const catalog = useCatalogStore();
        catalog.items.push({ id: 'has-fields', name: 'Has Fields', category: 'Block', has_styleguide: true, fields: [{ key: 'title', type: 'text' }] });
        await wrapper.vm.$nextTick();
        const fieldsLink = wrapper.findAll('a').find((a) => a.text() === 'Fields');
        expect(fieldsLink).toBeDefined();
        expect(fieldsLink.classes()).toContain('bg-red-600/10');
        expect(fieldsLink.classes()).toContain('text-red-700');
    });

    // hasFields must agree with FieldsView's own group filter
    // (has_styleguide !== false) — otherwise the nav entry can point at a
    // view with nothing to show, if the only fields-bearing component is
    // itself hidden from the styleguide.
    it('hides the Fields nav item when the only component with fields has has_styleguide: false', async () => {
        const { wrapper } = await mountSidebar();
        const catalog = useCatalogStore();
        catalog.items.push({ id: 'hidden-fields', name: 'Hidden Fields', category: 'Block', has_styleguide: false, fields: [{ key: 'title', type: 'text' }] });
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('a').find((a) => a.text() === 'Fields')).toBeUndefined();
    });

    // Regression test for the real page-load timing: main.js calls
    // `catalog.init()` (async fetch) WITHOUT awaiting it before `app.mount()`,
    // so Sidebar.vue's very first render always sees an empty catalog and the
    // items populate only later, on a subsequent reactive update -- never
    // before mount. Every other test in this file (via mountSidebar())
    // seeds catalog.items BEFORE mount(), which only ever exercises v-show's
    // *first* evaluation and can't catch a directive that freezes after that
    // first render. This test mounts empty first, matching the real
    // sequence, then seeds items after mount to catch exactly that gap: a
    // `v-show` living on the same element as `v-for` only evaluates once, at
    // that node's creation, and never re-checks on later updates even though
    // sibling bindings (e.g. the count badge) keep updating correctly.
    it('reveals a section once catalog items arrive asynchronously after mount (real boot sequence)', async () => {
        setActivePinia(createPinia());
        const catalog = useCatalogStore();
        catalog.items = [];
        catalog.pages = [];
        catalog.docs = [];
        catalog.loading = true;
        useI18nStore().strings = { nav: { docs: 'Docs', overview: 'Overview', foundations: 'Foundations', icons: 'Icons', fields: 'Fields', styleguide: 'Styleguide' }, sections: { basic: 'Basic', blocks: 'Blocks', gutenberg: 'Gutenberg', pages: 'Pages' }, search: { label: 'Search', placeholder: 'Search...' } };

        const router = makeRouter();
        await router.push('/foundations');
        const wrapper = mount(Sidebar, { global: { plugins: [router] } });
        await router.isReady();
        await wrapper.vm.$nextTick();

        // Before the async catalog resolves: matches the real first render —
        // the Basic section must be hidden (zero items).
        const basicSectionBefore = wrapper.findAll('button')
            .find((b) => b.find('span').exists() && b.find('span').text() === 'Basic')
            ?.element.closest('div');
        expect(basicSectionBefore?.style.display).toBe('none');

        // Simulate catalog.init()'s fetch resolving after app.mount() —
        // the real page-load sequence, not the "seed before mount" shortcut
        // every other test in this file uses.
        catalog.items = [{ id: 'gizmo', name: 'Gizmo', category: '', has_styleguide: true }];
        catalog.loading = false;
        await wrapper.vm.$nextTick();

        const basicButton = wrapper.findAll('button').find((b) => b.find('span').exists() && b.find('span').text() === 'Basic');
        expect(basicButton).toBeTruthy();
        const basicSectionAfter = basicButton.element.closest('div');
        expect(basicSectionAfter.style.display).not.toBe('none');
        expect(wrapper.text()).toContain('Gizmo');
    });
});

describe('Sidebar — locale switcher', () => {
    it('renders no switcher entries when the project discovers no catalogues (no translations_path)', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.text()).not.toContain('cs_CZ');
        expect(wrapper.findAll('button').some((b) => b.text() === 'en')).toBe(false);
    });

    it('lists every discovered locale, not just the chrome-only cs/en set', async () => {
        document.documentElement.dataset.locales = JSON.stringify(['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT']);
        const { wrapper } = await mountSidebar();
        const labels = wrapper.findAll('button').map((b) => b.text()).filter((t) => t.length > 0);
        for (const loc of ['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT']) {
            expect(labels).toContain(loc);
        }
    });

    it('clicking a discovered locale outside the chrome SUPPORTED set loads English chrome strings and switches content locale', async () => {
        document.documentElement.dataset.locales = JSON.stringify(['cs_CZ', 'en_US', 'sk_SK']);
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ nav: { overview: 'Overview' } }) });
        const { wrapper } = await mountSidebar();

        const skButton = wrapper.findAll('button').find((b) => b.text() === 'sk_SK');
        expect(skButton).toBeTruthy();
        await skButton.trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledWith('/styleguide/assets/locales/en.json', { cache: 'no-cache' });
        expect(useI18nStore().locale).toBe('sk_SK');
        expect(localStorage.getItem('sg-locale')).toBe('sk_SK');
    });
});
