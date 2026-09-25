import { describe, it, expect, beforeEach } from 'vitest';
import { baseUrl, url, assetUrl, resetRuntimeConfig, DEFAULT_BASE_URL } from './runtimeConfig.js';

function inject(payload) {
    const el = document.createElement('script');
    el.id = 'sg-config';
    el.type = 'application/json';
    el.textContent = JSON.stringify(payload);
    document.body.appendChild(el);
}

beforeEach(() => {
    document.body.innerHTML = '';
    resetRuntimeConfig();
});

describe('runtimeConfig', () => {
    it('reads the mount from #sg-config', () => {
        inject({ baseUrl: '/tools/ui' });
        expect(baseUrl()).toBe('/tools/ui');
        expect(url('api/components')).toBe('/tools/ui/api/components');
        expect(url('/render/component/card')).toBe('/tools/ui/render/component/card');
        expect(url()).toBe('/tools/ui');
        expect(assetUrl('locales/en.json')).toBe('/tools/ui/assets/locales/en.json');
    });

    it('drops a trailing slash', () => {
        inject({ baseUrl: '/tools/ui/' });
        expect(baseUrl()).toBe('/tools/ui');
    });

    it('falls back to the default without a payload', () => {
        expect(baseUrl()).toBe(DEFAULT_BASE_URL);
        expect(url('api/health')).toBe('/styleguide/api/health');
    });

    it.each([[undefined], [''], ['relative'], ['//cdn.example'], ['/'], [42]])(
        'falls back to the default for %s',
        (value) => {
            inject({ baseUrl: value });
            expect(baseUrl()).toBe(DEFAULT_BASE_URL);
        },
    );

    it('reads the payload once', () => {
        inject({ baseUrl: '/one' });
        expect(baseUrl()).toBe('/one');
        document.body.innerHTML = '';
        inject({ baseUrl: '/two' });
        expect(baseUrl()).toBe('/one');
    });
});
