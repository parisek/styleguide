import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref, defineComponent, h } from 'vue';
import { useVariant } from './useVariant.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
        ],
    });
}

// Mounts a bare host component so useVariant() (which calls useRoute()/
// useRouter()) runs inside real router-aware setup() context -- mirrors the
// pattern already used by Sidebar.spec.js/UsagePanel.spec.js for other
// composables/components that depend on vue-router injection.
async function mountVariant(entry, initialPath = '/component/multi') {
    const router = makeRouter();
    await router.push(initialPath);
    await router.isReady();

    let result;
    const Host = defineComponent({
        setup() {
            result = useVariant(entry);
            return () => h('div');
        },
    });
    const wrapper = mount(Host, { global: { plugins: [router] } });
    return { wrapper, router, ...result };
}

describe('useVariant', () => {
    it('defaults to null when the URL carries no ?variant=', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant } = await mountVariant(entry);
        expect(variant.value).toBeNull();
    });

    it('setVariant writes the id into the URL query', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant, setVariant, router } = await mountVariant(entry);
        await setVariant('secondary');
        expect(router.currentRoute.value.query.variant).toBe('secondary');
        expect(variant.value).toBe('secondary');
    });

    it('setVariant(null) removes the query param', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant, setVariant, router } = await mountVariant(entry);
        await setVariant('secondary');
        await setVariant(null);
        expect(router.currentRoute.value.query.variant).toBeUndefined();
        expect(variant.value).toBeNull();
    });

    it('a ?variant= id absent from the entry\'s discovered variants resolves to null (unknown/removed variant)', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant } = await mountVariant(entry, '/component/multi?variant=retired');
        expect(variant.value).toBeNull();
    });

    it('a valid deep-linked ?variant= resolves against the entry\'s discovered variants', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant } = await mountVariant(entry, '/component/multi?variant=secondary');
        expect(variant.value).toBe('secondary');
    });

    it('resets to null after navigating to a route with no ?variant= of its own', async () => {
        const entry = ref({ id: 'multi', variants: [{ id: 'secondary', label: 'Secondary style' }] });
        const { variant, setVariant, router } = await mountVariant(entry);
        await setVariant('secondary');
        expect(variant.value).toBe('secondary');

        await router.push('/component/other');
        expect(variant.value).toBeNull();
    });
});
