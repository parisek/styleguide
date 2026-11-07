import { describe, it, expect } from 'vitest';
import { entryFromUrl } from './entryLink.js';

const FROM = 'https://site.test/styleguide/render/page/home';

describe('entryFromUrl', () => {
    it('maps an SPA entry link to its entry', () => {
        expect(entryFromUrl('/styleguide/page/about', FROM, '/styleguide')).toEqual({ type: 'page', slug: 'about', variant: null, locale: null, theme: null, hash: null });
        expect(entryFromUrl('/styleguide/component/hero/', FROM, '/styleguide')).toEqual({ type: 'component', slug: 'hero', variant: null, locale: null, theme: null, hash: null });
        expect(entryFromUrl('/styleguide/doc/intro', FROM, '/styleguide')).toEqual({ type: 'doc', slug: 'intro', variant: null, locale: null, theme: null, hash: null });
    });

    it('maps a render link back to its entry', () => {
        expect(entryFromUrl('/styleguide/render/page/about?canvas=1&locale=ar', FROM, '/styleguide'))
            .toEqual({ type: 'page', slug: 'about', variant: null, locale: 'ar', theme: null, hash: null });
    });

    it('keeps variant, locale and theme, and drops every other query key', () => {
        expect(entryFromUrl('/styleguide/page/about?variant=b&locale=en&theme=dark&canvas=1&_r=2#team', FROM, '/styleguide'))
            .toEqual({ type: 'page', slug: 'about', variant: 'b', locale: 'en', theme: 'dark', hash: '#team' });
        expect(entryFromUrl('/styleguide/page/about?theme=neon', FROM, '/styleguide'))
            .toEqual({ type: 'page', slug: 'about', variant: null, locale: null, theme: null, hash: null });
    });

    it('keeps a variant on its own', () => {
        expect(entryFromUrl('/styleguide/page/about?variant=dark#team', FROM, '/styleguide'))
            .toEqual({ type: 'page', slug: 'about', variant: 'dark', locale: null, theme: null, hash: '#team' });
    });

    it('resolves a relative link against the iframe document', () => {
        expect(entryFromUrl('../../page/about', FROM, '/styleguide')).toEqual({ type: 'page', slug: 'about', variant: null, locale: null, theme: null, hash: null });
    });

    it('honours a host base path in front of the mount', () => {
        const from = 'https://site.test/app/styleguide/render/page/home';
        expect(entryFromUrl('/app/styleguide/page/about', from, '/app/styleguide')).toEqual({ type: 'page', slug: 'about', variant: null, locale: null, theme: null, hash: null });
        expect(entryFromUrl('/styleguide/page/about', from, '/app/styleguide')).toBeNull();
    });

    it.each([
        ['another origin', 'https://other.test/styleguide/page/about'],
        ['mailto', 'mailto:info@example.com'],
        ['an asset', '/themes/x/dist/css/style.css'],
        ['the overview', '/styleguide/overview'],
        ['foundations render', '/styleguide/render/foundations/index'],
        ['a nested path', '/styleguide/page/about/team'],
        ['a prefix that only looks like the mount', '/styleguide-old/page/about'],
        ['an unknown kind', '/styleguide/widget/about'],
    ])('returns null for %s', (_, href) => {
        expect(entryFromUrl(href, FROM, '/styleguide')).toBeNull();
    });

    it('maps a fragment to the frame\'s own entry, which the caller compares', () => {
        expect(entryFromUrl('#main', FROM, '/styleguide')).toEqual({ type: 'page', slug: 'home', variant: null, locale: null, theme: null, hash: '#main' });
        expect(entryFromUrl('/styleguide/page/home#', FROM, '/styleguide')?.hash).toBeNull();
    });

    it('returns null for an unparsable base document URL', () => {
        expect(entryFromUrl('/styleguide/page/about', 'not a url', '/styleguide')).toBeNull();
    });
});
