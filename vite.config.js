import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/admin.css',
                'resources/js/admin.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        // No manualChunks: forcing all of node_modules into one shared chunk made the
        // storefront download admin-only libraries (jQuery, DataTables, Chart.js).
        // Rollup's per-entry splitting keeps each bundle to what it actually imports.
        chunkSizeWarningLimit: 1000,
    },
});
