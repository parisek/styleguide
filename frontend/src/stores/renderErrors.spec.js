import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useRenderErrorsStore } from './renderErrors.js';

let teardown = null;

function frame({ tile = 'default', label = 'Default', width = '' } = {}) {
    const el = document.createElement('iframe');
    el.dataset.sgTile = tile;
    el.dataset.sgLabel = label;
    el.dataset.sgWidth = String(width);
    document.body.appendChild(el);
    return el;
}

function send(el, payload, origin = window.location.origin) {
    window.dispatchEvent(new MessageEvent('message', { data: { sgRender: payload }, origin, source: el.contentWindow }));
}

const error = (message, extra = {}) => ({ type: 'error', kind: 'error', message, source: '/js/app.js', line: 12, ...extra });
// Pruning waits for the next animation frame.
const tick = () => new Promise((resolve) => setTimeout(resolve, 50));

beforeEach(() => {
    document.body.innerHTML = '';
    setActivePinia(createPinia());
    teardown = useRenderErrorsStore().listen();
});

afterEach(() => teardown?.());

describe('renderErrors', () => {
    it('records an error with the place stamped on its iframe', () => {
        const el = frame({ tile: 'secondary', label: 'Secondary', width: 320 });
        send(el, error('boom'));
        const store = useRenderErrorsStore();
        expect(store.count).toBe(1);
        expect(store.entries[0]).toMatchObject({ tile: 'secondary', label: 'Secondary', width: 320, message: 'boom', line: 12 });
        expect(store.countFor('secondary')).toBe(1);
        expect(store.countFor('secondary', 320)).toBe(1);
        expect(store.countFor('secondary', 768)).toBe(0);
    });

    it('ignores other origins, unknown frames, unknown kinds and foreign messages', () => {
        const el = frame();
        send(el, error('x'), 'https://evil.example');
        send(el, { type: 'error', kind: 'nonsense', message: 'x' });
        window.dispatchEvent(new MessageEvent('message', { data: { hello: 1 }, origin: window.location.origin, source: el.contentWindow }));
        window.dispatchEvent(new MessageEvent('message', { data: { sgRender: error('x') }, origin: window.location.origin, source: null }));
        expect(useRenderErrorsStore().count).toBe(0);
    });

    it('drops a frame\'s errors when a new document starts in it', () => {
        const el = frame();
        send(el, error('old'));
        send(el, { type: 'start' });
        expect(useRenderErrorsStore().count).toBe(0);
    });

    it('drops a frame\'s errors when it loads a document without the relay', () => {
        const el = frame();
        send(el, error('old'));
        el.dispatchEvent(new Event('load'));
        expect(useRenderErrorsStore().count).toBe(0);
    });

    it('keeps them when the loaded document has the relay (its "start" already ran)', () => {
        const el = frame();
        const relay = el.contentDocument.createElement('script');
        relay.src = '/styleguide/assets/render-relay.js?v=1';
        el.contentDocument.head.appendChild(relay);
        send(el, error('new'));
        el.dispatchEvent(new Event('load'));
        expect(useRenderErrorsStore().count).toBe(1);
    });

    it('drops a frame\'s errors when the frame leaves the page', async () => {
        const kept = frame({ tile: 'a' });
        const gone = frame({ tile: 'b' });
        send(kept, error('stays'));
        send(gone, error('goes'));
        gone.remove();
        await tick();
        expect(useRenderErrorsStore().entries.map((e) => e.message)).toEqual(['stays']);
    });

    it('groups identical errors and lists their places', () => {
        send(frame({ tile: 'a', label: 'Default', width: 320 }), error('same'));
        send(frame({ tile: 'a', label: 'Default', width: 768 }), error('same'));
        send(frame({ tile: 'b', label: 'Secondary' }), error('other'));
        const groups = useRenderErrorsStore().groups;
        expect(groups).toHaveLength(2);
        expect(groups[0]).toMatchObject({ message: 'same', count: 2, places: ['Default · 320 px', 'Default · 768 px'] });
    });

    it('marks a frame that hit the relay cap, until it leaves', async () => {
        const el = frame();
        send(el, { type: 'more' });
        expect(useRenderErrorsStore().hasTruncated).toBe(true);
        el.remove();
        await tick();
        expect(useRenderErrorsStore().hasTruncated).toBe(false);
    });

    it('stops listening after teardown', () => {
        teardown();
        teardown = null;
        send(frame(), error('late'));
        expect(useRenderErrorsStore().count).toBe(0);
    });
});
