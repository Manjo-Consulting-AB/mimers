<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';
import { settingsSections } from './settingsSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Inställningarnas skal, se issue 53b § Beslut 2.
 *
 * AppLayout är fortfarande ramen — header, flash och footer kommer därifrån
 * och upprepas inte här. Det här är bara sidonavigationen och ytan bredvid
 * den, och varje inställningssida wrappar sitt innehåll i den:
 *
 *   <SettingsLayout> ... </SettingsLayout>
 *
 * Navigationen renderas ur settingsSections med v-for, inte som hårdkodade
 * <Link> — en post till kräver ingen ändring här, bara en rad i listan.
 * Den här issuen har en post (Säkerhet); issue 53c lägger till Profil och
 * Konto, issue 65 Notiser, issue 66 Plan.
 *
 * Texten kommer ur t() med nyckeln `settings.nav.<key>`, aldrig ur en
 * sträng i den här filen.
 *
 * **Sidlistan fälls ihop på en telefon** (issue 68a § Beslut 2), på samma
 * sätt och av samma skäl som ContainerLayouts sektionslista: sju rader
 * ovanför innehållet är sju rader man skrollar förbi varje gång. Över `md:`
 * står listan framme som förut och knappen försvinner.
 *
 * Länkarna bär `min-h-11` (44 px, issue 68a § Beslut 3).
 */
const { t } = useTranslations();
const page = usePage();
const sectionsOpen = ref(false);

function isActive(section) {
    return page.url === section.href || page.url.startsWith(`${section.href}/`);
}

const sectionLabel = (section) => t(`settings.nav.${section.key}`);
</script>

<template>
    <AppLayout>
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
                    <li v-for="section in settingsSections" :key="section.key">
                        <Link
                            :href="section.href"
                            :aria-current="isActive(section) ? 'page' : undefined"
                            class="flex min-h-11 items-center rounded px-3"
                            :class="isActive(section) ? 'bg-slate-200 font-medium' : 'hover:bg-slate-100'"
                        >
                            {{ sectionLabel(section) }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <div class="min-w-0 flex-1">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
