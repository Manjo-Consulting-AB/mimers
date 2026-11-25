<script setup>
import { Link } from '@inertiajs/vue3';
import FlashMessage from './FlashMessage.vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Tvåkolumnsskalet för gästsidorna, issue 1671. Utbrutet ur Auth/Login.vue
 * (issue 203, 490) så att `/login` och `/register` ritar samma helsida: bilden
 * och löftena till vänster, ett kort till höger och länkarna till
 * informationssidorna under kortet.
 *
 * **Utan AppLayout** (issue 203 § Beslut 1): `<div class="flex min-h-screen">`
 * är hela skalet. Flashmeddelandet ritas ändå — `<FlashMessage />` överst i
 * högerkolumnen, samma komponent som AppLayout lägger i innehållsytan, så att
 * ett "status" ur sessionen inte tappas på vägen in.
 *
 * **Slotten är kortets innehåll.** Kortet, märket överst i det och ramen runt
 * är skalets; rubriken, formuläret och länkarna i kortet är sidans.
 *
 * Fotot bakom texten ritas sedan issue 490 — en slumpad bild av tre, se
 * `loginImages` nedan. Raden *Används för* och rutan om molnlagringen ritas
 * fortfarande inte: de har ingen datakälla och byggs inte nu (issue 203 §
 * Beslut 6).
 *
 * Texten är engelsk och kommer ur `lang/en/ui.php` under `auth`.
 */
const { t } = useTranslations();

/*
 * De fyra punkterna i vänsterkolumnen, i bildens ordning (Beslut 2). Nycklarna
 * står utskrivna och byggs inte ur `key`: en dynamisk `auth.landing.features.
 * ${key}.title` hade gömt dem för källkodsprovet som letar efter dem, och
 * listan är kort nog att läsas som den är.
 */
const landingFeatures = [
    {
        title: 'auth.landing.features.documents.title',
        body: 'auth.landing.features.documents.body',
        role: 'document',
    },
    {
        title: 'auth.landing.features.maintenance.title',
        body: 'auth.landing.features.maintenance.body',
        role: 'task',
    },
    {
        title: 'auth.landing.features.costs.title',
        body: 'auth.landing.features.costs.body',
        role: 'cost',
    },
    {
        title: 'auth.landing.features.relations.title',
        body: 'auth.landing.features.relations.body',
        role: 'link',
    },
];

/*
 * De tre bilderna bakom vänsterkolumnen (issue 490). Sökvägarna är utskrivna
 * och inte byggda ur en mapplista: filerna ligger färdiga i
 * `public/images/login/` och refereras med absolut sökväg, som
 * `/images/dashboard-hero.jpg` i AppHero.vue — inte via Vite.
 */
const loginImages = [
    '/images/login/boat.webp',
    '/images/login/caravan.webp',
    '/images/login/cabin.webp',
];

/*
 * En bild, slumpad en gång när komponenten sätts upp — inte ett bildspel.
 * Slumpen står i `setup` och inte i en `computed`, så att den dras exakt en
 * gång per sidladdning: en computed kan räknas om och hade då kunnat byta bild
 * medan sidan står öppen. Sidan renderas inte på servern, så det finns ingen
 * hydrering att hamna i otakt med.
 */
const loginImage = loginImages[Math.floor(Math.random() * loginImages.length)];
</script>

<template>
    <div class="flex min-h-screen">
        <!--
            Vänsterkolumnen, se Beslut 2. `hidden lg:flex` — på en telefon är
            hela ytan kortet. Märket överst, allt annat tryckt mot botten av
            `justify-between`.

            Bilden (issue 490) ligger bakom texten: den enda `<img>` är
            `absolute inset-0` med `object-cover`, och över den en slöja i
            `bg-shell` som håller texten läsbar mot båtens ljusa himmel.
            `bg-shell` står kvar på kolumnen, så ytan är mörk innan bilden
            laddats. `loading="lazy"` gör att en telefon, där kolumnen aldrig
            ritas, inte heller hämtar bilden. Bilden är dekoration: `alt=""`
            och `aria-hidden="true"` — den säger ingenting som texten inte
            redan säger.
        -->
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-between relative overflow-hidden bg-shell text-white p-12">
            <img
                :src="loginImage"
                alt=""
                aria-hidden="true"
                loading="lazy"
                decoding="async"
                class="absolute inset-0 h-full w-full object-cover"
            >

            <div class="absolute inset-0 bg-shell/70" aria-hidden="true"></div>

            <p class="relative text-lg font-semibold uppercase tracking-[0.3em]">{{ t('common.brand') }}</p>

            <div class="relative">
                <p class="text-4xl font-semibold">
                    {{ t('auth.landing.heading') }}
                    <span class="text-sky-300">{{ t('auth.landing.heading_accent') }}</span>
                </p>

                <p class="mt-6 text-body text-white/80">{{ t('auth.landing.body') }}</p>

                <ul class="mt-8 flex flex-col gap-6">
                    <li v-for="feature in landingFeatures" :key="feature.title" class="flex items-start gap-4">
                        <span class="shrink-0 rounded-control bg-white/10 p-2">
                            <UiIcon :name="feature.role" :size="24" class="h-6 w-6" />
                        </span>

                        <span>
                            <span class="block font-semibold">{{ t(feature.title) }}</span>
                            <span class="block text-body text-white/80">{{ t(feature.body) }}</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <!--
            Högerkolumnen, se issue 203 § Beslut 3. Kortet bär sidans
            innehåll i slotten. Länkarna under kortet pekar på
            informationssidorna ur issue 202.
        -->
        <div class="flex w-full lg:w-1/2 flex-col items-center justify-center bg-surface-muted px-4 py-12">
            <FlashMessage />

            <div class="w-full max-w-md rounded-card border border-border bg-surface p-8 shadow-sm">
                <p class="text-center text-lg font-semibold">{{ t('common.brand') }}</p>

                <slot />
            </div>

            <nav class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                <Link href="/about" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.about') }}</Link>
                <Link href="/privacy" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.privacy') }}</Link>
                <Link href="/terms" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.terms') }}</Link>
                <Link href="/subprocessors" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.subprocessors') }}</Link>
                <Link href="/contact" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.contact') }}</Link>
                <Link href="/help" class="text-meta text-ink-subtle hover:underline">{{ t('auth.landing.links.help') }}</Link>
            </nav>
        </div>
    </div>
</template>
