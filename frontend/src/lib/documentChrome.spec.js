import { describe, it, expect, beforeEach } from 'vitest';
import { applyDocumentChrome, GENERIC_FAVICON } from './documentChrome.js';

function makeConfig(overrides = {}) {
    return {
        locale: 'cs', projectName: 'Acme', favicon: '/f.svg', title: 'Styleguide — Acme', baseUrl: '/styleguide', ...overrides,
    };
}

beforeEach(() => {
    document.head.innerHTML = '<link rel="icon" id="sg-favicon-tag" href="">';
    document.title = 'placeholder';
    delete document.documentElement.dataset.defaultLocale;
});

describe('applyDocumentChrome — favicon link', () => {
    it('sets the #sg-favicon-tag href from config.favicon', () => {
        applyDocumentChrome(makeConfig({ favicon: '/images/favicon.svg' }));
        expect(document.getElementById('sg-favicon-tag').getAttribute('href')).toBe('/images/favicon.svg');
    });

    it('falls back to the generic glyph when favicon is empty', () => {
        applyDocumentChrome(makeConfig({ favicon: '' }));
        expect(document.getElementById('sg-favicon-tag').getAttribute('href')).toBe(GENERIC_FAVICON);
    });

    it('falls back to the generic glyph when favicon is missing or non-string', () => {
        const config = makeConfig();
        delete config.favicon;
        applyDocumentChrome(config);
        expect(document.getElementById('sg-favicon-tag').getAttribute('href')).toBe(GENERIC_FAVICON);

        applyDocumentChrome(makeConfig({ favicon: null }));
        expect(document.getElementById('sg-favicon-tag').getAttribute('href')).toBe(GENERIC_FAVICON);
    });

    it('does not throw when the link element is absent', () => {
        document.head.innerHTML = '';
        expect(() => applyDocumentChrome(makeConfig())).not.toThrow();
    });
});

describe('applyDocumentChrome — default locale', () => {
    it('stamps config.locale onto <html data-default-locale>', () => {
        applyDocumentChrome(makeConfig({ locale: 'en' }));
        expect(document.documentElement.dataset.defaultLocale).toBe('en');
    });
});

describe('applyDocumentChrome — title seed', () => {
    it('sets document.title from config.title', () => {
        applyDocumentChrome(makeConfig({ title: 'Styleguide — Acme' }));
        expect(document.title).toBe('Styleguide — Acme');
    });

    it('leaves document.title untouched when config has no title', () => {
        applyDocumentChrome(makeConfig({ title: undefined }));
        expect(document.title).toBe('placeholder');
    });

    it('leaves document.title untouched for an empty or non-string title', () => {
        applyDocumentChrome(makeConfig({ title: '' }));
        expect(document.title).toBe('placeholder');

        applyDocumentChrome(makeConfig({ title: null }));
        expect(document.title).toBe('placeholder');
    });
});
