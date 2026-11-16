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
 *
 * Varför `debugIdCitattecken` finns (issue 1371, MIMERS-JS-1): pluginen lägger
 * in en debug-id-snutt, och rolldowns minifierare skriver om dess strängar med
 * baktick. Sentrys CLI, som laddar upp kartorna, känner bara igen snutten med
 * vanliga citattecken, tror att den saknas och lägger in en egen på en ny rad
 * överst — i den uppladdade kopian, inte i filen vi levererar. Kartan skjuts
 * då en rad, och varje träff i Sentry hamnar en rad fel
 * (`js_invalid_sourcemap_location`). Pluginen nedan byter tillbaka de två
 * strängarna efter minifieringen. Tecknen är lika många, så kolumnerna i
 * kartan stämmer fortfarande. Tas bort när CLI:n läser baktick.
 */
const uploadSourcemaps = Boolean(process.env.SENTRY_AUTH_TOKEN);

const debugIdCitattecken = () => ({
    name: 'mimers-debug-id-citattecken',
    generateBundle(_options, bundle) {
        const snutt = /(_sentryDebugIds\[\w+\]=)`([0-9a-f-]{36})`,(\w+\._sentryDebugIdIdentifier=)`(sentry-dbid-[0-9a-f-]{36})`/;
        for (const chunk of Object.values(bundle)) {
            if (chunk.type === 'chunk') {
                chunk.code = chunk.code.replace(snutt, '$1"$2",$3"$4"');
            }
        }
    },
});

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
        uploadSourcemaps && debugIdCitattecken(),
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
