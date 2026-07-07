// Global jsdom polyfill for `window.matchMedia`, which jsdom does not
// implement (https://github.com/jsdom/jsdom/issues/3522). Several
// stores/components query it at init/boot time (theme's system-preference
// detection, ui's responsive breakpoint check) even in tests that aren't
// exercising that specific branch — without this, every such spec throws
// "matchMedia is not a function" regardless of what it's actually testing.
// Specs that need to assert on the *result* of a matchMedia query still
// override it locally via `vi.stubGlobal('matchMedia', ...)` in their own
// `beforeEach` (see stores/theme.spec.js) — this default only fills the gap
// for everyone else.
if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (query) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        dispatchEvent: () => false,
    });
}
