import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

// Test-only config: no Laravel plugin (it touches public/hot and Ziggy, neither
// of which the unit tests want), no dev server. Component tests run under
// happy-dom for speed; bump to jsdom if anything needs the wider browser API.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        globals: true,
        include: ['tests/js/**/*.{test,spec}.{js,mjs,ts}'],
        // Stub Laravel/Ziggy's global `route()` helper so files that import it
        // (Inertia pages, composables) don't blow up under Node.
        setupFiles: ['./tests/js/setup.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'html'],
            include: ['resources/js/**/*.{js,vue}'],
        },
    },
});
