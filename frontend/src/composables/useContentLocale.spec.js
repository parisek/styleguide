import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { defineComponent, h, nextTick } from 'vue';
import { useContentLocale } from './useContentLocale.js';
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
});

afterEach(() => {
    delete document.documentElement.dataset.defaultLocale;
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
});
