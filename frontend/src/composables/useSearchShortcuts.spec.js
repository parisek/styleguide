import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { defineComponent, h, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { useSearchShortcuts } from './useSearchShortcuts.js';
import { useUiStore } from '../stores/ui.js';

const Host = defineComponent({
    setup() {
        const inputRef = ref(null);
        useSearchShortcuts(inputRef);
        return () => h('input', { ref: inputRef });
    },
});

let wrapper;

beforeEach(() => {
    setActivePinia(createPinia());
    wrapper = mount(Host, { attachTo: document.body });
});

afterEach(() => {
    wrapper.unmount();
});

describe('useSearchShortcuts', () => {
    it('focuses the input on Cmd+K', () => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
        expect(document.activeElement).toBe(wrapper.find('input').element);
    });

    it('focuses the input on Ctrl+K', () => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
        expect(document.activeElement).toBe(wrapper.find('input').element);
    });

    it('clears the search query and blurs on Escape', () => {
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        wrapper.find('input').element.focus();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(ui.searchQuery).toBe('');
        expect(document.activeElement).not.toBe(wrapper.find('input').element);
    });

    it('removes the window listener on unmount', () => {
        wrapper.unmount();
        // No assertion beyond "does not throw" — jsdom has no direct API to
        // introspect registered listener count; this guards against a
        // double-focus crash if a second Host mounts after this one unmounts.
        const w2 = mount(Host, { attachTo: document.body });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
        expect(document.activeElement).toBe(w2.find('input').element);
        w2.unmount();
    });
});
