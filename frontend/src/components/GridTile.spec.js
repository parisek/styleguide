import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import GridTile from './GridTile.vue';
import { createLoadQueue } from '../lib/loadQueue.js';
import { useI18nStore } from '../stores/i18n.js';

// jsdom has no IntersectionObserver. This stub records every instance so a
// test can say which tiles are "near the viewport".
class FakeIntersectionObserver {
    static instances = [];

    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        this.targets = [];
        this.disconnected = false;
        FakeIntersectionObserver.instances.push(this);
    }

    observe(el) { this.targets.push(el); }

    disconnect() { this.disconnected = true; }

    fire(isIntersecting) {
        this.callback(this.targets.map((target) => ({ target, isIntersecting })));
    }
}

const entry = (id, extra = {}) => ({
    key: `component:${id}`, type: 'component', id, name: id.toUpperCase(), section: 'blocks', variant: null, variantCount: 0, item: {}, ...extra,
});

function mountTile({ id = 'hero', queue = createLoadQueue(6), tileWidth = 320, src, extra } = {}) {
    return mount(GridTile, {
        props: {
            entry: entry(id, extra),
            src: src ?? `/styleguide/render/component/${id}`,
            href: `/styleguide/component/${id}`,
            tileWidth,
            queue,
        },
        attachTo: document.body,
    });
}

beforeEach(() => {
    FakeIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
    setActivePinia(createPinia());
    useI18nStore().strings = { grid: { variants: 'Variants' } };
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('GridTile', () => {
    it('renders no iframe until it is near the viewport', async () => {
        const wrapper = mountTile();
        expect(wrapper.find('iframe').exists()).toBe(false);
        expect(FakeIntersectionObserver.instances[0].options.rootMargin).toBe('400px');
        expect(FakeIntersectionObserver.instances[0].options.root).toBeNull();

        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/hero');
        expect(wrapper.find('iframe').attributes('loading')).toBeUndefined();
    });

    it('waits for a slot and loads when an earlier tile finishes', async () => {
        const queue = createLoadQueue(1);
        const first = mountTile({ id: 'one', queue });
        const second = mountTile({ id: 'two', queue });
        FakeIntersectionObserver.instances.forEach((io) => io.fire(true));
        await first.vm.$nextTick();

        expect(first.find('iframe').exists()).toBe(true);
        expect(second.find('iframe').exists()).toBe(false);
        expect(second.attributes('data-state')).toBe('queued');

        await first.find('iframe').trigger('load');
        expect(first.attributes('data-state')).toBe('loaded');
        expect(second.find('iframe').exists()).toBe(true);
    });

    it('drops its request when it scrolls away before its turn', async () => {
        const queue = createLoadQueue(1);
        mountTile({ id: 'one', queue });
        const second = mountTile({ id: 'two', queue });
        FakeIntersectionObserver.instances.forEach((io) => io.fire(true));
        FakeIntersectionObserver.instances[1].fire(false);
        await second.vm.$nextTick();

        expect(second.attributes('data-state')).toBe('idle');
        expect(queue.pendingCount).toBe(0);
    });

    it('frees its slot after a timeout when the render never fires load', async () => {
        vi.useFakeTimers();
        const queue = createLoadQueue(1);
        const wrapper = mountTile({ queue });
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        expect(queue.activeCount).toBe(1);

        vi.advanceTimersByTime(15000);
        expect(queue.activeCount).toBe(0);
    });

    it('frees its slot when it unmounts mid-load', async () => {
        const queue = createLoadQueue(1);
        const wrapper = mountTile({ queue });
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();

        wrapper.unmount();
        expect(queue.activeCount).toBe(0);
        expect(FakeIntersectionObserver.instances[0].disconnected).toBe(true);
    });

    it('reloads with the new URL when the source changes (theme, locale)', async () => {
        const wrapper = mountTile();
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        await wrapper.find('iframe').trigger('load');

        await wrapper.setProps({ src: '/styleguide/render/component/hero?theme=dark' });
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/hero?theme=dark');
    });

    it('scales a desktop-wide render down to the tile width', async () => {
        const wrapper = mountTile({ tileWidth: 320 });
        FakeIntersectionObserver.instances[0].fire(true);
        await wrapper.vm.$nextTick();
        const style = wrapper.find('iframe').attributes('style');
        expect(style).toContain('width: 1280px');
        expect(style).toContain('scale(0.25)');
        // 800 * 0.25
        expect(wrapper.find('[aria-hidden="true"]').attributes('style')).toContain('height: 200px');
    });

    it('opens the entry from a stretched link with a real href', async () => {
        const wrapper = mountTile();
        const link = wrapper.find('[data-testid="grid-tile-link"]');
        expect(link.attributes('href')).toBe('/styleguide/component/hero');
        expect(link.text()).toBe('HERO');
        await link.trigger('click');
        expect(wrapper.emitted('open')[0][0].id).toBe('hero');
    });

    it('shows the variants badge only for an entry with variants', () => {
        expect(mountTile({ id: 'plain' }).find('[data-testid="grid-tile-variants"]').exists()).toBe(false);
        const badge = mountTile({ id: 'multi', extra: { variantCount: 4 } }).find('[data-testid="grid-tile-variants"]');
        expect(badge.text()).toBe('4');
        expect(badge.attributes('aria-label')).toBe('Variants: 4');
    });
});
