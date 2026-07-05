import { describe, it, expect, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SearchPalette from './SearchPalette.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

// @pinia/testing isn't a dependency of this project (checked: not in
// package.json/package-lock.json) -- every other spec in this codebase
// drives a real Pinia store via setActivePinia/createPinia instead, so this
// file follows that same convention rather than introducing a new one.
function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
            { path: '/page/:slug', name: 'page', component: { template: '<div/>' } },
            { path: '/doc/:slug', name: 'doc', component: { template: '<div/>' } },
        ],
    });
}

// category/description left blank across every fixture entry below on
// purpose -- scoreEntry()'s own field-weighting behaviour is already
// covered exhaustively in lib/searchMatch.spec.js; blank non-name fields
// here keep query 'o'/'zzz' style assertions in this file from being thrown
// off by an incidental category-field match.
async function mountPalette(initialPath = '/') {
    setActivePinia(createPinia());
    const catalog = useCatalogStore();
    catalog.items = [
        { id: 'multi', name: 'Multi', category: '', description: '', hasStyleguide: true },
        { id: 'gizmo', name: 'Gizmo', category: '', description: '', hasStyleguide: true },
    ];
    catalog.pages = [{ id: 'homepage', name: 'Homepage', category: '', description: '', hasStyleguide: true }];
    // Deliberately no letter 'o' in this name -- keeps it out of the 'o'
    // query used by several tests below, so those tests can assert an exact
    // 2-group (component + page) result set without an incidental 3rd doc
    // match.
    catalog.docs = [{ id: 'guide', name: 'Guide', category: '', description: '' }];

    useI18nStore().strings = {
        search: {
            label: 'Search',
            placeholder: 'Search…',
            no_results: 'No results',
            group_components: 'Components',
            group_pages: 'Pages',
            group_docs: 'Documentation',
            hint_navigate: '↵ open',
            hint_close: 'Esc close',
        },
    };

    const router = makeRouter();
    await router.push(initialPath);
    const wrapper = mount(SearchPalette, { global: { plugins: [router] }, attachTo: document.body });
    await router.isReady();
    return { wrapper, router };
}

// The global ⌘K/Ctrl+K/Escape/Arrow listener lives on `window`
// (onMounted(() => window.addEventListener(...))) independently of what the
// component's own v-if-gated template currently renders, so dispatching
// directly on window -- rather than wrapper.trigger(), which needs an
// existing root DOM node -- exercises it exactly as the real app does, and
// works before the dialog has ever opened. Mirrors the retired
// useSearchShortcuts.spec.js's own dispatch style.
function pressKey(key, opts = {}) {
    window.dispatchEvent(new KeyboardEvent('keydown', { key, ...opts }));
}

let wrapper;

afterEach(() => {
    wrapper?.unmount();
});

describe('SearchPalette', () => {
    it('opens on Cmd+K and toggles closed on a second Cmd+K press', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);

        pressKey('k', { metaKey: true });
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('opens on Ctrl+K', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { ctrlKey: true });
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    it('closes on Escape', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();

        pressKey('Escape');
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('carries the expected dialog/listbox aria wiring while open', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();

        const dialog = wrapper.find('[role="dialog"]');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-label')).toBe('Search');

        await wrapper.find('input').setValue('o');
        await flushPromises();
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
        expect(wrapper.findAll('[role="option"]').length).toBeGreaterThan(0);
    });

    it('groups results by section and resets the active row to the top on every query edit', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();

        // 'o': "Gizmo" (component) and "Homepage" (page) match on name;
        // "Multi"/"Sample Doc" don't -- two groups, one row each.
        await wrapper.find('input').setValue('o');
        await flushPromises();

        expect(wrapper.text()).toContain('Components');
        expect(wrapper.text()).toContain('Pages');
        expect(wrapper.text()).not.toContain('Documentation');
        const active = wrapper.findAll('[data-active="true"]');
        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Gizmo');
    });

    it('moves the active row with ArrowDown and wraps around with ArrowUp', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();
        await wrapper.find('input').setValue('o');
        await flushPromises();
        // Fixture yields exactly 2 rows for 'o': Gizmo (component), Homepage (page).

        pressKey('ArrowDown');
        await flushPromises();
        let active = wrapper.findAll('[data-active="true"]');
        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Homepage');

        // Wraps: ArrowDown again from the last row goes back to the first.
        pressKey('ArrowDown');
        await flushPromises();
        active = wrapper.findAll('[data-active="true"]');
        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Gizmo');

        // ArrowUp from the first row wraps to the last.
        pressKey('ArrowUp');
        await flushPromises();
        active = wrapper.findAll('[data-active="true"]');
        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Homepage');
    });

    it('Enter navigates to the active result via router.push and closes the palette', async () => {
        const mounted = await mountPalette();
        wrapper = mounted.wrapper;
        const { router } = mounted;

        pressKey('k', { metaKey: true });
        await flushPromises();
        await wrapper.find('input').setValue('multi');
        await flushPromises();
        pressKey('Enter');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/component/multi');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('clicking a result also navigates and closes the palette', async () => {
        const mountResult = await mountPalette();
        wrapper = mountResult.wrapper;
        const { router } = mountResult;
        pressKey('k', { metaKey: true });
        await flushPromises();
        await wrapper.find('input').setValue('homepage');
        await flushPromises();

        await wrapper.find('[role="option"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/page/homepage');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('shows the no-results state for a query that matches nothing', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();
        await wrapper.find('input').setValue('zzzzz');
        await flushPromises();

        expect(wrapper.text()).toContain('No results');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('highlights the matched substring as a separate, non-v-html text segment', async () => {
        ({ wrapper } = await mountPalette());
        pressKey('k', { metaKey: true });
        await flushPromises();
        await wrapper.find('input').setValue('gizmo');
        await flushPromises();

        // The matched segment is its own <span>, not raw markup injected via
        // v-html -- SearchPalette.vue's option row has no v-html binding at
        // all, so there is no way for this highlight to become an
        // HTML-injection surface regardless of what a catalog entry's name
        // contains.
        const matched = wrapper.find('[data-matched="true"]');
        expect(matched.exists()).toBe(true);
        expect(matched.text().toLowerCase()).toBe('gizmo');
    });
});
