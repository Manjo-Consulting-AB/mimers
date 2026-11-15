import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { sentryVitePlugin } from '@sentry/vite-plugin';

/*
 * Källkartor till Sentry — issue 388, [[ADR-0079 Övervakning]] § 1.
 *
 * Kartorna skapas alltid (`hidden`: bundlen refererar dem inte) men laddas bara
 * upp när `SENTRY_AUTH_TOKEN` finns, så ett bygge utan token — lokalt, i
 * ci.yml — hoppar över uppladdningen utan att fällas. `.map`-filerna tas bort
 * ur artefakten av staging.yml, så att de aldrig publiceras.
 */
const uploadSourcemaps = Boolean(process.env.SENTRY_AUTH_TOKEN);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
        tailwindcss(),
        uploadSourcemaps && sentryVitePlugin({
            org: process.env.SENTRY_ORG || 'mimers',
            project: process.env.SENTRY_JS_PROJECT || 'mimers-js',
            url: process.env.SENTRY_URL || 'https://de.sentry.io',
            authToken: process.env.SENTRY_AUTH_TOKEN,
            release: { name: process.env.SENTRY_RELEASE },
            telemetry: false,
        }),
    ],
    build: {
        sourcemap: 'hidden',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
