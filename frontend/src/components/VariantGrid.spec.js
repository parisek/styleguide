import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import VariantGrid from './VariantGrid.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';

function mountGrid(type = 'component', slug = 'multi', { items, variant, setVariant } = {}) {
    // Every mount gets a genuinely fresh ui store -- localStorage.clear()
    // first, otherwise a PREVIOUS test's ui.setWidth() (previewWidth is
    // persisted via usePersistedRef, real localStorage, not reset by
    // setActivePinia(createPinia()) alone) leaks into this test's "default"
    // preset expectations.
    localStorage.clear();
    setActivePinia(createPinia());
    useI18nStore().strings = { toolbar: { variant_default: 'Default', variant_isolate_prefix: 'Isolate' } };
    useCatalogStore().items = items ?? [{
        id: 'multi',
        name: 'Multi',
        variants: [
            { id: 'dark-bg', label: 'dark-bg', description: '' },
            { id: 'secondary', label: 'Secondary style', description: 'Tuned for a secondary-toned surface.' },
        ],
    }];

    let capturedViewport;
    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            const viewport = useViewportPreset({ type: typeRef, slug: slugRef, variant, setVariant });
            capturedViewport = viewport;
            provide('viewport', viewport);
            return () => h(VariantGrid);
        },
    });
    const wrapper = mount(Host, { attachTo: document.body });
    // Exposed for tests that assert on the composable's own state (e.g.
    // gridZoom reporting) alongside the rendered DOM -- an additive
    // property, so every pre-existing `wrapper.find(...)`/`wrapper.vm`
    // call site above is unaffected.
    wrapper.viewport = capturedViewport;
    return wrapper;
}

// Stubs `clientWidth` (jsdom never computes real layout, so every element's
// clientWidth reads 0) to a fixed value for the lifetime of a test --
// VariantGrid's per-tile ResizeObserver registration reads it synchronously
// off the content-area wrapper at mount time, so this must be installed
// BEFORE calling mountGrid(). Mirrors the codebase's existing pattern of
// swapping in a tracking/stub global for the duration of one test (see
// PreviewPane.spec.js's TrackingResizeObserver swap).
function stubClientWidth(px) {
    const original = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, value: px });
    return () => {
        if (original) Object.defineProperty(HTMLElement.prototype, 'clientWidth', original);
        else delete HTMLElement.prototype.clientWidth;
    };
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

    it('applies the (small, non-over-tall) pre-measure fallback height before any iframe has loaded', () => {
        const wrapper = mountGrid();
        const heights = wrapper.findAll('[data-testid="variant-tile"] iframe').map((f) => f.attributes('style'));
        expect(heights.every((style) => style.includes('height: 96px'))).toBe(true);
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
        expect(iframe.attributes('style')).toContain('height: 96px');
    });
});

// Device presets in the variant grid (styleguide 2.0): the shared toolbar
// viewport preset (same one that drives the classic single preview) applies
// uniformly to every tile, scaled down per tile to fit that tile's own
// measured cell width -- see lib/tileGeometry.js for the underlying math
// (unit-tested there directly; these specs cover the Vue-level wiring).
describe('VariantGrid — device presets', () => {
    it('renders the Full preset (default) as fluid tiles -- no scaling, no per-tile scale readout', () => {
        const wrapper = mountGrid();
        const iframe = wrapper.findAll('[data-testid="variant-tile"] iframe')[0];
        expect(iframe.classes()).toContain('w-full');
        expect(iframe.attributes('style')).not.toContain('transform');
        expect(wrapper.find('[data-testid="variant-tile-scale"]').exists()).toBe(false);
    });

    // The per-tile "375 × 667 · 53 %" readout is gone (styleguide 2.0 UX
    // fix) -- every tile shares the identical preset and, since cell
    // widths are uniform, the identical zoom, so it was pure repeated
    // noise. VariantGrid.vue now reports just the representative (first)
    // tile's zoom up to the shared viewport composable instead, for
    // ViewportToolbar.vue's single trigger label to show once (see
    // ViewportToolbar.spec.js).
    it('scales a fixed-width/fixed-height preset down to fit each tile\'s measured cell width, with no per-tile readout -- reports the shared zoom to the viewport composable instead', async () => {
        const restore = stubClientWidth(200);
        try {
            const wrapper = mountGrid();
            const ui = useUiStore();
            ui.setWidth('375px', 667);
            await wrapper.vm.$nextTick();

            const tile = wrapper.findAll('[data-testid="variant-tile"]')[0];
            const iframe = tile.find('iframe');
            const expectedZoom = 200 / 375;
            expect(iframe.attributes('style')).toContain('width: 375px');
            expect(iframe.attributes('style')).toContain('height: 667px');
            expect(iframe.attributes('style')).toContain(`transform: scale(${expectedZoom})`);

            expect(wrapper.find('[data-testid="variant-tile-scale"]').exists()).toBe(false);
            expect(wrapper.viewport.gridZoom.value).toBeCloseTo(expectedZoom, 10);
        } finally {
            restore();
        }
    });

    it('never upscales a tile beyond the preset\'s logical size when the cell is wider than the preset, and reports a zoom of 1', async () => {
        const restore = stubClientWidth(2000);
        try {
            const wrapper = mountGrid();
            const ui = useUiStore();
            ui.setWidth('375px', 667);
            await wrapper.vm.$nextTick();

            const iframe = wrapper.findAll('[data-testid="variant-tile"] iframe')[0];
            expect(iframe.attributes('style')).toContain('transform: scale(1)');
            expect(wrapper.viewport.gridZoom.value).toBe(1);
        } finally {
            restore();
        }
    });

    it('resets the shared grid zoom to null on unmount so the classic single preview is never left with a stale value', async () => {
        const restore = stubClientWidth(200);
        try {
            const wrapper = mountGrid();
            const ui = useUiStore();
            ui.setWidth('375px', 667);
            await wrapper.vm.$nextTick();
            expect(wrapper.viewport.gridZoom.value).not.toBeNull();

            wrapper.unmount();
            expect(wrapper.viewport.gridZoom.value).toBeNull();
        } finally {
            restore();
        }
    });
});

describe('VariantGrid — density control (ui.variantColumns)', () => {
    it('defaults to Auto -- CSS grid, auto-fit columns, always the 420px fluid basis at the Full preset', () => {
        const wrapper = mountGrid();
        const container = wrapper.find('[data-testid="variant-grid-tiles"]');
        expect(container.classes()).toContain('grid');
        expect(container.attributes('style')).toContain('grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr))');
    });

    it('Auto derives a larger basis from a device preset (preset width + tile chrome padding)', async () => {
        const wrapper = mountGrid();
        const ui = useUiStore();
        ui.setWidth('1280px', 800);
        await wrapper.vm.$nextTick();
        const container = wrapper.find('[data-testid="variant-grid-tiles"]');
        expect(container.attributes('style')).toContain('minmax(min(1312px, 100%), 1fr)');
    });

    it('stays a CSS grid (never flex-col) at every density -- 1-4 use an exact repeat(N, ...) column count', async () => {
        const wrapper = mountGrid();
        const ui = useUiStore();
        for (const n of [1, 2, 3, 4]) {
            ui.setVariantColumns(n);
            // eslint-disable-next-line no-await-in-loop -- each iteration needs its
            // own re-render before asserting the freshly computed style string.
            await wrapper.vm.$nextTick();
            const container = wrapper.find('[data-testid="variant-grid-tiles"]');
            expect(container.classes()).toContain('grid');
            expect(container.classes()).not.toContain('flex-col');
            expect(container.attributes('style')).toContain(`grid-template-columns: repeat(${n}, minmax(0, 1fr))`);
        }
    });

    it('every tile keeps the subgrid row-span-2 mechanics regardless of density, including N=1 (the old flex-col "rows" branch is gone)', async () => {
        const wrapper = mountGrid();
        const ui = useUiStore();
        ui.setVariantColumns(1);
        await wrapper.vm.$nextTick();
        const tile = wrapper.findAll('[data-testid="variant-tile"]')[0];
        expect(tile.classes()).toContain('grid');
        expect(tile.classes()).toContain('row-span-2');
        expect(tile.classes()).not.toContain('flex-col');
    });
});

describe('VariantGrid — click-to-isolate', () => {
    it('does not make the Default tile header clickable/keyboard-focusable', () => {
        const wrapper = mountGrid();
        const header = wrapper.findAll('[data-testid="variant-tile-header"]')[0];
        expect(header.attributes('role')).toBeUndefined();
        expect(header.attributes('tabindex')).toBeUndefined();
        expect(header.classes()).not.toContain('cursor-pointer');
    });

    it('makes a variant tile header clickable and calls viewport.setVariant with its id', async () => {
        let capturedId = 'not-called';
        const wrapper = mountGrid('component', 'multi', { setVariant: (id) => { capturedId = id; } });
        const header = wrapper.findAll('[data-testid="variant-tile-header"]')[1]; // dark-bg
        expect(header.attributes('role')).toBe('button');
        expect(header.attributes('tabindex')).toBe('0');
        await header.trigger('click');
        expect(capturedId).toBe('dark-bg');
    });

    it('isolates a variant tile header via the Enter key', async () => {
        let capturedId = 'not-called';
        const wrapper = mountGrid('component', 'multi', { setVariant: (id) => { capturedId = id; } });
        const header = wrapper.findAll('[data-testid="variant-tile-header"]')[2]; // secondary
        await header.trigger('keydown.enter');
        expect(capturedId).toBe('secondary');
    });
});
