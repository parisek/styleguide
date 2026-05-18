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
            input: 'index.html',
            output: {
                entryFileNames: 'styleguide.[hash].js',
                assetFileNames: 'styleguide.[hash][extname]',
            },
        },
    },
});
