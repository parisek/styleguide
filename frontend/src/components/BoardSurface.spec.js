import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setActivePinia, createPinia } from 'pinia';
import BoardSurface from './BoardSurface.vue';
import { createLoadQueue } from '../lib/loadQueue.js';
import { useI18nStore } from '../stores/i18n.js';

class FakeIntersectionObserver {
    observe() {}

    disconnect() {}
}

const entries = [
    { key: 'page:home', type: 'page', id: 'home', name: 'Home', item: {} },
    { key: 'page:about', type: 'page', id: 'about', name: 'About', item: {} },
    { key: 'component:hero', type: 'component', id: 'hero', name: 'Hero', item: {} },
];

// jsdom 30 ships read-only MouseEvent fields, which breaks the `trigger()`
// assignment of clientX/button. Build the event with an init dict instead.
async function pointer(wrapper, type, init = {}) {
    const { pointerId, ...mouse } = init;
    const event = new MouseEvent(type, { bubbles: true, cancelable: true, ...mouse });
    if (pointerId !== undefined) Object.defineProperty(event, 'pointerId', { value: pointerId });
    wrapper.element.dispatchEvent(event);
    // A microtask tick only, like trigger(): a macrotask would run the
    // zero-delay timer that ends the swallowed click after a drag.
    await nextTick();
}

function mountSurface(extra = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        board: { controls: 'Controls', zoom_in: 'In', zoom_out: 'Out', zoom_reset: 'Reset', fit: 'Fit all', active_hint: 'Interactive', label: 'Board' },
    };
    return mount(BoardSurface, {
        props: {
            groups: [
                { key: 'pages', label: 'Pages', level: 1, items: entries.slice(0, 2) },
                { key: 'basic', label: 'Basic', level: 1, items: entries.slice(2) },
            ],
            previewWidth: 1440,
            queue: createLoadQueue(),
            srcFor: (e) => `/styleguide/render/${e.type}/${e.id}`,
            hrefFor: (e) => `/styleguide/${e.type}/${e.id}`,
            ...extra,
        },
        attachTo: document.body,
    });
}

beforeEach(() => vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver));
afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('BoardSurface', () => {
    it('shows one frame per entry, in the given order, with its name', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        expect(wrapper.findAll('[data-testid="board-frame"]')).toHaveLength(3);
        expect(wrapper.findAll('[data-testid="board-frame-link"]').map((a) => a.text())).toEqual(['Home', 'About', 'Hero']);
    });

    it('shows a group inside a section as a smaller heading', async () => {
        const wrapper = mountSurface({
            groups: [
                { key: 'pages', label: 'Pages', level: 1, items: [] },
                { key: 'category:home', label: 'Home', level: 2, items: entries.slice(0, 2) },
            ],
        });
        await flushPromises();
        expect(wrapper.findAll('[data-testid="board-group"]').map((h) => h.text())).toEqual(['Pages']);
        expect(wrapper.findAll('[data-testid="board-subgroup"]').map((h) => h.text())).toEqual(['Home2']);
    });

    it('has one heading per group', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        expect(wrapper.findAll('[data-testid="board-group"]').map((h) => h.text())).toEqual(['Pages2', 'Basic1']);
        expect(wrapper.findAll('[data-testid="board-rule"]')).toHaveLength(1);
    });

    it('zooms with the buttons and resets to 100 %', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const reset = wrapper.get('[data-testid="board-zoom-reset"]');
        await reset.trigger('click');
        expect(reset.text()).toBe('100 %');
        await wrapper.get('[data-testid="board-zoom-in"]').trigger('click');
        expect(reset.text()).toBe('125 %');
        await wrapper.get('[data-testid="board-zoom-out"]').trigger('click');
        await wrapper.get('[data-testid="board-zoom-out"]').trigger('click');
        expect(reset.text()).toBe('80 %');
    });

    it('zooms on Ctrl and wheel, and leaves a plain wheel to the scroll', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const scroller = wrapper.get('[data-testid="board-scroller"]').element;
        await wrapper.get('[data-testid="board-zoom-reset"]').trigger('click');
        const plain = new WheelEvent('wheel', { deltaY: -50, cancelable: true });
        scroller.dispatchEvent(plain);
        expect(plain.defaultPrevented).toBe(false);
        const pinch = new WheelEvent('wheel', { deltaY: -50, ctrlKey: true, cancelable: true });
        scroller.dispatchEvent(pinch);
        expect(pinch.defaultPrevented).toBe(true);
        await flushPromises();
        expect(wrapper.get('[data-testid="board-zoom-reset"]').text()).not.toBe('100 %');
    });

    it('selects a frame on a click, makes it interactive on the second, and steps back with Esc', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const frames = () => wrapper.findAll('[data-testid="board-frame"]');
        await frames()[1].trigger('click');
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="board-active-hint"]').exists()).toBe(false);
        await frames()[1].trigger('click');
        expect(wrapper.find('[data-testid="board-active-hint"]').exists()).toBe(true);
        const scroller = wrapper.get('[data-testid="board-scroller"]');
        await scroller.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[data-testid="board-active-hint"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(true);
        await scroller.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(false);
    });

    it('clears the selection on a click on the empty surface', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        await wrapper.findAll('[data-testid="board-frame"]')[0].trigger('click');
        await pointer(wrapper.get('[data-testid="board-surface"]'), 'pointerdown', { button: 0, clientX: 5, clientY: 5 });
        await pointer(wrapper.get('[data-testid="board-surface"]'), 'pointerup', { button: 0, clientX: 5, clientY: 5 });
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(false);
    });

    it('walks between frames with the arrows and activates the selection with Enter', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const scroller = wrapper.get('[data-testid="board-scroller"]');
        await scroller.trigger('keydown', { key: 'ArrowRight' });
        await scroller.trigger('keydown', { key: 'ArrowRight' });
        const selected = () => wrapper.findAll('[data-testid="board-frame"]').findIndex((f) => f.element.style.boxShadow.includes('indigo'));
        expect(selected()).toBe(1);
        await scroller.trigger('keydown', { key: 'ArrowDown' });
        expect(selected()).toBe(2);
        await scroller.trigger('keydown', { key: 'Enter' });
        expect(wrapper.find('[data-testid="board-active-hint"]').exists()).toBe(true);
    });

    it('opens the selected entry with Ctrl+Enter', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const scroller = wrapper.get('[data-testid="board-scroller"]');
        await scroller.trigger('keydown', { key: 'ArrowRight' });
        await scroller.trigger('keydown', { key: 'Enter', ctrlKey: true });
        expect(wrapper.emitted('open')[0][0].id).toBe('home');
    });

    it('zooms to the selection with F and from its button', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const el = wrapper.get('[data-testid="board-scroller"]').element;
        Object.defineProperty(el, 'clientWidth', { configurable: true, value: 1312 });
        Object.defineProperty(el, 'clientHeight', { configurable: true, value: 700 });
        expect(wrapper.find('[data-testid="board-zoom-selection"]').exists()).toBe(false);
        await wrapper.findAll('[data-testid="board-frame"]')[0].trigger('click');
        await wrapper.get('[data-testid="board-scroller"]').trigger('keydown', { key: 'f' });
        await flushPromises();
        expect(wrapper.get('[data-testid="board-zoom-reset"]').text()).toBe('86 %');
        await wrapper.get('[data-testid="board-zoom-reset"]').trigger('click');
        await wrapper.get('[data-testid="board-zoom-selection"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="board-zoom-reset"]').text()).toBe('86 %');
    });

    it('pans by dragging, and a drag does not select', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const scroller = wrapper.get('[data-testid="board-scroller"]');
        scroller.element.scrollLeft = 300;
        const frame = wrapper.findAll('[data-testid="board-frame"]')[0];
        await pointer(frame, 'pointerdown', { button: 0, clientX: 200, clientY: 200, pointerId: 1 });
        await pointer(scroller, 'pointermove', { clientX: 150, clientY: 190, pointerId: 1 });
        expect(scroller.element.scrollLeft).toBe(350);
        await pointer(scroller, 'pointerup', { button: 0, clientX: 150, clientY: 190 });
        await frame.trigger('click');
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(false);
    });

    it('keeps a small movement a click', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        const scroller = wrapper.get('[data-testid="board-scroller"]');
        const frame = wrapper.findAll('[data-testid="board-frame"]')[0];
        await pointer(frame, 'pointerdown', { button: 0, clientX: 200, clientY: 200, pointerId: 1 });
        await pointer(scroller, 'pointermove', { clientX: 201, clientY: 201, pointerId: 1 });
        await pointer(scroller, 'pointerup', { button: 0, clientX: 201, clientY: 201 });
        await frame.trigger('click');
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(true);
    });

    it('names the size of a frame once its page is measured', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        expect(wrapper.find('[data-testid="board-frame-dimensions"]').exists()).toBe(false);
        wrapper.findAllComponents({ name: 'BoardFrame' })[0].vm.$emit('measure', 'page:home', 2806);
        await flushPromises();
        expect(wrapper.get('[data-testid="board-frame-dimensions"]').text()).toBe('1440 × 2806');
    });

    it('reports the view once the reader has moved, and not before', async () => {
        vi.useFakeTimers();
        const wrapper = mountSurface();
        await flushPromises();
        vi.advanceTimersByTime(1000);
        expect(wrapper.emitted('viewchange')).toBeUndefined();
        await wrapper.findAll('[data-testid="board-frame"]')[1].trigger('click');
        vi.advanceTimersByTime(400);
        const [state] = wrapper.emitted('viewchange').at(-1);
        expect(state.sel).toBe('page:about');
        expect(state.zoom).toMatch(/^\d+$/);
        expect(state.at).toMatch(/^-?\d+,-?\d+$/);
        vi.useRealTimers();
    });

    it('opens as a link says: the zoom, and the selection', async () => {
        vi.useFakeTimers();
        const wrapper = mountSurface({ initialView: { zoom: 0.5, at: { x: 800, y: 600 }, sel: 'page:about' } });
        await flushPromises();
        vi.advanceTimersByTime(400);
        await flushPromises();
        expect(wrapper.get('[data-testid="board-zoom-reset"]').text()).toBe('50 %');
        expect(wrapper.find('[data-testid="board-selected-hint"]').exists()).toBe(true);
        vi.useRealTimers();
    });

    it('opens an entry from its name', async () => {
        const wrapper = mountSurface();
        await flushPromises();
        await wrapper.findAll('[data-testid="board-frame-link"]')[2].trigger('click');
        expect(wrapper.emitted('open')[0][0].id).toBe('hero');
    });
});
