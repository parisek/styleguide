import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import LocaleMenu from './LocaleMenu.vue';
import { useI18nStore } from '../stores/i18n.js';

beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    delete document.documentElement.dataset.locales;
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
});

afterEach(() => {
    delete document.documentElement.dataset.locales;
});

// LocaleMenu calls useContentLocale(), which watches `route.query.locale` —
// so it needs a real router installed, not a stub.
function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/', name: 'landing', component: { template: '<div/>' } }],
    });
}

// attachTo: document.body throughout — the outside-click and focus-return
// assertions compare real event targets and document.activeElement, neither
// of which behaves correctly on a detached wrapper.
function mountMenu(locales) {
    if (locales) document.documentElement.dataset.locales = JSON.stringify(locales);
    return mount(LocaleMenu, {
        attachTo: document.body,
        global: { plugins: [makeRouter()] },
    });
}

function trigger(wrapper) {
    return wrapper.get('[data-testid="locale-trigger"]');
}

// Each option shows the language name AND its raw catalogue code, so read
// the name span rather than the row's concatenated text.
function optionLabels(wrapper) {
    return wrapper.findAll('[data-testid="locale-name"]').map((o) => o.text());
}

function optionNamed(wrapper, name) {
    return wrapper.findAll('[role="option"]')
        .find((o) => o.get('[data-testid="locale-name"]').text() === name);
}

describe('LocaleMenu — when it appears at all', () => {
    it('renders nothing when the project discovers no catalogues', () => {
        const wrapper = mountMenu();
        expect(wrapper.find('[data-testid="locale-trigger"]').exists()).toBe(false);
    });

    it('renders nothing when the project offers a single locale', () => {
        // A picker with one option cannot pick anything — it is pure noise in
        // a footer that is already tight.
        const wrapper = mountMenu(['cs_CZ']);
        expect(wrapper.find('[data-testid="locale-trigger"]').exists()).toBe(false);
    });

    it('renders the trigger as soon as there are two locales to choose between', () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        expect(wrapper.find('[data-testid="locale-trigger"]').exists()).toBe(true);
    });
});

describe('LocaleMenu — the closed trigger', () => {
    it('shows the active locale as a short uppercase code', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        useI18nStore().locale = 'cs_CZ';
        await flushPromises();
        expect(trigger(wrapper).text()).toContain('CS');
    });

    it('keeps the region when two offered locales share a language', async () => {
        const wrapper = mountMenu(['pt_PT', 'pt_BR']);
        useI18nStore().locale = 'pt_BR';
        await flushPromises();
        expect(trigger(wrapper).text()).toContain('PT-BR');
    });

    it('reports its collapsed state to assistive tech', () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        expect(trigger(wrapper).attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});

describe('LocaleMenu — opening and listing', () => {
    it('lists every discovered locale by its own name, not just the chrome cs/en set', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT']);
        await trigger(wrapper).trigger('click');
        expect(optionLabels(wrapper)).toEqual([
            'Čeština', 'English', 'Slovenčina', 'Polski', 'Italiano',
        ]);
    });

    it('falls back to the raw code for a locale it has no name for', async () => {
        const wrapper = mountMenu(['cs_CZ', 'xx_YY']);
        await trigger(wrapper).trigger('click');
        expect(optionLabels(wrapper)).toContain('xx_YY');
    });

    it('marks the active locale as selected', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        useI18nStore().locale = 'en_US';
        await trigger(wrapper).trigger('click');
        const selected = wrapper.findAll('[role="option"]').filter((o) => o.attributes('aria-selected') === 'true');
        expect(selected).toHaveLength(1);
        expect(selected[0].get('[data-testid="locale-name"]').text()).toBe('English');
    });

    it('scrolls rather than growing without bound, so 15 languages still fit on screen', async () => {
        const many = ['cs_CZ', 'en_US', 'sk_SK', 'pl_PL', 'it_IT', 'de_DE', 'fr_FR', 'es_ES',
            'pt_PT', 'nl_NL', 'sv_SE', 'da_DK', 'fi_FI', 'hu_HU', 'ro_RO'];
        const wrapper = mountMenu(many);
        await trigger(wrapper).trigger('click');
        expect(optionLabels(wrapper)).toHaveLength(15);
        expect(wrapper.get('[role="listbox"]').classes().join(' ')).toContain('overflow-y-auto');
    });

    it('opens upward, because the switcher sits at the very bottom of the sidebar', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        await trigger(wrapper).trigger('click');
        // bottom-full anchors the panel's bottom edge to the trigger's top
        // edge — the one class that makes this a drop-UP.
        expect(wrapper.get('[role="listbox"]').classes()).toContain('bottom-full');
    });
});

describe('LocaleMenu — choosing a locale', () => {
    it('switches both the chrome strings and the content locale, then closes', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK']);
        await trigger(wrapper).trigger('click');

        const slovak = optionNamed(wrapper, 'Slovenčina');
        await slovak.trigger('click');
        await flushPromises();

        // sk_SK is outside the chrome's SUPPORTED set, so the UI strings fall
        // back to English while the content locale still becomes Slovak.
        expect(fetch).toHaveBeenCalledWith('/styleguide/assets/locales/en.json', { cache: 'no-cache' });
        expect(useI18nStore().locale).toBe('sk_SK');
        expect(localStorage.getItem('sg-locale')).toBe('sk_SK');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});

describe('LocaleMenu — dismissal', () => {
    it('closes on Escape and returns focus to the trigger', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        await trigger(wrapper).trigger('click');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);

        await wrapper.get('[role="listbox"]').trigger('keydown', { key: 'Escape' });
        await flushPromises();

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(document.activeElement).toBe(trigger(wrapper).element);
    });

    it('closes when the pointer goes down anywhere outside it', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        await trigger(wrapper).trigger('click');

        document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('stays open when the pointer goes down inside the panel', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        await trigger(wrapper).trigger('click');

        wrapper.get('[role="listbox"]').element
            .dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
    });

    it('stops listening for outside clicks once unmounted', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US']);
        await trigger(wrapper).trigger('click');
        const remove = vi.spyOn(document, 'removeEventListener');
        wrapper.unmount();
        expect(remove).toHaveBeenCalledWith('pointerdown', expect.any(Function), true);
    });
});

describe('LocaleMenu — keyboard', () => {
    it('moves the active option with the arrow keys and wraps at the ends', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK']);
        useI18nStore().locale = 'cs_CZ';
        await trigger(wrapper).trigger('click');
        const listbox = wrapper.get('[role="listbox"]');

        await listbox.trigger('keydown', { key: 'ArrowDown' });
        expect(listbox.attributes('aria-activedescendant')).toBe('sg-locale-opt-en_US');

        await listbox.trigger('keydown', { key: 'ArrowUp' });
        expect(listbox.attributes('aria-activedescendant')).toBe('sg-locale-opt-cs_CZ');

        // Wraps to the last entry rather than sticking at the top.
        await listbox.trigger('keydown', { key: 'ArrowUp' });
        expect(listbox.attributes('aria-activedescendant')).toBe('sg-locale-opt-sk_SK');
    });

    it('jumps to the ends with Home and End', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK']);
        await trigger(wrapper).trigger('click');
        const listbox = wrapper.get('[role="listbox"]');

        await listbox.trigger('keydown', { key: 'End' });
        expect(listbox.attributes('aria-activedescendant')).toBe('sg-locale-opt-sk_SK');

        await listbox.trigger('keydown', { key: 'Home' });
        expect(listbox.attributes('aria-activedescendant')).toBe('sg-locale-opt-cs_CZ');
    });

    it('picks the active option with Enter', async () => {
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK']);
        useI18nStore().locale = 'cs_CZ';
        await trigger(wrapper).trigger('click');
        const listbox = wrapper.get('[role="listbox"]');

        await listbox.trigger('keydown', { key: 'ArrowDown' });
        await listbox.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(useI18nStore().locale).toBe('en_US');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('opens with ArrowUp from the closed trigger, landing on the last option', async () => {
        // ArrowUp on a drop-UP should reveal the entry nearest the trigger,
        // which is the visually lowest one.
        const wrapper = mountMenu(['cs_CZ', 'en_US', 'sk_SK']);
        await trigger(wrapper).trigger('keydown', { key: 'ArrowUp' });
        expect(wrapper.get('[role="listbox"]').attributes('aria-activedescendant'))
            .toBe('sg-locale-opt-sk_SK');
    });
});
