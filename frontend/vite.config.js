import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // Relative, so dist/ carries no mount path. dist/index.html references
    // its entry assets as `./styleguide.[hash].(js|css)`; Styleguide::dispatchSpa()
    // rewrites those to `<mount>/assets/…` when it serves the shell, because a
    // relative URL in a document served at `/styleguide` (no trailing slash)
    // would resolve against `/`. Chunks and CSS `url()`s resolve against the
    // module or stylesheet that references them, which already sits under
    // `<mount>/assets/`, so they need nothing. The served shell is the same
    // bytes as with the old absolute base at the default mount.
    base: './',
    plugins: [vue(), tailwindcss()],
    publicDir: 'public',
    build: {
        outDir: '../dist',
        emptyOutDir: true,
        // Both HTML entries now emit a `<script type="module">` (index.html's
        // SPA bundle, and — as of #79 — foundations.html's vanilla behavior
        // script). With 2+ module-script entries, Vite's default
        // `polyfillModulePreload: true` extracts a shared
        // `assets/modulepreload-polyfill-[hash].js` chunk instead of inlining
        // it, landing outside distRoot's flat hashed-filename layout (nested
        // under dist/assets/, unaccounted for by any resolver glob) and
        // changing the SPA entry's own hash for no behavioral reason. Both
        // consuming environments (this package's own iframe, modern
        // browsers) already support `<link rel="modulepreload">` natively —
        // opt out rather than carry dead legacy-browser weight.
        modulePreload: { polyfill: false },
        rollupOptions: {
            // `foundations` is a second entry whose only purpose is to emit
            // `dist/foundations.[hash].css` — a Tailwind build that scans
            // `templates/foundations.twig` so its utility classes are
            // available to the foundations iframe regardless of which
            // utilities the consumer's own Tailwind config produces. The
            // matching foundations.html stub lands in dist/ as build noise
            // and is never served; PHP discovers the hashed CSS via glob.
            input: {
                index: 'index.html',
                foundations: 'foundations.html',
            },
            output: {
                // Mirrors the assetFileNames branch below: the foundations
                // entry's JS chunk (from foundations.html's <script
                // type="module" src="./foundations.js">) must emit as
                // foundations.[hash].js at dist root — Styleguide::
                // resolveFoundationsJsUrl() globs for that exact pattern,
                // same as it already does for foundations.[hash].css. Every
                // other entry (the main SPA bundle) keeps the untouched
                // styleguide.[hash].js naming.
                entryFileNames: (info) => {
                    return info.name === 'foundations' ? 'foundations.[hash].js' : 'styleguide.[hash].js';
                },
                assetFileNames: (info) => {
                    const name = info.name ?? '';
                    if (name === 'foundations.css' || name.endsWith('/foundations.css')) {
                        return 'foundations.[hash][extname]';
                    }
                    return 'styleguide.[hash][extname]';
                },
            },
        },
    },
});
