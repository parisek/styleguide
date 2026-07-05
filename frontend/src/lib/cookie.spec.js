import { describe, it, expect, beforeEach } from 'vitest';
import { setCookie } from './cookie.js';

// jsdom's document.cookie is a real per-origin jar (unlike most jsdom DOM
// APIs, which are stubs) — path/SameSite attributes round-trip through the
// same parser a real browser uses, so this is a faithful test of the
// string this module builds, not just a mock assertion.
describe('setCookie', () => {
    beforeEach(() => {
        // jsdom scopes document.cookie reads to the current document's path,
        // same as a real browser: a Path=/styleguide cookie is invisible from
        // "/" (jsdom's default test URL, see vitest.config.js). Every route
        // this package serves lives under /styleguide, so this matches real
        // usage, not a test-only workaround.
        window.history.replaceState(null, '', '/styleguide/');
        document.cookie = 'sg-iframe-theme=; path=/styleguide; max-age=0';
    });

    it('writes name=value readable back from document.cookie', () => {
        setCookie('sg-iframe-theme', 'dark');
        expect(document.cookie).toContain('sg-iframe-theme=dark');
    });

    it('URL-encodes the value', () => {
        setCookie('sg-test', 'a b');
        expect(document.cookie).toContain('sg-test=a%20b');
    });

    it('defaults to path=/styleguide and SameSite=Lax', () => {
        // jsdom's document.cookie getter doesn't expose attributes back, so
        // this asserts the exact string built rather than the round-trip.
        const calls = [];
        const original = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            set(v) { calls.push(v); },
            get() { return original.get.call(document); },
        });
        try {
            setCookie('sg-iframe-theme', 'dark');
        } finally {
            Object.defineProperty(document, 'cookie', original);
        }
        expect(calls[0]).toBe('sg-iframe-theme=dark; path=/styleguide; SameSite=Lax');
    });
});
