import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePersistedRef } from './persistedRef.js';

beforeEach(() => {
    localStorage.clear();
});

describe('usePersistedRef', () => {
    it('reads the default value when nothing is stored', () => {
        const state = usePersistedRef('sg-test-a', 42);
        expect(state.value).toBe(42);
    });

    it('reads back a previously JSON-encoded value (matches @alpinejs/persist convention)', () => {
        localStorage.setItem('sg-test-b', JSON.stringify('dark'));
        const state = usePersistedRef('sg-test-b', 'system');
        expect(state.value).toBe('dark');
    });

    it('writes through to localStorage as JSON on change', async () => {
        const state = usePersistedRef('sg-test-c', false);
        state.value = true;
        await Promise.resolve();
        expect(localStorage.getItem('sg-test-c')).toBe('true');
    });

    it('deep-persists a plain-object value (e.g. sg-groups shape)', async () => {
        const state = usePersistedRef('sg-test-d', {});
        state.value['basic/Widget'] = false;
        await Promise.resolve();
        expect(JSON.parse(localStorage.getItem('sg-test-d'))).toEqual({ 'basic/Widget': false });
    });

    it('falls back to the default when localStorage throws (Safari private mode)', () => {
        const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });
        const state = usePersistedRef('sg-test-e', 'fallback');
        expect(state.value).toBe('fallback');
        spy.mockRestore();
    });
});
