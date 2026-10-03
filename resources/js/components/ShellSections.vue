<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiListRow from './UiListRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Skalets sektioner, se [[ADR-0048 Mobilen och plusknappen]] § 1 och
 * [[M23 Mobilen och kartan]] § 151.
 *
 * **En lista, två ytor.** Över `md:` ritar AppLayout den i sidhuvudet; under
 * `md:` ritar MobileMenu samma rader i sidomenyn. Sektionerna kommer ur SAMMA
 * data — listan `sections` nedan — så att ingenting finns i den ena och
 * saknas i den andra: en rad som läggs till här syns på båda ställena, och en
 * rad som tas bort försvinner från båda. Det är `Klart när`-punkten, och den
 * är därför komponenten finns och inte två avskrifter.
 *
 * **Ordningen är listans**, `dashboard, tasks, containers, transfers, search,
 * settings`, samma ord som sidorna de leder till. Sedan issue 653 ritar en yta
 * bara en del av listan: `part` väljer vilka nycklar som ritas, och
 * sidopanelen staplar delarna i mockupens ordning ([[ADR-0050
 * Desktopdesignen]] § 1) — översikten överst (`top`), containrarna, resten av
 * raderna (`rows`, förvalet), de senast besökta, favoriterna och användarens
 * egen rad sist (`account`). Delarna är urval ur SAMMA lista och inte egna
 * listor, så en rad som flyttar flyttar i varje yta.
 *
 * **Etiketten slås upp ur `nav.<key>`** och kommer aldrig ur en sträng här
 * ([[ADR-0013 Språk och i18n]]): texten formuleras på servern och slås bara
 * upp på klienten, precis som `container.nav.<key>` i ContainerLayout.
 * Undantaget är raden för inställningarna (issue 163): dess text är
 * användarens namn — data ur den delade proppen och ingen mening — och nyckeln
 * `nav.settings` bor kvar som radens `title`, bara där, eftersom den är det
 * enda som säger vad raden GÖR. Grannradernas `title` hade bara upprepat deras
 * synliga text och gett dem en tooltip ingen beställt.
 *
 * **Nyligen besökta är INGEN av delarna** (issue 160 · [[ADR-0049
 * Nyligen besökta]] § Beslut). Den är en lista över items och inte en rad i
 * navigeringen, och den bor därför i
 * resources/js/components/RecentVisitList.vue — skalet ritar den direkt
 * ovanför favoriterna, i bandet över `md:` och i sidomenyn under det. Att
 * lägga den i `sections` hade gett en rad som pekade på en sida som inte finns
 * och en rubrik i navigeringen; ordningen här är oförändrad sedan issue 151.
 *
 * **Raderna blev sidopanelen** (issue 169 · [[ADR-0050 Desktopdesignen]] § 1).
 * Över `md:` står de inte längre i en vågrät rad i sidhuvudet utan i den mörka
 * sidopanelen, och de staplas därför på varandra på båda sidor om
 * brytpunkten: `md:flex-row` var sidhuvudets form och hade gett panelen sex
 * rader i sidled. Träffytan och etiketterna är oförändrade; ordningen mellan
 * delarna står hos den yta som staplar dem.
 *
 * **Den aktuella raden bär `aria-current="page"` och en token-färg.** Vilken
 * rad det är avgörs av `page.url` mot radens `href` — vägen och inte hela
 * adressen, för `page.url` bär querysträngen — och `matchPrefix` på
 * inställningsraden fångar undersidorna under `/settings`, dit
 * omdirigeringen från issue 53c leder. Färgen är `--color-shell-active`,
 * rollen ADR-0042 ger "den aktiva raden i sidopanelen".
 *
 * `part` skiljer blocken åt. `top` är översikten som står överst i panelen,
 * `rows` är navigeringen mellan sidorna, `account` är användarens egen rad med
 * vägen ut, och `favorites` är `FAVORITER`-sektionen ur issue 106. De är anrop
 * av SAMMA komponent i stället för fyra komponenter: raderna är desamma, och
 * det är bara urvalet och var de står som skiljer.
 */
const props = defineProps({
    /*
     * Vilket block som ritas: `top`, `rows` (förval), `account` eller
     * `favorites`.
     */
    part: { type: String, default: 'rows' },
});

const { t } = useTranslations();
const page = usePage();

const user = computed(() => page.props.auth.user);

/*
 * Den delade proppen `favorites`, filtrerad genom `ResolveItemScope` på
 * servern (issue 106). Vyn prövar inte omfånget en gång till: en andra regel
 * om vad man når är en andra sanning om samma sak.
 */
const favorites = computed(() => page.props.favorites ?? []);

const allSections = [
    { key: 'dashboard', href: '/dashboard' },
    { key: 'tasks', href: '/tasks' },
    { key: 'containers', href: '/containers' },
    { key: 'transfers', href: '/transfers' },
    { key: 'search', href: '/search' },
    // Inställningarna är den enda raden som också är aktuell på en UNDERSIDA:
    // `/settings` omdirigerar till profilen (issue 53c), så en jämförelse som
    // bara såg den exakta adressen hade lämnat raden omarkerad på varje
    // inställningssida. `matchPrefix` står därför på den och ingen annan —
    // `/containers` får inte samma flagga, för på en containersida är det
    // containerraden i sidopanelen som är den aktuella (issue 169).
    { key: 'settings', href: '/settings', matchPrefix: true },
];

/*
 * Vilka nycklar varje `part` ritar, ur SAMMA lista. En rad byter yta genom att
 * flyttas mellan raderna här; listan ovan är fortfarande den enda sanningen om
 * vad en rad är.
 */
const partKeys = {
    'top': ['dashboard'],
    'rows': ['tasks', 'containers', 'transfers', 'search'],
    'account': ['settings'],
};

const sections = computed(() =>
    allSections.filter((section) => partKeys[props.part].includes(section.key)),
);

/*
 * Sidans väg, utan querysträngen. `page.url` är hela adressen — Inertia
 * lägger frågan och dess parametrar i den — och en jämförelse mot `href` hade
 * fallit så fort en lista paginerades eller ett filter sattes.
 */
const currentPath = computed(() => page.url.split('?')[0]);

/*
 * Är den här raden sidan användaren står på? Se `matchPrefix` ovan.
 */
function isCurrent(section) {
    return currentPath.value === section.href
        || (section.matchPrefix === true && currentPath.value.startsWith(`${section.href}/`));
}
</script>

<template>
    <ul v-if="props.part !== 'favorites'" class="flex flex-col gap-1 px-2 text-sm">
        <li v-for="section in sections" :key="section.key" class="flex">
            <Link
                v-if="user"
                :href="section.href"
                :title="section.key === 'settings' ? t('nav.settings') : undefined"
                :aria-current="isCurrent(section) ? 'page' : undefined"
                class="inline-flex min-h-11 items-center rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="['w-full', isCurrent(section) ? 'bg-shell-active text-white' : 'hover:bg-shell-active/50']"
            >
                {{ section.key === 'settings' ? user.name : t(`nav.${section.key}`) }}
            </Link>
        </li>

        <!--
            Utloggningen är en <Link method="post">, inte ett eget formulär:
            /logout är en POST-rutt (routes/web.php) och Inertia skickar
            CSRF-tokenet åt oss. Utan den går det att logga in men inte ut i
            webbläsaren — och i sidomenyn är raden den enda vägen ut.

            Raden hör till `account` och inte till navigeringen (issue 653):
            den är ett mål och inte en sida, och den står sist i panelen
            tillsammans med användarens namn.
        -->
        <li v-if="props.part === 'account' && user" class="flex">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="inline-flex min-h-11 w-full items-center rounded-control outline-none hover:bg-shell-active/50 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('auth.logout') }}
            </Link>
        </li>

        <li v-else-if="props.part === 'account'" class="flex">
            <Link
                href="/login"
                class="inline-flex min-h-11 w-full items-center rounded-control outline-none hover:bg-shell-active/50 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('nav.login') }}
            </Link>
        </li>
    </ul>

    <!--
        FAVORITER, se issue 106 och [[M17 Designsystemet]] § 106. Sektionen
        ritas bara när listan har rader: en rubrik över en tom lista är en yta
        som lovar något den inte har, och den som inte har några favoriter ska
        inte mötas av ett tomt fack. Ingen räknare och ingen antydan om hur
        många som fallit bort ur omfånget (issue 73 § Beslut 6).
    -->
    <nav v-else-if="favorites.length" :aria-label="t('nav.favorites')" class="w-full pt-6">
        <h2 class="text-meta font-semibold uppercase tracking-wide text-ink-subtle">
            {{ t('nav.favorites') }}
        </h2>

        <ul class="flex flex-col">
            <UiListRow v-for="favorite in favorites" :key="favorite.url">
                <template #title>
                    <Link :href="favorite.url" class="flex min-h-11 items-center rounded-control hover:bg-shell-active/50">
                        {{ favorite.name }}
                    </Link>
                </template>
            </UiListRow>
        </ul>
    </nav>
</template>
