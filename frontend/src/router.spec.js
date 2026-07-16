import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { router } from './router.js';
import FieldsView from './views/FieldsView.vue';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('router deep links', () => {
    it.each([
        ['/', 'landing'],
        ['/component/hero', 'component'],
        ['/page/homepage', 'page'],
        ['/doc/sample-doc', 'doc'],
        ['/overview', 'overview'],
        ['/foundations', 'foundations'],
        ['/icons', 'icons'],
        ['/fields', 'fields'],
        ['/nonexistent/garbage/path', 'not-found-fallback'],
    ])('resolves %s to the %s route', async (path, expectedName) => {
        await router.push(path);
        expect(router.currentRoute.value.name).toBe(expectedName);
    });

    it('extracts the slug param for component/page/doc routes', async () => {
        await router.push('/component/hero');
        expect(router.currentRoute.value.params.slug).toBe('hero');
    });

    it('does not rewrite the address bar for the bare landing path (no redirect)', async () => {
        await router.push('/');
        expect(router.currentRoute.value.fullPath).toBe('/');
    });

    // /fields used to be dead-but-preserved, rendering PreviewView's empty
    // state; #95 revives it as a real cross-component overview.
    it('resolves /fields to the FieldsView component', async () => {
        await router.push('/fields');
        expect(router.currentRoute.value.matched[0].components.default).toBe(FieldsView);
    });
});
