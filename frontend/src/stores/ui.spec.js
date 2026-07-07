import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useUiStore } from './ui.js';

beforeEach(() => {
    localStorage.clear();
    window.history.replaceState(null, '', '/styleguide/');
    setActivePinia(createPinia());
});

describe('useUiStore', () => {
    it('defaults previewWidth to 100% (Full)', () => {
        expect(useUiStore().previewWidth).toBe('100%');
    });

    it('setWidth sets width+height and clears previewRotated when height is null', () => {
        const ui = useUiStore();
        ui.previewRotated = true;
        ui.setWidth('375px');
        expect(ui.previewWidth).toBe('375px');
        expect(ui.previewHeight).toBeNull();
        expect(ui.previewRotated).toBe(false);
    });

    it('setWidth with an explicit height keeps previewRotated (preset application path)', () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        expect(ui.previewHeight).toBe(667);
    });

    it('toggleRotation is a no-op with no canonical height', () => {
        const ui = useUiStore();
        ui.setWidth('500px');
        ui.toggleRotation();
        expect(ui.previewRotated).toBe(false);
    });

    it('toggleRotation flips previewRotated when a canonical height exists', () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        ui.toggleRotation();
        expect(ui.previewRotated).toBe(true);
    });

    it('isPortrait reflects the effective (post-rotation) dimensions', () => {
        const ui = useUiStore();
        ui.setWidth('1280px', 800);
        expect(ui.isPortrait).toBe(false);
        ui.toggleRotation();
        expect(ui.isPortrait).toBe(true);
    });

    it('setPortrait(true) computes the correct rotated flag for a landscape-canonical preset', () => {
        const ui = useUiStore();
        ui.setWidth('1280px', 800);
        ui.setPortrait(true);
        expect(ui.previewRotated).toBe(true);
    });

    it('toggleSidebar flips sidebarOpen', () => {
        const ui = useUiStore();
        const before = ui.sidebarOpen;
        ui.toggleSidebar();
        expect(ui.sidebarOpen).toBe(!before);
    });

    it('setRoute flips isPreviewLoading synchronously for iframe-bearing route types only', () => {
        const ui = useUiStore();
        ui.setRoute('component', 'hero');
        expect(ui.isPreviewLoading).toBe(true);
        ui.isPreviewLoading = false;
        ui.setRoute('overview', null);
        expect(ui.isPreviewLoading).toBe(false);
    });

    // Review finding baked in: on-demand a11y check state is ephemeral and
    // must not survive a navigation — a stale violation list (or a
    // never-cleared "running" flag from an abandoned check) would describe
    // a document the iframe no longer shows. Mirrors the existing
    // isPreviewLoading reset test above.
    it('setRoute clears a11yResults and a11yRunning on every navigation', () => {
        const ui = useUiStore();
        ui.a11yResults = { byImpact: { critical: [], serious: [], moderate: [], minor: [] }, total: 0 };
        ui.a11yRunning = true;
        ui.setRoute('component', 'hero');
        expect(ui.a11yResults).toBeNull();
        expect(ui.a11yRunning).toBe(false);
    });

    it('setRoute clears a11y state even for a non-iframe-bearing route (overview)', () => {
        const ui = useUiStore();
        ui.a11yResults = { byImpact: { critical: [], serious: [], moderate: [], minor: [] }, total: 1 };
        ui.setRoute('overview', null);
        expect(ui.a11yResults).toBeNull();
    });

    it('initFromUrl applies a valid ?width= URL param exactly once at boot', () => {
        window.history.replaceState(null, '', '/styleguide/?width=768');
        const ui = useUiStore();
        ui.initFromUrl();
        expect(ui.previewWidth).toBe('768px');
        expect(ui.previewHeight).toBeNull();
    });

    it('persists previewWidth/Height/Rotated/sidebarOpen under their legacy keys as JSON', async () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        ui.toggleRotation();
        ui.toggleSidebar();
        await Promise.resolve();
        expect(localStorage.getItem('sg-preview-width')).toBe('"375px"');
        expect(localStorage.getItem('sg-preview-height')).toBe('667');
        expect(localStorage.getItem('sg-preview-rotated')).toBe('true');
        expect(JSON.parse(localStorage.getItem('sg-sidebar-open'))).toBe(false);
    });
});

// Iframe content theme — independent of the SPA chrome's own light/dark/
// system toggle (stores/theme.js). Persisted under its own localStorage key
// (sg-iframe-theme) so switching one doesn't affect the other.
describe('iframeTheme', () => {
    beforeEach(() => {
        localStorage.clear();
        // Explicit (not relying on the outer describe's replaceState leaking
        // across tests within the file) — document.cookie in jsdom is scoped
        // to the current path, and the cookie setIframeTheme() writes is
        // Path=/styleguide.
        window.history.replaceState(null, '', '/styleguide/');
        setActivePinia(createPinia());
    });

    it('defaults to light', () => {
        const ui = useUiStore();
        expect(ui.iframeTheme).toBe('light');
    });

    it('persists the chosen theme across store instances', async () => {
        useUiStore().setIframeTheme('dark');
        // usePersistedRef's localStorage write is flushed by a `watch()`
        // callback, which runs on the next microtask — matches the existing
        // "persists previewWidth/..." test's `await Promise.resolve()` above.
        await Promise.resolve();
        setActivePinia(createPinia());
        expect(useUiStore().iframeTheme).toBe('dark');
    });

    it('rejects invalid values by falling back to light', () => {
        const ui = useUiStore();
        ui.setIframeTheme('neon');
        expect(ui.iframeTheme).toBe('light');
    });

    // The cookie is the only channel Router::synthesizeEmbeddedRoute() (PHP)
    // has to recover this preference on an in-iframe native navigation —
    // localStorage never leaves the browser. See ui.js `setIframeTheme()`.
    it('mirrors the choice into the sg-iframe-theme cookie', () => {
        document.cookie = 'sg-iframe-theme=; path=/styleguide; max-age=0';
        const ui = useUiStore();
        ui.setIframeTheme('dark');
        expect(document.cookie).toContain('sg-iframe-theme=dark');
    });

    it('writes the whitelisted (not raw) value to the cookie for invalid input', () => {
        document.cookie = 'sg-iframe-theme=; path=/styleguide; max-age=0';
        const ui = useUiStore();
        ui.setIframeTheme('neon');
        expect(document.cookie).toContain('sg-iframe-theme=light');
    });
});

// Variant grid tile density (styleguide 2.0: Auto | 1 | 2 | 3 | 4, replacing
// the earlier rows/grid toggle). Its own localStorage key, independent of
// previewWidth/etc. -- it's a VariantGrid-only concern.
describe('variantColumns', () => {
    beforeEach(() => {
        localStorage.clear();
        setActivePinia(createPinia());
    });

    it('defaults to "auto"', () => {
        expect(useUiStore().variantColumns).toBe('auto');
    });

    it('setVariantColumns persists the chosen density across store instances', async () => {
        useUiStore().setVariantColumns(2);
        await Promise.resolve();
        setActivePinia(createPinia());
        expect(useUiStore().variantColumns).toBe(2);
    });

    it('rejects invalid values (including out-of-range numbers and numeric strings) by falling back to "auto"', () => {
        const ui = useUiStore();
        ui.setVariantColumns('columns');
        expect(ui.variantColumns).toBe('auto');
        ui.setVariantColumns(5);
        expect(ui.variantColumns).toBe('auto');
        ui.setVariantColumns('2');
        expect(ui.variantColumns).toBe('auto');
    });
});

// Migration of the pre-2.0 rows/grid toggle (`sg-variant-layout`) to the new
// density control (`sg-variant-columns`) -- see migrateVariantColumnsKey()
// in ui.js. Every test here sets up the legacy key BEFORE the store (and
// therefore usePersistedRef/the migration) is ever instantiated, mirroring
// what a real upgrade looks like: an old localStorage value already sitting
// there when the new build's JS first runs.
describe('variantColumns migration from the legacy sg-variant-layout key', () => {
    beforeEach(() => {
        localStorage.clear();
        setActivePinia(createPinia());
    });

    it('migrates a legacy "rows" value to the exact replacement, 1', () => {
        localStorage.clear();
        localStorage.setItem('sg-variant-layout', JSON.stringify('rows'));
        setActivePinia(createPinia());
        expect(useUiStore().variantColumns).toBe(1);
    });

    it('migrates a legacy "grid" value to "auto", the closest new equivalent', () => {
        localStorage.clear();
        localStorage.setItem('sg-variant-layout', JSON.stringify('grid'));
        setActivePinia(createPinia());
        expect(useUiStore().variantColumns).toBe('auto');
    });

    it('removes the legacy key once migrated, so it cannot re-migrate on a later reload', () => {
        localStorage.clear();
        localStorage.setItem('sg-variant-layout', JSON.stringify('rows'));
        setActivePinia(createPinia());
        useUiStore();
        expect(localStorage.getItem('sg-variant-layout')).toBeNull();
    });

    it('leaves an already-set sg-variant-columns value alone even if the legacy key is still present', () => {
        localStorage.clear();
        localStorage.setItem('sg-variant-columns', JSON.stringify(3));
        localStorage.setItem('sg-variant-layout', JSON.stringify('rows'));
        setActivePinia(createPinia());
        expect(useUiStore().variantColumns).toBe(3);
    });

    it('defaults to "auto" with no legacy key present (fresh install, not an upgrade)', () => {
        localStorage.clear();
        setActivePinia(createPinia());
        expect(useUiStore().variantColumns).toBe('auto');
    });
});
