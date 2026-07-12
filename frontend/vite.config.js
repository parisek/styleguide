import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    // Asset URLs in dist/index.html become `/styleguide/assets/styleguide.[hash].(js|css)`,
    // matching the AssetServer route `/styleguide/assets/*` so served files hit the
    // hashed-filename branch (immutable cache) without a separate rewrite layer.
    base: '/styleguide/assets/',
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
