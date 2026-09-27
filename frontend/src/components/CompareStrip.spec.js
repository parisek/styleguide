import { describe, it, expect, afterEach } from 'vitest';
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
