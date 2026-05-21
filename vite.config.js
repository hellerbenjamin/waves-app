import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        // Runs inside the ddev web container; ddev-router exposes 5173 over TLS.
        // `origin` is written verbatim into the hot file, so Laravel emits asset
        // URLs the browser can actually reach over https.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'https://waves.ddev.site:5173',
        cors: true,
        hmr: {
            protocol: 'wss',
            host: 'waves.ddev.site',
            clientPort: 5173,
        },
        watch: {
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
                '**/storage/**',
                '**/.git/**',
            ],
        },
    },
});
