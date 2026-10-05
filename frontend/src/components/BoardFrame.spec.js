import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BoardFrame from './BoardFrame.vue';
import { createLoadQueue } from '../lib/loadQueue.js';

class FakeIntersectionObserver {
    static instances = [];

    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        FakeIntersectionObserver.instances.push(this);
    }

    observe(el) { this.targets.push(el); }

    disconnect() {}

    fire(isIntersecting) {
        this.callback(this.targets.map((target) => ({ target, isIntersecting })));
    }
}

function mountFrame({ queue = createLoadQueue(6), id = 'home', item = {} } = {}) {
    return mount(BoardFrame, {
        props: {
            entry: { key: `page:${id}`, id, name: id, ...item },
            src: `/styleguide/render/page/${id}`,
            href: `/styleguide/page/${id}`,
            x: 100, y: 200, width: 1440, height: 900,
            queue,
        },
        attachTo: document.body,
    });
}

beforeEach(() => {
    FakeIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('BoardFrame', () => {
    it('sits at its spot with its size and loads nothing until it is near', () => {
        const wrapper = mountFrame();
        const style = wrapper.get('[data-testid="board-frame"]').attributes('style');
        expect(style).toContain('left: 100px');
        expect(style).toContain('top: 200px');
        expect(style).toContain('width: 1440px');
        expect(wrapper.find('iframe').exists()).toBe(false);
    });

    it('sets the iframe src when it comes near and the queue has a slot', async () => {
        const wrapper = mountFrame();
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('iframe').attributes('src')).toBe('/styleguide/render/page/home');
        expect(wrapper.get('iframe').classes()).toContain('pointer-events-none');
    });

    it('waits in the queue when all slots are taken, and drops out when it leaves', async () => {
        const queue = createLoadQueue(1);
        queue.request('other', () => {});
        const wrapper = mountFrame({ queue });
        const observer = FakeIntersectionObserver.instances[0];
        observer.fire(true);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('iframe').exists()).toBe(false);
        expect(queue.pendingCount).toBe(1);
        observer.fire(false);
        expect(queue.pendingCount).toBe(0);
    });

    it('reports a click as a pick, and takes no pointer until it is active', async () => {
        const wrapper = mountFrame();
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        expect(wrapper.get('iframe').classes()).toContain('pointer-events-none');
        await wrapper.get('[data-testid="board-frame"]').trigger('click');
        expect(wrapper.emitted('pick')[0]).toEqual(['page:home']);
        await wrapper.setProps({ active: true });
        expect(wrapper.get('iframe').classes()).not.toContain('pointer-events-none');
        await wrapper.get('[data-testid="board-frame"]').trigger('click');
        expect(wrapper.emitted('pick')).toHaveLength(1);
    });

    it('marks a selected frame in indigo and an active one in red, at the width it is given', async () => {
        const wrapper = mountFrame();
        const shadow = () => wrapper.get('[data-testid="board-frame"]').element.style.boxShadow;
        expect(shadow()).toContain('zinc-400');
        await wrapper.setProps({ selected: true, ringWidth: 12 });
        expect(shadow()).toContain('12px');
        expect(shadow()).toContain('indigo-500');
        await wrapper.setProps({ active: true });
        expect(shadow()).toContain('red-600');
    });

    it('forwards Ctrl+wheel and Esc from the page to the board', async () => {
        const wrapper = mountFrame();
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        const doc = document.implementation.createHTMLDocument('page');
        Object.defineProperty(wrapper.get('iframe').element, 'contentDocument', { value: doc });
        await wrapper.get('iframe').trigger('load');
        const wheel = new WheelEvent('wheel', { deltaY: -30, ctrlKey: true, clientX: 10, clientY: 20, cancelable: true });
        doc.dispatchEvent(wheel);
        expect(wheel.defaultPrevented).toBe(true);
        expect(wrapper.emitted('zoomwheel')[0][0].deltaY).toBe(-30);
        const plain = new WheelEvent('wheel', { deltaY: 30, cancelable: true });
        doc.dispatchEvent(plain);
        expect(plain.defaultPrevented).toBe(false);
        expect(wrapper.emitted('zoomwheel')).toHaveLength(1);
        doc.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('escape')).toHaveLength(1);
    });

    it('reports the height of the loaded page, capped', async () => {
        const wrapper = mountFrame();
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        const iframe = wrapper.get('iframe').element;
        Object.defineProperty(iframe, 'contentDocument', {
            value: { documentElement: { scrollHeight: 99999 }, body: { scrollHeight: 10 } },
        });
        await wrapper.get('iframe').trigger('load');
        expect(wrapper.emitted('measure')[0]).toEqual(['page:home', 20000]);
    });

    it('keeps the pinned demo height for a chrome entry', async () => {
        const wrapper = mountFrame({ item: { item: { render: 'chrome' } } });
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        Object.defineProperty(wrapper.get('iframe').element, 'contentDocument', {
            value: { documentElement: { scrollHeight: 1280 }, body: { scrollHeight: 1280 } },
        });
        await wrapper.get('iframe').trigger('load');
        expect(wrapper.emitted('measure')[0]).toEqual(['page:home', 640]);
    });
});
