import { describe, it, expect, afterEach } from 'vitest';
import { routeInfo } from './routeInfo.js';
import { resetRuntimeConfig } from './runtimeConfig.js';

function injectConfig(payload) {
    document.getElementById('sg-config')?.remove();
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify(payload);
    document.body.appendChild(el);
    resetRuntimeConfig();
}

afterEach(() => {
    document.getElementById('sg-config')?.remove();
    resetRuntimeConfig();
});

describe('routeInfo', () => {
    it('maps component/page/doc routes with their slug param', () => {
        expect(routeInfo({ name: 'component', params: { slug: 'hero' } })).toEqual({ type: 'component', slug: 'hero' });
        expect(routeInfo({ name: 'page', params: { slug: 'homepage' } })).toEqual({ type: 'page', slug: 'homepage' });
        expect(routeInfo({ name: 'doc', params: { slug: 'sample-doc' } })).toEqual({ type: 'doc', slug: 'sample-doc' });
    });

    it('maps overview with no slug', () => {
        expect(routeInfo({ name: 'overview', params: {} })).toEqual({ type: 'overview', slug: null });
    });

    it('maps the standalone icons route with no slug', () => {
        expect(routeInfo({ name: 'icons', params: {} })).toEqual({ type: 'icons', slug: null });
    });

    it('maps foundations, landing, and the not-found fallback all to type foundations (legacy landing-maps-to-foundations behavior)', () => {
        expect(routeInfo({ name: 'foundations', params: {} })).toEqual({ type: 'foundations', slug: null });
        expect(routeInfo({ name: 'landing', params: {} })).toEqual({ type: 'foundations', slug: null });
        expect(routeInfo({ name: 'not-found-fallback', params: {} })).toEqual({ type: 'foundations', slug: null });
    });

    it('maps the dead-but-preserved fields route to type fields with no slug', () => {
        expect(routeInfo({ name: 'fields', params: {} })).toEqual({ type: 'fields', slug: null });
    });

    it('falls back to foundations for an unrecognised route name', () => {
        expect(routeInfo({ name: undefined, params: {} })).toEqual({ type: 'foundations', slug: null });
    });

    it('maps the overview grid with no slug', () => {
        expect(routeInfo({ name: 'grid', params: {} })).toEqual({ type: 'grid', slug: null });
    });

    it('maps the landing to the grid when `overview.default: grid` is configured', () => {
        injectConfig({ landing: 'grid' });
        expect(routeInfo({ name: 'landing', params: {} })).toEqual({ type: 'grid', slug: null });
        // The not-found fallback keeps rendering Foundations either way.
        expect(routeInfo({ name: 'not-found-fallback', params: {} })).toEqual({ type: 'foundations', slug: null });
    });
});
