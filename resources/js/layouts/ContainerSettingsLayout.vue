<script setup>
import { ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { containerSettingsSections } from './containerSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns inställningsskal, se issue 101 och [[ADR-0042 Designsystemet]].
 *
 * Flikraden bär de ytor man arbetar i — översikten, itemen och historiken —
 * och de sju sektionerna som inte fick plats där samlade issue 101 på
 * inställningssidan, som en lista UNDER formuläret. Den låg bara där, och de
 * sex andra sidorna nåddes därför bara via inställningssidan.
 *
 * **Här blir listan en sidokolumn i stället, och den står på alla åtta
 * sidor.** Samma konstruktion som SettingsLayout och av samma skäl: de sju
 * sektionerna är syskon, och en meny som bara finns på en av dem tvingar
 * varje byte genom den sidan. Layouten lägger kolumnen till VÄNSTER om
 * innehållet och under flikraden och namnraden — flikraden och rubriken ritas
 * av ContainerLayout och upprepas inte här.
 *
 * **Den här layouten ritar bara sin egen del** (issue 275 · [[ADR-0056
 * Flytande navigering]] § 1). Kolumnen ovan är hela dess mall; `AppLayout` och
 * `ContainerLayout` ligger utanför den i kedjan, och sidan skriver
 * `layout: [AppLayout, ContainerLayout, ContainerSettingsLayout]` i sina
 * `defineOptions`. Den tar därför inte emot `create` eller `can` — den läste
 * dem aldrig själv, den vidarebefordrade dem bara, och nu går de förbi den.
 *
 * **`hero="compact"` är sidans svar sedan issue 275** (issue 678): de åtta
 * sidorna är EN flik, och en hjälte som fanns på en av dem men försvann på
 * nästa hade hoppat när man bytte rad i kolumnen. Attributet låg i den här
 * layouten så länge den ritade `ContainerLayout`; nu står `hero: 'compact'` i
 * `ContainerLayout`-ledet i var och en av de åttas kedja, och ingen av dem
 * skickar det till den här layouten. PROPKONTRAKTET är `container` ur
 * App\Http\Resources\ContainerResource — det enda den läser själv.
 *
 * **Listan kommer ur `containerSettingsSections` och renderas med `v-for`.**
 * En ny sektion är en ny rad i containerSections.js och ingen ändring här,
 * precis som i SettingsLayout. Adressen byggs ur `section.href(container.ulid)`
 * — sektionerna ligger under en container, och vilken det är vet bara den här
 * layouten, genom sin `container`-prop.
 *
 * **Den aktiva posten läses ur adressen och inte ur ett tillstånd.** Samma
 * skäl som i UiTabs: en post man kan länka till är en post man kan dela, och
 * en jämförelse mot `page.url` klarar en omladdning och en webbläsare som går
 * bakåt. Texten kommer ur `t()` med nyckeln `container.nav.<key>`, aldrig ur
 * en sträng i den här filen ([[ADR-0013 Språk och i18n]]).
 *
 * **Kolumnen fälls ihop på en telefon**, på samma sätt och av samma skäl som
 * SettingsLayout: sju rader ovanför innehållet är sju rader man skrollar
 * förbi varje gång. Över `md:` står listan framme och knappen försvinner;
 * rubriken `container.edit.sections` är navens tillgängliga namn och den
 * synliga rubriken över listan där. Länkarna bär `min-h-11` (44 px, issue 68a
 * § Beslut 3).
 */
const props = defineProps({
    /* Containern, ur App\Http\Resources\ContainerResource — samma prop som
       ContainerLayout kräver, och det enda den här layouten läser själv. */
    container: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();
const sectionsOpen = ref(false);

/*
 * Menyn stängs vid navigering, se issue 274 och issue 275.
 *
 * Sedan sidorna deklarerar sin layout med `defineOptions` behåller Inertia
 * skalets instans mellan två sidor — och därmed dess tillstånd. Utan raden
 * står den hopfällda sektionsmenyn öppen på mobilen efter ett klick i den.
 * Samma vakt och samma skäl som i AppLayout och SettingsLayout.
 */
watch(() => page.url, () => {
    sectionsOpen.value = false;
});

function isActive(section) {
    const href = section.href(props.container.ulid);

    return page.url === href || page.url.startsWith(`${href}/`);
}

const sectionLabel = (section) => t(`container.nav.${section.key}`);
</script>

<template>
    <div class="flex flex-col gap-8 md:flex-row">
        <nav :aria-label="t('container.edit.sections')" class="md:w-48 md:shrink-0">
            <button
                type="button"
                class="inline-flex min-h-11 w-full items-center rounded border border-slate-300 px-3 text-sm font-medium md:hidden"
                aria-controls="containerns-installningar"
                :aria-expanded="sectionsOpen"
                @click="sectionsOpen = !sectionsOpen"
            >
                {{ sectionsOpen ? t('nav.menu_close') : t('nav.menu') }}
            </button>

            <h2 class="hidden text-title md:block">{{ t('container.edit.sections') }}</h2>

            <ul
                id="containerns-installningar"
                class="flex-col gap-1 text-sm"
                :class="sectionsOpen ? 'flex' : 'hidden md:flex'"
            >
                <li v-for="section in containerSettingsSections" :key="section.key">
                    <Link
                        :href="section.href(container.ulid)"
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
</template>
