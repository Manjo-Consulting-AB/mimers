<script setup>
import { ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppHero from '../components/AppHero.vue';
import { settingsSections } from './settingsSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Inställningarnas skal, se issue 53b § Beslut 2 och issue 274.
 *
 * **Ramen deklareras av sidan** (issue 274). Den här layouten ritar bara sin
 * egen del — hjälten, sidonavigationen och ytan bredvid den — och varje
 * inställningssida deklarerar `layout: [AppLayout, SettingsLayout]`. AppLayout
 * ligger då utanför, och header, flash och footer kommer därifrån och upprepas
 * inte här.
 *
 * **Hjälten ritas här en gång** (issue 438 · [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-22). Det är samma `AppHero` som dashboarden och
 * toppnivåsidorna ritar — samma höjd, bild, brickor och donut — och bara
 * rubriken *Settings* och undertiteln skiljer. Den står ovanför navigationen
 * och innehållet och bär sidans enda `<h1>`; varje inställningssidas egen
 * rubrik är därför en `<h2>`. Bekräftelsesidorna för radering använder inte
 * den här layouten och ritar sin egen `<h1>`.
 *
 * Navigationen renderas ur settingsSections med v-for, inte som hårdkodade
 * <Link> — en post till kräver ingen ändring här, bara en rad i listan.
 * Den här issuen har en post (Säkerhet); issue 53c lägger till Profil och
 * Konto, issue 65 Notiser, issue 66 Plan.
 *
 * Texten kommer ur t() med nyckeln `settings.nav.<key>`, aldrig ur en
 * sträng i den här filen — utom för en rad som bär sin EGEN nyckel i `label`,
 * som ägarbytena (`nav.transfers`, M28 · issue 248): ordet bor redan i skalet,
 * och en kopia under `settings.nav.transfers` hade varit samma mening två
 * gånger ([[ADR-0013 Språk och i18n]]).
 *
 * **Sidlistan fälls ihop på en telefon** (issue 68a § Beslut 2), på samma
 * sätt och av samma skäl som ContainerLayouts sektionslista: sju rader
 * ovanför innehållet är sju rader man skrollar förbi varje gång. Över `md:`
 * står listan framme som förut och knappen försvinner.
 *
 * **Menyn stängs vid navigering** (issue 274 § Beslut 3). Skalet står kvar
 * mellan två sidor, så dess tillstånd gör det också: utan vakten nedan står
 * den hopfällda sektionsmenyn öppen på mobilen efter ett klick i den.
 *
 * Länkarna bär `min-h-11` (44 px, issue 68a § Beslut 3).
 */
const { t } = useTranslations();
const page = usePage();
const sectionsOpen = ref(false);

watch(() => page.url, () => {
    sectionsOpen.value = false;
});

function isActive(section) {
    return page.url === section.href || page.url.startsWith(`${section.href}/`);
}

/*
 * Rader med `requires` ritas bara när den delade propen är sann (issue 407).
 */
const isVisible = (section) => section.requires === undefined || page.props[section.requires] === true;

/*
 * Radens ord: dess egen nyckel när den bär en (`label`), annars
 * `settings.nav.<key>`. Se docblocket ovanför.
 */
const sectionLabel = (section) => t(section.label ?? `settings.nav.${section.key}`);
</script>

<template>
    <div class="flex flex-col gap-8">
        <!--
            Hjälten (issue 438 · [[ADR-0090 Dashboardens hjälte]] § Tillägg
            2026-11-22) står ovanför både menyn och innehållet och bär sidans
            enda `<h1>`. Rubriken är inställningarnas egen — sidans rubrik
            ritas som `<h2>` i innehållet — och talen hämtas av `AppHero` när
            de saknas, precis som på toppnivåsidorna.
        -->
        <AppHero
            :heading="t('hero.heading.settings')"
            :subtitle="t('hero.subtitle.settings')"
        />

        <div class="flex flex-col gap-8 md:flex-row">
            <nav :aria-label="t('settings.title')" class="md:w-48 md:shrink-0">
                <button
                    type="button"
                    class="inline-flex min-h-11 w-full items-center rounded border border-slate-300 px-3 text-sm font-medium md:hidden"
                    aria-controls="installningarnas-sidor"
                    :aria-expanded="sectionsOpen"
                    @click="sectionsOpen = !sectionsOpen"
                >
                    {{ sectionsOpen ? t('nav.menu_close') : t('nav.menu') }}
                </button>

                <ul
                    id="installningarnas-sidor"
                    class="flex-col gap-1 text-sm"
                    :class="sectionsOpen ? 'flex' : 'hidden md:flex'"
                >
                    <template v-for="section in settingsSections" :key="section.key">
                        <li v-if="isVisible(section)">
                            <Link
                                :href="section.href"
                                :aria-current="isActive(section) ? 'page' : undefined"
                                class="flex min-h-11 items-center rounded px-3"
                                :class="isActive(section) ? 'bg-slate-200 font-medium' : 'hover:bg-slate-100'"
                            >
                                {{ sectionLabel(section) }}
                            </Link>
                        </li>
                    </template>
                </ul>
            </nav>

            <div class="min-w-0 flex-1">
                <slot />
            </div>
        </div>
    </div>
</template>
