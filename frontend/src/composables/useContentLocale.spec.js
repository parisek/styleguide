import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { defineComponent, h, nextTick } from 'vue';
import { useContentLocale, syncStoredLocale } from './useContentLocale.js';
import { STORAGE_KEY } from '../lib/contentLocale.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
        ],
    });
}

// Mounts a bare host component so useContentLocale() (which calls
// useRoute()) runs inside real router-aware setup() context -- same pattern
// as useVariant.spec.js.
async function mountContentLocale(initialPath = '/component/hero') {
    // The stored value lives in a module-level ref (so a switcher click is
    // reactive and shared between Sidebar and App). These tests drive
    // localStorage directly, so the mirror is re-read here rather than
    // holding whatever the previous test left in it.
    syncStoredLocale();

    const router = makeRouter();
    await router.push(initialPath);
    await router.isReady();

    let result;
    const Host = defineComponent({
        setup() {
            result = useContentLocale();
            return () => h('div');
        },
    });
    const wrapper = mount(Host, { global: { plugins: [router] } });
    await nextTick();
    return { wrapper, router, ...result };
}

beforeEach(() => {
    localStorage.clear();
    document.documentElement.dataset.defaultLocale = 'en';
    // Discovered set the "known" checks below resolve against -- mirrors a
    // project with translations_path configured for cs/en (the two the
    // pre-existing suite exercised) so those cases keep asserting the same
    // thing; sk_SK/pl_PL/it_IT cover the newly-reachable-locale cases.
    document.documentElement.dataset.locales = JSON.stringify(['cs', 'en', 'sk_SK', 'pl_PL', 'it_IT']);
});

afterEach(() => {
    delete document.documentElement.dataset.defaultLocale;
    delete document.documentElement.dataset.locales;
});

describe('useContentLocale', () => {
    it('a stored value is used on load when the URL carries no explicit ?locale=', async () => {
        localStorage.setItem(STORAGE_KEY, 'cs');
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('cs');
    });

    it('an explicit ?locale= in the URL beats a stored value and does not overwrite it', async () => {
        localStorage.setItem(STORAGE_KEY, 'cs');
        const { contentLocale } = await mountContentLocale('/component/hero?locale=en');
        expect(contentLocale.value).toBe('en');
        // The stored preference is left exactly as the visitor set it --
        // a shared/deep link must not silently overwrite it.
        expect(localStorage.getItem(STORAGE_KEY)).toBe('cs');
    });

    it('a stale stored locale (catalogue no longer offered) falls back to the YAML default and clears the key', async () => {
        localStorage.setItem(STORAGE_KEY, 'de');
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('en');
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('falls back to the YAML default when nothing is stored and no URL locale is present', async () => {
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('en');
    });

    it('setContentLocale persists the switcher\'s choice for the next load', async () => {
        const { setContentLocale } = await mountContentLocale();
        setContentLocale('cs');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('cs');
    });

    it('setContentLocale does not touch the URL query -- an explicit ?locale= stays authoritative', async () => {
        const { setContentLocale, contentLocale, router } = await mountContentLocale('/component/hero?locale=en');
        setContentLocale('cs');
        await nextTick();
        expect(router.currentRoute.value.query.locale).toBe('en');
        expect(contentLocale.value).toBe('en');
    });

    it('a stored locale outside the chrome-only set (sk_SK) resolves fine as long as it is a discovered catalogue', async () => {
        localStorage.setItem(STORAGE_KEY, 'sk_SK');
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('sk_SK');
        // Never cleared -- sk_SK IS in data-locales. Chrome-string coverage
        // (stores/i18n.js's SUPPORTED) is a separate concern this
        // composable never gates content-locale resolution on.
        expect(localStorage.getItem(STORAGE_KEY)).toBe('sk_SK');
    });

    it('a stored locale absent from the discovered set is treated as stale even though it looks like a real code', async () => {
        localStorage.setItem(STORAGE_KEY, 'fr_FR');
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('en');
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('migrates a pre-collapse styleguide:locale value into the shared sg-locale key on first read', async () => {
        localStorage.setItem('styleguide:locale', 'sk_SK');
        const { contentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('sk_SK');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('sk_SK');
        expect(localStorage.getItem('styleguide:locale')).toBeNull();
    });

    it('a switcher click updates the resolved locale without a navigation', async () => {
        // localStorage is not a reactive source. Reading it straight from the
        // computed left nothing to recompute on, so the click persisted the
        // new locale and the iframe URL kept serving the old one until the
        // visitor navigated or reloaded.
        const { contentLocale, setContentLocale } = await mountContentLocale();
        expect(contentLocale.value).toBe('en');

        setContentLocale('cs');
        await nextTick();

        expect(contentLocale.value).toBe('cs');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('cs');
    });

    it('a click in one instance is seen by another, because the switcher and the preview are separate callers', async () => {
        // Sidebar owns the switcher, App owns the iframe URL, and each calls
        // useContentLocale() itself. A per-instance mirror would leave the
        // preview holding its own stale copy.
        const sidebar = await mountContentLocale();
        const preview = await mountContentLocale();

        sidebar.setContentLocale('cs');
        await nextTick();

        expect(preview.contentLocale.value).toBe('cs');
    });

    it('an explicit ?locale= still outranks a later switcher click', async () => {
        // Precedence is unchanged by the mirror: the URL is the deterministic
        // signal shared links and the visual harvester depend on.
        const { contentLocale, setContentLocale } = await mountContentLocale('/component/hero?locale=sk_SK');
        expect(contentLocale.value).toBe('sk_SK');

        setContentLocale('cs');
        await nextTick();

        expect(contentLocale.value).toBe('sk_SK');
        expect(localStorage.getItem(STORAGE_KEY)).toBe('cs');
    });
});
