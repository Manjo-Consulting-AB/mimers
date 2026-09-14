<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';
import { containerSections } from './containerSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Pärmens skal, se issue 54 § Beslut 7.
 *
 * Samma konstruktion som SettingsLayout och av samma skäl: 55a (delning),
 * 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63 (scheman)
 * får alla en sida per pärm, och varje sida wrappar sitt innehåll i den här:
 *
 *   <ContainerLayout> ... </ContainerLayout>
 *
 * PROPKONTRAKTET: varje sida under den här layouten skickar en prop
 * `container` ur App\Http\Resources\ContainerResource — `{ ulid, name, kind,
 * account, created_at, updated_at }`. Rubriken läser `container.name` och
 * navigationen bygger sina href ur `container.ulid`. 55a behöver inte gissa:
 * kontrollern gör `ContainerResource::make($container)->resolve($request)`.
 *
 * Den aktiva pärmen läses INTE här. Den delade propen `activeContainer` bär
 * ULID:t och ingenting annat (issue 51 § Beslut 4) — en sida som behöver
 * pärmens namn får det som sin egen `container`-prop, inte ur en utökad
 * delad prop. AppLayout har av samma skäl ingen pärmväljare: listan på
 * /containers är ytan där pärmar byts.
 *
 * Navigationen renderas ur containerSections med v-for — en post till kräver
 * ingen ändring här, bara en rad i listan. Texten kommer ur t() med nyckeln
 * `container.nav.<key>`, aldrig ur en sträng i den här filen.
 *
 * Undantaget är itemlistan (issue 57a), vars href är pärmens EGNA url och
 * därför ett prefix till alla andra — se isActive() nedan.
 */
const props = defineProps({
    container: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const sectionHref = (section) => section.href(props.container.ulid);

const containerRoot = computed(() => `/containers/${props.container.ulid}`);

function isActive(section) {
    const href = sectionHref(section);

    // Itemlistan (issue 57a) är pärmens EGEN url och därmed ett prefix till
    // varje annan sektion — /containers/X matchar /containers/X/categories.
    // Utan det här undantaget hade `items` lyst på varje sida under pärmen,
    // vid sidan av den sida man faktiskt står på.
    if (href === containerRoot.value) {
        return page.url === href;
    }

    return page.url === href || page.url.startsWith(`${href}/`);
}

const sectionLabel = (section) => t(`container.nav.${section.key}`);

const heading = computed(() => props.container.name);
</script>

<template>
    <AppLayout>
        <div class="flex flex-col gap-8 md:flex-row">
            <nav :aria-label="heading" class="md:w-48 md:shrink-0">
                <p class="px-3 py-2 font-medium">{{ heading }}</p>

                <ul class="flex flex-col gap-1 text-sm">
                    <li v-for="section in containerSections" :key="section.key">
                        <Link
                            :href="sectionHref(section)"
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
