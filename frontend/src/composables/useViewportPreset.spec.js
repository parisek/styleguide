import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { ref } from 'vue';
import { useViewportPreset } from './useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
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
