<script setup>
import { computed } from 'vue';
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
 */
const { t } = useTranslations();
const page = usePage();

function isActive(section) {
    return page.url === section.href || page.url.startsWith(`${section.href}/`);
}

const sectionLabel = (section) => t(`settings.nav.${section.key}`);
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-8 md:flex-row">
            <nav :aria-label="t('settings.title')" class="md:w-48 md:shrink-0">
                <ul class="flex flex-col gap-1 text-sm">
                    <li v-for="section in settingsSections" :key="section.key">
                        <Link
                            :href="section.href"
                            :aria-current="isActive(section) ? 'page' : undefined"
                            class="block rounded px-3 py-2"
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
