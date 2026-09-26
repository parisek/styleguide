import { describe, it, expect, beforeEach } from 'vitest';
import { buildRenderSrc } from './renderSrc.js';
import { resetRuntimeConfig } from './runtimeConfig.js';

beforeEach(() => {
    document.body.innerHTML = '';
    resetRuntimeConfig();
});

describe('buildRenderSrc', () => {
    it('keeps the bare URL when nothing is toggled', () => {
        expect(buildRenderSrc({ type: 'component', slug: 'card' })).toBe('/styleguide/render/component/card');
        expect(buildRenderSrc({ type: 'page', slug: 'home' })).toBe('/styleguide/render/page/home');
    });

    it('renders foundations and icons at their index', () => {
        expect(buildRenderSrc({ type: 'foundations' })).toBe('/styleguide/render/foundations/index');
        expect(buildRenderSrc({ type: 'icons', theme: 'dark' })).toBe('/styleguide/render/icons/index?theme=dark');
    });

    it('returns null for a route with no iframe', () => {
        expect(buildRenderSrc({ type: 'overview' })).toBeNull();
        expect(buildRenderSrc({ type: 'grid' })).toBeNull();
        expect(buildRenderSrc({ type: 'component', slug: null })).toBeNull();
    });

    it('appends reload nonce, theme, variant and locale in that order', () => {
        expect(buildRenderSrc({
            type: 'component', slug: 'card', variant: 'dark bg', theme: 'dark', reloadNonce: 3,
            contentLocale: 'en_US', defaultLocale: 'cs_CZ',
        })).toBe('/styleguide/render/component/card?_r=3&theme=dark&variant=dark%20bg&locale=en_US');
    });

    it('omits the locale when it equals the default, or when either is unknown', () => {
        expect(buildRenderSrc({ type: 'component', slug: 'c', contentLocale: 'cs_CZ', defaultLocale: 'cs_CZ' })).toBe('/styleguide/render/component/c');
        expect(buildRenderSrc({ type: 'component', slug: 'c', contentLocale: 'en_US', defaultLocale: '' })).toBe('/styleguide/render/component/c');
        expect(buildRenderSrc({ type: 'component', slug: 'c', contentLocale: '', defaultLocale: 'cs_CZ' })).toBe('/styleguide/render/component/c');
    });
});
