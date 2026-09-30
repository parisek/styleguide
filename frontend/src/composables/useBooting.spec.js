import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useBooting, startBootTimeout } from './useBooting.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
});

afterEach(() => vi.useRealTimers());

describe('useBooting', () => {
    it('holds until BOTH the strings and the catalogue are in', () => {
        const i18n = useI18nStore();
        const catalog = useCatalogStore();
        const cancel = startBootTimeout();
        const booting = useBooting();
        expect(booting.value).toBe(true);
        i18n.ready = true;
        expect(booting.value).toBe(true);
        catalog.loading = false;
        expect(booting.value).toBe(false);
        cancel();
    });

    it('holds while the catalogue is in but the strings are not', () => {
        const catalog = useCatalogStore();
        const cancel = startBootTimeout();
        const booting = useBooting();
        catalog.loading = false;
        expect(booting.value).toBe(true);
        cancel();
    });

    it('gives up after the timeout, so a request that never answers cannot cover the interface for ever', () => {
        startBootTimeout(1000);
        const booting = useBooting();
        expect(booting.value).toBe(true);
        vi.advanceTimersByTime(1000);
        expect(booting.value).toBe(false);
    });

    it('does not give up when the timer was cancelled', () => {
        const cancel = startBootTimeout(1000);
        const booting = useBooting();
        cancel();
        vi.advanceTimersByTime(5000);
        expect(booting.value).toBe(true);
    });
});
