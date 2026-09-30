import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useRenderErrorsStore } from '../stores/renderErrors.js';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import CompareStrip from './CompareStrip.vue';
import { CHROME_VIEWPORT_HEIGHT_PX } from '../lib/previewHeight.js';

// jsdom lays nothing out, so every clientWidth reads 0. Stub it for tests
// that check the fit-to-column zoom (same approach as VariantGrid.spec.js).
let restore = null;
function stubClientWidth(px) {
    const original = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, value: px });
    restore = () => {
        if (original) Object.defineProperty(HTMLElement.prototype, 'clientWidth', original);
        else delete HTMLElement.prototype.clientWidth;
    };
}

beforeEach(() => {
    setActivePinia(createPinia());
});

afterEach(() => {
    restore?.();
    restore = null;
});

function mountStrip(props = {}) {
    return mount(CompareStrip, {
        props: { src: '/styleguide/render/component/multi', widths: [1440, 768, 320], ...props },
        attachTo: document.body,
    });
}

describe('CompareStrip', () => {
    it('renders one column per width, in order, each captioned with its width', () => {
        const wrapper = mountStrip();
        const captions = wrapper.findAll('[data-testid="compare-caption"]').map((c) => c.text());
        expect(captions).toEqual(['1440 px', '768 px', '320 px']);
        wrapper.unmount();
    });

    it('sizes the columns in proportion to their widths', () => {
        const wrapper = mountStrip();
        expect(wrapper.get('[data-testid="compare-strip"]').attributes('style'))
            .toContain('grid-template-columns: minmax(0, 1440fr) minmax(0, 768fr) minmax(0, 320fr)');
        wrapper.unmount();
    });

    it('renders the same src in every column, lazily, at the column width', () => {
        const wrapper = mountStrip();
        const frames = wrapper.findAll('iframe');
        expect(frames).toHaveLength(3);
        for (const frame of frames) {
            expect(frame.attributes('src')).toBe('/styleguide/render/component/multi');
            expect(frame.attributes('loading')).toBe('lazy');
        }
        expect(frames.map((f) => f.attributes('style').match(/width: (\d+)px/)[1])).toEqual(['1440', '768', '320']);
        expect(frames.map((f) => f.attributes('title'))).toEqual(['1440 px', '768 px', '320 px']);
        wrapper.unmount();
    });

    it('scales each iframe down to its column and shows the zoom in the caption', async () => {
        stubClientWidth(360);
        const wrapper = mountStrip();
        await nextTick();
        const frame = wrapper.findAll('iframe')[0];
        expect(frame.attributes('style')).toContain('scale(0.25)');
        expect(wrapper.findAll('[data-testid="compare-caption"]')[0].text()).toBe('1440 px · 25 %');
        // 320 fits a 360px column: no scaling up, no zoom readout.
        expect(wrapper.findAll('iframe')[2].attributes('style')).toContain('scale(1)');
        expect(wrapper.findAll('[data-testid="compare-caption"]')[2].text()).toBe('320 px');
        wrapper.unmount();
    });

    it('gives a render: chrome entry a fixed viewport height instead of measuring it', () => {
        const wrapper = mountStrip({ scrolls: true });
        for (const frame of wrapper.findAll('iframe')) {
            expect(frame.attributes('style')).toContain(`height: ${CHROME_VIEWPORT_HEIGHT_PX}px`);
        }
        wrapper.unmount();
    });

    it('emits load when the first column has loaded', async () => {
        const wrapper = mountStrip();
        await wrapper.findAll('iframe')[1].trigger('load');
        expect(wrapper.emitted('load')).toHaveLength(1);
        await wrapper.findAll('iframe')[0].trigger('load');
        expect(wrapper.emitted('load')).toHaveLength(1);
        wrapper.unmount();
    });
});

describe('CompareStrip — a changed set of widths', () => {
    // A same-origin document of a given height, as the load handler reads it.
    function loadWithHeight(frame, height) {
        const doc = { documentElement: { scrollHeight: height }, body: { scrollHeight: height } };
        Object.defineProperty(frame.element, 'contentDocument', { configurable: true, value: doc });
        return frame.trigger('load');
    }
    const heightOf = (wrapper, width) => wrapper.get(`iframe[title="${width} px"]`).attributes('style').match(/height: (\d+)px/)[1];

    it('captions the measured content height once it is known', async () => {
        const wrapper = mountStrip({ widths: [320, 768] });
        const captions = () => wrapper.findAll('[data-testid="compare-caption"]').map((c) => c.text());
        // Before the load there is no height to tell, only the floor.
        expect(captions()).toEqual(['320 px', '768 px']);
        await loadWithHeight(wrapper.get('iframe[title="320 px"]'), 1500);
        expect(captions()).toEqual(['320 × 1500', '768 px']);
        wrapper.unmount();
    });

    it('captions a render: chrome entry with its pinned viewport height', () => {
        const wrapper = mountStrip({ widths: [320, 768], scrolls: true });
        expect(wrapper.findAll('[data-testid="compare-caption"]').map((c) => c.text()))
            .toEqual([`320 × ${CHROME_VIEWPORT_HEIGHT_PX}`, `768 × ${CHROME_VIEWPORT_HEIGHT_PX}`]);
        wrapper.unmount();
    });

    it('keeps each measured height with its width when a width is added in between', async () => {
        const wrapper = mountStrip({ widths: [320, 768] });
        await loadWithHeight(wrapper.get('iframe[title="320 px"]'), 1500);
        await loadWithHeight(wrapper.get('iframe[title="768 px"]'), 900);

        // The existing iframes stay (keyed by width) and never load again,
        // so a height filed under a position would land on the wrong column.
        await wrapper.setProps({ widths: [320, 375, 768] });
        expect(heightOf(wrapper, 320)).toBe('1500');
        expect(heightOf(wrapper, 768)).toBe('900');
        wrapper.unmount();
    });

    it('lets an unticked width go: its own height observer disconnects, the rest stay measured', async () => {
        // Record every observer and what it watches, so the assertion can
        // name the one that belonged to the removed width.
        const observers = [];
        class Recording {
            constructor() { this.targets = []; this.disconnected = false; observers.push(this); }
            observe(target) { this.targets.push(target); }
            unobserve() {}
            disconnect() { this.disconnected = true; }
        }
        vi.stubGlobal('ResizeObserver', Recording);
        const wrapper = mountStrip({ widths: [320, 768, 1440] });
        const docs = {};
        for (const [width, height] of [[320, 1500], [768, 900], [1440, 600]]) {
            const frame = wrapper.get(`iframe[title="${width} px"]`);
            await loadWithHeight(frame, height);
            docs[width] = frame.element.contentDocument.documentElement;
        }
        const watching = (width) => observers.find((o) => o.targets.includes(docs[width]));

        await wrapper.setProps({ widths: [320, 1440] });
        expect(watching(768).disconnected).toBe(true);
        expect(watching(320).disconnected).toBe(false);
        expect(watching(1440).disconnected).toBe(false);
        expect(heightOf(wrapper, 320)).toBe('1500');
        expect(heightOf(wrapper, 1440)).toBe('600');
        wrapper.unmount();
        vi.unstubAllGlobals();
    });

    it('stamps each iframe with its place, and marks a column that reported errors', async () => {
        const wrapper = mountStrip({ widths: [320, 768], tile: 'secondary', label: 'Secondary' });
        const narrow = wrapper.get('iframe[title="320 px"]');
        expect(narrow.attributes()).toMatchObject({ 'data-sg-tile': 'secondary', 'data-sg-label': 'Secondary', 'data-sg-width': '320' });
        expect(wrapper.find('[data-testid="error-mark"]').exists()).toBe(false);

        useRenderErrorsStore().entries.push({ id: 1, frame: narrow.element, tile: 'secondary', label: 'Secondary', width: 320, kind: 'error', message: 'x', source: '', line: 0 });
        await nextTick();
        const marks = wrapper.findAll('[data-testid="error-mark"]');
        expect(marks).toHaveLength(1);
        expect(marks[0].text()).toBe('1');
        wrapper.unmount();
    });
});
