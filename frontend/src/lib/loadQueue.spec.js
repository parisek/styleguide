import { describe, it, expect, vi } from 'vitest';
import { createLoadQueue, DEFAULT_LOAD_LIMIT } from './loadQueue.js';

describe('createLoadQueue', () => {
    it('defaults to six concurrent loads', () => {
        expect(DEFAULT_LOAD_LIMIT).toBe(6);
        const queue = createLoadQueue();
        const starts = Array.from({ length: 8 }, () => vi.fn());
        starts.forEach((start, i) => queue.request(`t${i}`, start));
        expect(starts.filter((s) => s.mock.calls.length === 1)).toHaveLength(6);
        expect(queue.activeCount).toBe(6);
        expect(queue.pendingCount).toBe(2);
    });

    it('starts the next request, first come first served, when a slot frees', () => {
        const queue = createLoadQueue(2);
        const a = vi.fn(); const b = vi.fn(); const c = vi.fn(); const d = vi.fn();
        queue.request('a', a);
        queue.request('b', b);
        queue.request('c', c);
        queue.request('d', d);
        expect(c).not.toHaveBeenCalled();

        queue.release('a');
        expect(c).toHaveBeenCalledTimes(1);
        expect(d).not.toHaveBeenCalled();
        expect(queue.isActive('c')).toBe(true);
        expect(queue.isActive('a')).toBe(false);
    });

    it('drops a queued request on release, without starting it or freeing a slot', () => {
        const queue = createLoadQueue(1);
        const a = vi.fn(); const b = vi.fn(); const c = vi.fn();
        queue.request('a', a);
        queue.request('b', b);
        queue.request('c', c);

        queue.release('b');
        expect(queue.pendingCount).toBe(1);
        expect(queue.activeCount).toBe(1);

        queue.release('a');
        expect(b).not.toHaveBeenCalled();
        expect(c).toHaveBeenCalledTimes(1);
    });

    it('ignores a repeated request for the same key', () => {
        const queue = createLoadQueue(1);
        const a = vi.fn();
        queue.request('a', a);
        queue.request('a', a);
        queue.request('b', vi.fn());
        queue.request('b', vi.fn());
        expect(a).toHaveBeenCalledTimes(1);
        expect(queue.pendingCount).toBe(1);
    });

    it('treats release of an unknown key as a no-op', () => {
        const queue = createLoadQueue(1);
        expect(() => queue.release('nope')).not.toThrow();
        expect(queue.activeCount).toBe(0);
    });
});
