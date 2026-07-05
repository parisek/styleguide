import { copyFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

// Vendors axe-core's UMD bundle into dist/ as a stable, unhashed asset served
// at /styleguide/assets/axe.min.js. Not run through Rollup (it's a
// self-contained UMD script meant to be <script>-injected into the iframe
// document directly, not imported as an ES module) — a plain file copy after
// the main build, so a version bump of axe-core is a one-line package.json
// change with no build-graph wiring. Unhashed filename is deliberate: it's
// fetched on demand by axeInject.js, never referenced from dist/index.html,
// so there's no cache-busting concern that would need a content hash —
// AssetServer::isHashedFilename() falls through to the shorter
// `max-age=3600` branch for it instead of `immutable`, which is fine for a
// debug-only tool.
function copyAxeCore() {
    return {
        name: 'copy-axe-core',
        writeBundle() {
            copyFileSync(
                fileURLToPath(new URL('./node_modules/axe-core/axe.min.js', import.meta.url)),
                fileURLToPath(new URL('../dist/axe.min.js', import.meta.url)),
            );
        },
    };
}

export default defineConfig({
    // Asset URLs in dist/index.html become `/styleguide/assets/styleguide.[hash].(js|css)`,
    // matching the AssetServer route `/styleguide/assets/*` so served files hit the
    // hashed-filename branch (immutable cache) without a separate rewrite layer.
    base: '/styleguide/assets/',
    plugins: [vue(), tailwindcss(), copyAxeCore()],
    publicDir: 'public',
    build: {
        outDir: '../dist',
        emptyOutDir: true,
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
                entryFileNames: 'styleguide.[hash].js',
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
