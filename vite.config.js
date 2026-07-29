import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            // Emits bootstrap/ssr/ssr.js on `vite build --ssr` — the conventional
            // Laravel SSR bundle path, which is what `ssr_ready` detection looks
            // for and what `artisan inertia:start-ssr` runs.
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    // Inline React and Inertia into the SSR bundle so bootstrap/ssr/ssr.js runs
    // standalone. An SSR process starts the bundle from the deployed tree
    // directly, so a self-contained file cannot break on a missing or stale
    // node_modules in the deployed tree.
    ssr: {
        noExternal: true,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
