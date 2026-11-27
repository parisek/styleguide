import { describe, it, expect } from 'vitest';
import { anchorOf, entryFromUrl, linkUrl } from './entryLink.js';

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

describe('anchorOf and linkUrl', () => {
    function click(target) {
        let seen = null;
        const listener = (event) => { seen = anchorOf(event); };
        document.addEventListener('click', listener);
        target.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true }));
        document.removeEventListener('click', listener);
        return seen;
    }

    it('finds an HTML link from a child', () => {
        document.body.innerHTML = '<a id="a" href="/styleguide/page/about"><span id="s">About</span></a>';
        expect(click(document.getElementById('s'))?.id).toBe('a');
    });

    it('finds a link inside an open shadow root', () => {
        document.body.innerHTML = '<div id="host"></div>';
        const root = document.getElementById('host').attachShadow({ mode: 'open' });
        root.innerHTML = '<a id="inner" href="/styleguide/page/about">About</a>';
        expect(click(root.getElementById('inner'))?.id).toBe('inner');
    });

    it('finds an SVG link and resolves its href and xlink:href', () => {
        document.body.innerHTML = '<svg><a id="svg" href="/styleguide/page/about"><text id="t">About</text></a>'
            + '<a id="x"><text id="tx">X</text></a></svg>';
        document.getElementById('x').setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '/styleguide/page/x');
        const svg = click(document.getElementById('t'));
        expect(svg?.id).toBe('svg');
        expect(linkUrl(svg)).toBe(new URL('/styleguide/page/about', document.baseURI).href);
        expect(linkUrl(click(document.getElementById('tx')))).toBe(new URL('/styleguide/page/x', document.baseURI).href);
    });

    it('takes an inner link with href="" as the browser does', () => {
        document.body.innerHTML = '<a id="outer" href="/styleguide/page/about"><span><a id="inner" href=""><b id="b">x</b></a></span></a>';
        const inner = document.getElementById('inner');
        // The parser does not nest <a>; build the nesting by hand.
        const outer = document.getElementById('outer');
        if (!outer.contains(inner)) outer.appendChild(inner);
        expect(click(document.getElementById('b'))?.id).toBe('inner');
        expect(linkUrl(inner)).toBe(new URL('', document.baseURI).href);
    });

    it('strips only C0 controls and spaces around the href', () => {
        document.body.innerHTML = '<a id="w" href=" /styleguide/page/about&#160;\n">x</a>';
        expect(linkUrl(document.getElementById('w'))).toBe(new URL('/styleguide/page/about%C2%A0', document.baseURI).href);
    });

    it('walks the ancestors when the event has no composedPath', () => {
        document.body.innerHTML = '<a id="a" href="/styleguide/page/about"><span id="s">About</span></a>';
        expect(anchorOf({ target: document.getElementById('s') })?.id).toBe('a');
    });

    it('gives null for a link without an href and for no link', () => {
        document.body.innerHTML = '<a id="n">No href</a><p id="p">Text</p>';
        expect(click(document.getElementById('n'))).toBeNull();
        expect(click(document.getElementById('p'))).toBeNull();
    });
});
