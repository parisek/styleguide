import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        // jsdom throws SecurityError on localStorage access for the default
        // opaque "about:blank" origin — Task 3's usePersistedRef specs (and
        // every store spec that persists state) need a real origin so
        // localStorage/sessionStorage actually work under jsdom.
        environmentOptions: {
            jsdom: { url: 'http://localhost/' },
        },
        // Node >= 22 ships its own built-in `localStorage` global behind a
        // non-configurable accessor. That shadows jsdom's window.localStorage
        // when vitest copies window properties onto globalThis, so bare
        // `localStorage` reads as undefined even with the jsdom url above.
        // Disabling Node's own implementation lets jsdom's win.
        poolOptions: {
            threads: { execArgv: ['--no-experimental-webstorage'] },
            forks: { execArgv: ['--no-experimental-webstorage'] },
        },
        include: ['src/**/*.spec.js'],
        setupFiles: ['./src/test/setup.js'],
        globals: false,
    },
});
