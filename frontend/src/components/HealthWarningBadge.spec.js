import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import HealthWarningBadge from './HealthWarningBadge.vue';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
    setActivePinia(createPinia());
    // jsdom ships no <dialog> methods — stub the modal API so open/close
    // assertions can run against the `open` attribute like a real browser.
    HTMLDialogElement.prototype.showModal = vi.fn(function showModal() {
        this.setAttribute('open', '');
    });
    HTMLDialogElement.prototype.close = vi.fn(function close() {
        this.removeAttribute('open');
    });
});

function mountWithWarnings(warnings) {
    const catalog = useCatalogStore();
    catalog.warnings = warnings;
    // attachTo: document — the native dialog backdrop-click check compares
    // event targets, which needs real DOM event plumbing.
    return mount(HealthWarningBadge, { attachTo: document.body });
}

describe('HealthWarningBadge', () => {
    it('renders nothing when there are no parser warnings', () => {
        const wrapper = mount(HealthWarningBadge);
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('dialog').exists()).toBe(false);
    });

    it('renders a badge with the warning count when the catalogue has warnings', () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
            { file: 'component/other/other.twig', error: 'kaboom' },
        ]);
        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.text()).toBe('2');
    });

    it('opens the native dialog listing every warning on badge click (#89)', async () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
            { file: 'page/_partials/footer.twig', error: 'Unexpected characters near "..."' },
        ]);
        await wrapper.find('button').trigger('click');

        const dialog = wrapper.find('dialog');
        expect(dialog.element.showModal).toHaveBeenCalled();
        const items = wrapper.findAll('dialog li');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('component/broken/broken.twig');
        expect(items[0].text()).toContain('boom');
        expect(items[1].text()).toContain('page/_partials/footer.twig');
    });

    it('closes via the dialog close button', async () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
        ]);
        await wrapper.find('button').trigger('click');
        await wrapper.find('dialog button').trigger('click');
        expect(wrapper.find('dialog').element.close).toHaveBeenCalled();
    });

    it('stays mounted when warnings refresh to empty while open, unmounts after close', async () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
        ]);
        await wrapper.find('button').trigger('click');

        // catalog.init() re-run (toolbar reload) clears the warnings while
        // the modal is showing — the dialog must survive, not vanish
        // mid-interaction with ::backdrop/focus state stranded.
        useCatalogStore().warnings = [];
        await wrapper.vm.$nextTick();
        const dialog = wrapper.find('dialog');
        expect(dialog.exists()).toBe(true);
        expect(wrapper.find('button[title]').exists()).toBe(false);

        // The native `close` event (Esc / close() call) flips isOpen and the
        // now-warning-less dialog unmounts.
        dialog.element.close();
        await dialog.trigger('close');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('dialog').exists()).toBe(false);
    });

    it('names the dialog for assistive tech via aria-labelledby', async () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
        ]);
        await wrapper.find('button').trigger('click');
        const dialog = wrapper.find('dialog');
        const labelId = dialog.attributes('aria-labelledby');
        expect(labelId).toBeTruthy();
        expect(wrapper.find(`#${labelId}`).text()).not.toBe('');
    });

    it('closes on a backdrop click (target is the dialog element itself) but not on content clicks', async () => {
        const wrapper = mountWithWarnings([
            { file: 'component/broken/broken.twig', error: 'boom' },
        ]);
        await wrapper.find('button').trigger('click');
        const dialog = wrapper.find('dialog');

        // Click on inner content — must NOT close.
        await wrapper.find('dialog li').trigger('click');
        expect(dialog.element.close).not.toHaveBeenCalled();

        // Click landing on the <dialog> element itself = the ::backdrop area.
        await dialog.trigger('click');
        expect(dialog.element.close).toHaveBeenCalled();
    });
});
