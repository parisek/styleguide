import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useThemeStore } from './theme.js';

beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        addListener: vi.fn(),
    }));
});

describe('useThemeStore', () => {
    it('defaults to system mode', () => {
        const theme = useThemeStore();
        expect(theme.mode).toBe('system');
    });

    it('resolves to light when mode is system and OS is light', () => {
        const theme = useThemeStore();
        theme.init();
        expect(theme.resolved).toBe('light');
    });

    it('resolves to the explicit mode when not system', () => {
        const theme = useThemeStore();
        theme.mode = 'dark';
        expect(theme.resolved).toBe('dark');
    });

    it('cycles light -> dark -> system -> light', () => {
        const theme = useThemeStore();
        theme.mode = 'light';
        theme.cycle();
        expect(theme.mode).toBe('dark');
        theme.cycle();
        expect(theme.mode).toBe('system');
        theme.cycle();
        expect(theme.mode).toBe('light');
    });

    it('persists mode to the sg-theme localStorage key as JSON', async () => {
        const theme = useThemeStore();
        theme.mode = 'dark';
        await Promise.resolve();
        expect(localStorage.getItem('sg-theme')).toBe('"dark"');
    });
});
