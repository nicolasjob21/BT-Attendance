import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Builds static/src → static/dist with fixed filenames so Django templates can
// reference them directly ({% static 'dist/app.css' %}); whitenoise hashes them
// for production caching.
export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        outDir: 'static/dist',
        emptyOutDir: true,
        rollupOptions: {
            input: { app: 'static/src/app.js' },
            output: {
                entryFileNames: 'app.js',
                assetFileNames: (info) => (info.name && info.name.endsWith('.css') ? 'app.css' : 'assets/[name][extname]'),
            },
        },
    },
});
