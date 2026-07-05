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
