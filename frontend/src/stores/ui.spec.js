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
