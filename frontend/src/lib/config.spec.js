import { describe, it, expect, beforeEach } from 'vitest';
import { readSpaConfig, seedTitle } from './config.js';

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('readSpaConfig', () => {
    it('parses the JSON payload out of the #sg-config script element', () => {
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.type = 'application/json';
        el.textContent = JSON.stringify({ locale: 'cs', projectName: 'Acme', favicon: '/f.svg', title: 'Styleguide — Acme', baseUrl: '/styleguide' });
        document.body.appendChild(el);

        expect(readSpaConfig()).toEqual({
            locale: 'cs', projectName: 'Acme', favicon: '/f.svg', title: 'Styleguide — Acme', baseUrl: '/styleguide',
        });
    });

    it('throws when the element is missing', () => {
        expect(() => readSpaConfig()).toThrow(/missing #sg-config/);
    });

    it('throws when the element contains invalid JSON', () => {
        // type must be a non-executable MIME type (matches the real
        // #sg-config element) — a <script> with no/JS type gets parsed AND
        // executed by jsdom on attach, which would throw its own uncaught
        // SyntaxError for this deliberately-malformed body and fail the run
        // independently of the assertion below.
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.type = 'application/json';
        el.textContent = '{not valid json';
        document.body.appendChild(el);
        expect(() => readSpaConfig()).toThrow();
    });

    it('accepts a custom element id', () => {
        const el = document.createElement('script');
        el.id = 'custom-config';
        el.type = 'application/json';
        el.textContent = '{"locale":"en"}';
        document.body.appendChild(el);
        expect(readSpaConfig('custom-config')).toEqual({ locale: 'en' });
    });
});

describe('seedTitle', () => {
    it('sets document.title from config.title', () => {
        document.title = 'placeholder';
        seedTitle({ title: 'Styleguide — Acme' });
        expect(document.title).toBe('Styleguide — Acme');
    });

    it('leaves document.title untouched when config has no title', () => {
        document.title = 'placeholder';
        seedTitle({});
        expect(document.title).toBe('placeholder');
    });

    it('leaves document.title untouched for a non-string title', () => {
        document.title = 'placeholder';
        seedTitle({ title: null });
        expect(document.title).toBe('placeholder');
    });
});
