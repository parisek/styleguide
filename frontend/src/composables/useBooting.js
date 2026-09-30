import { ref, computed } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

// The longest the boot loader may cover the interface. A request that never
// answers must not leave the visitor with a loader for ever: after this the
// interface shows as it is (raw keys and all, as before the loader existed).
export const BOOT_TIMEOUT_MS = 10000;

const gaveUp = ref(false);

// True until the interface strings and the catalogue are in (or the wait
// gave up). App.vue covers the interface with BootSplash while it holds;
// the command palette waits for it to end before it takes focus.
export function useBooting() {
    const i18n = useI18nStore();
    const catalog = useCatalogStore();
    return computed(() => !gaveUp.value && (!i18n.ready || catalog.loading));
}

// Starts the give-up timer; returns the function that cancels it.
export function startBootTimeout(ms = BOOT_TIMEOUT_MS) {
    gaveUp.value = false;
    const timer = setTimeout(() => { gaveUp.value = true; }, ms);
    return () => clearTimeout(timer);
}
