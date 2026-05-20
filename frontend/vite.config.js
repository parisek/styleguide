import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    // Asset URLs in dist/index.html become `/styleguide/assets/styleguide.[hash].(js|css)`,
    // matching the AssetServer route `/styleguide/assets/*` so served files hit the
    // hashed-filename branch (immutable cache) without a separate rewrite layer.
    base: '/styleguide/assets/',
    plugins: [tailwindcss()],
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
