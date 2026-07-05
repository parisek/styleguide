import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { ref } from 'vue';
import { useViewportPreset } from './useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
    // iframeTheme (ui.js) round-trips through localStorage via usePersistedRef
    // — clear it so a 'dark' write in one test doesn't leak into the next
    // test's fresh Pinia instance (a new store instance still reads the same
    // localStorage key at construction time).
    localStorage.clear();
    setActivePinia(createPinia());
});

describe('useViewportPreset', () => {
    it('iframeSrc builds a render URL for a component route', () => {
        const type = ref('component');
        const slug = ref('hero');
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero');
    });

    it('iframeSrc is null for overview (no route slug, not foundations)', () => {
        const type = ref('overview');
        const slug = ref(null);
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBeNull();
    });

    it('iframeSrc uses the fixed foundations/index path regardless of slug', () => {
        const type = ref('foundations');
        const slug = ref(null);
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/foundations/index');
    });

    it('iframeSrc appends ?theme=dark when the iframe theme toggle is dark', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        ui.setIframeTheme('dark');
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?theme=dark');
    });

    it('iframeSrc omits the theme param for the light default (historical URL shape)', () => {
        const type = ref('component');
        const slug = ref('hero');
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero');
    });

    it('iframeSrc combines the reload nonce and dark theme with the correct separators', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        const catalog = useCatalogStore();
        catalog.init = () => {};
        ui.setIframeTheme('dark');
        const vp = useViewportPreset({ type, slug });
        vp.reloadPreview();
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?_r=1&theme=dark');
    });

    it('reloadPreview appends an incrementing _r nonce to iframeSrc', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.init = () => {};
        const vp = useViewportPreset({ type, slug });
        vp.reloadPreview();
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?_r=1');
        vp.reloadPreview();
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?_r=2');
    });

    it('setPreset applies both width and height from the VIEWPORTS table', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        const vp = useViewportPreset({ type, slug });
        vp.setPreset('tablet');
        expect(ui.previewWidth).toBe('768px');
        expect(ui.previewHeight).toBe(1024);
        expect(vp.activePreset.value).toBe('tablet');
        expect(vp.activePresetCategory.value).toBe('tablet');
    });

    it('toolbarVisible is false when the current item is responsive:false', () => {
        const type = ref('doc');
        const slug = ref('sample-doc');
        const catalog = useCatalogStore();
        catalog.docs = [{ id: 'sample-doc', name: 'Sample doc', responsive: false }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.toolbarVisible.value).toBe(false);
        expect(vp.effective.value).toEqual({ width: null, height: null });
    });

    // Regression for Phase 4 Task 3 review finding 1: variantSwitcherVisible
    // must stay true for a responsive:false entry that has variants — only
    // toolbarVisible (the width controls) should react to `responsive`.
    it('variantSwitcherVisible stays true for a responsive:false entry that has variants', () => {
        const type = ref('doc');
        const slug = ref('sample-doc');
        const catalog = useCatalogStore();
        catalog.docs = [{
            id: 'sample-doc', name: 'Sample doc', responsive: false,
            variants: [{ id: 'secondary', label: 'Secondary' }],
        }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.toolbarVisible.value).toBe(false);
        expect(vp.variantSwitcherVisible.value).toBe(true);
    });

    it('variantSwitcherVisible is false when the current item has no variants', () => {
        const type = ref('component');
        const slug = ref('hero');
        const vp = useViewportPreset({ type, slug });
        expect(vp.variantSwitcherVisible.value).toBe(false);
    });

    it('variantSwitcherVisible is false for foundations even though iframeSrc is set', () => {
        const type = ref('foundations');
        const slug = ref(null);
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBeTruthy();
        expect(vp.variantSwitcherVisible.value).toBe(false);
    });

    it('dimensionsLabel reports the scaled percentage when zoom < 1', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        const vp = useViewportPreset({ type, slug });
        vp.setPreset('desktop-2k');
        vp.observeContainer(null); // no container measured -> width/height stay 0
        // Simulate a measured 1280x800 container via the ResizeObserver callback path:
        vp.observeContainer({ clientWidth: 1328, clientHeight: 848, addEventListener: () => {} });
        expect(vp.zoom.value).toBeCloseTo(0.5, 2);
        expect(vp.dimensionsLabel.value).toBe('2560 × 1440 (50 %)');
    });

    it('applyCustomWidth rejects an out-of-range value and reverts the input', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        ui.setWidth('375px');
        const vp = useViewportPreset({ type, slug });
        vp.customWidthInput.value = 5000;
        vp.applyCustomWidth();
        expect(ui.previewWidth).toBe('375px');
        expect(vp.customWidthInput.value).toBe(375);
    });

    it('currentSectionKey resolves via catalog.sectionOf for a component route', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero', category: 'Block' }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.currentSectionKey.value).toBe('blocks');
    });

    it('currentSectionKey is "pages" for a page route regardless of category', () => {
        const type = ref('page');
        const slug = ref('homepage');
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage' }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.currentSectionKey.value).toBe('pages');
    });

    it('iframeSrc appends ?variant= when a variant ref is supplied and set', () => {
        const type = ref('component');
        const slug = ref('multi');
        const variant = ref('secondary');
        const vp = useViewportPreset({ type, slug, variant });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/multi?variant=secondary');
    });

    it('iframeSrc omits ?variant= when the ref is null (default, matches pre-Task-3 URL shape)', () => {
        const type = ref('component');
        const slug = ref('multi');
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/multi');
    });

    it('iframeSrc composes ?variant= with the dark iframe theme param', () => {
        const type = ref('component');
        const slug = ref('multi');
        const ui = useUiStore();
        const variant = ref('secondary');
        ui.setIframeTheme('dark');
        const vp = useViewportPreset({ type, slug, variant });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/multi?theme=dark&variant=secondary');
    });

    it('passes the variant/setVariant refs through unchanged for injected components to consume', () => {
        const type = ref('component');
        const slug = ref('multi');
        const variant = ref('secondary');
        const setVariant = (id) => { variant.value = id; };
        const vp = useViewportPreset({ type, slug, variant, setVariant });
        vp.setVariant('dark-bg');
        expect(variant.value).toBe('dark-bg');
        expect(vp.variant.value).toBe('dark-bg');
    });

    it('fieldsTree/fieldsCount reflect the current item\'s YAML fields map', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero', fields: { title: { type: 'text' } } }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.fieldsCount.value).toBe(1);
        expect(vp.fieldsTree.value[0].key).toBe('title');
    });
});
