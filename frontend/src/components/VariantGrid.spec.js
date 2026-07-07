import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import VariantGrid from './VariantGrid.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountGrid(type = 'component', slug = 'multi', { items, variant } = {}) {
    setActivePinia(createPinia());
    useI18nStore().strings = { toolbar: { variant_default: 'Default' } };
    useCatalogStore().items = items ?? [{
        id: 'multi',
        name: 'Multi',
        variants: [
            { id: 'dark-bg', label: 'dark-bg', description: '' },
            { id: 'secondary', label: 'Secondary style', description: 'Tuned for a secondary-toned surface.' },
        ],
    }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant });
            provide('viewport', viewport);
            return () => h(VariantGrid);
        },
    });
    return mount(Host, { attachTo: document.body });
}

describe('VariantGrid', () => {
    it('renders one tile for the default fixture plus one per discovered variant, in order', () => {
        const wrapper = mountGrid();
        const tiles = wrapper.findAll('[data-testid="variant-tile"]');
        expect(tiles).toHaveLength(3);
        const labels = tiles.map((t) => t.find('[data-testid="variant-tile-label"]').text());
        expect(labels).toEqual(['Default', 'dark-bg', 'Secondary style']);
    });

    it('renders the description only for variants that have one', () => {
        const wrapper = mountGrid();
        const tiles = wrapper.findAll('[data-testid="variant-tile"]');
        expect(tiles[0].find('[data-testid="variant-tile-description"]').exists()).toBe(false); // Default
        expect(tiles[1].find('[data-testid="variant-tile-description"]').exists()).toBe(false); // dark-bg
        expect(tiles[2].find('[data-testid="variant-tile-description"]').text()).toBe('Tuned for a secondary-toned surface.');
    });

    it('gives each tile an iframe src isolating its own variant, default tile carrying no ?variant=', () => {
        const wrapper = mountGrid();
        const srcs = wrapper.findAll('[data-testid="variant-tile"] iframe').map((f) => f.attributes('src'));
        expect(srcs).toEqual([
            '/styleguide/render/component/multi',
            '/styleguide/render/component/multi?variant=dark-bg',
            '/styleguide/render/component/multi?variant=secondary',
        ]);
    });

    // VariantGrid itself always renders a "Default" tile for whatever
    // catalogue entry it's pointed at -- deciding whether to mount it at
    // all when an entry has no variants is PreviewPane.vue's job (see
    // useViewportPreset.js's `gridActive` and PreviewPane.spec.js's "grid
    // mode" tests), not this component's.
    it('still renders a single Default tile for a variant-less entry (caller decides whether to mount the grid at all)', () => {
        const wrapper = mountGrid('component', 'hero', { items: [{ id: 'hero', name: 'Hero' }] });
        const tiles = wrapper.findAll('[data-testid="variant-tile"]');
        expect(tiles).toHaveLength(1);
        expect(tiles[0].find('[data-testid="variant-tile-label"]').text()).toBe('Default');
    });

    it('applies the fallback tile height before any iframe has loaded', () => {
        const wrapper = mountGrid();
        const heights = wrapper.findAll('[data-testid="variant-tile"] iframe').map((f) => f.attributes('style'));
        expect(heights.every((style) => style.includes('height: 320px'))).toBe(true);
    });

    // jsdom never actually navigates a same-origin iframe to its `src` (no
    // real network stack), so `contentDocument` exists but
    // `contentDocument.documentElement` stays null -- onTileLoad()'s
    // `doc.documentElement?.scrollHeight` optional-chaining must tolerate
    // that instead of throwing, same as PreviewPane.vue's fitIframeToContent().
    it('does not throw when the load event fires against a not-yet-navigated iframe document', async () => {
        const wrapper = mountGrid();
        const iframe = wrapper.findAll('[data-testid="variant-tile"] iframe')[0];
        await expect(iframe.trigger('load')).resolves.not.toThrow();
        expect(iframe.attributes('style')).toContain('height: 320px');
    });
});
