<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';
import ContainerCover from '../components/ContainerCover.vue';
import ContainerCoverSheet from '../components/ContainerCoverSheet.vue';
import ContainerHero from '../components/ContainerHero.vue';
import UiTabs from '../components/UiTabs.vue';
import { containerTabs } from './containerSections.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns skal, se issue 54 § Beslut 7 och issue 101 ·
 * [[ADR-0042 Designsystemet]].
 *
 * Samma konstruktion som SettingsLayout och av samma skäl: 55a (delning),
 * 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63 (scheman)
 * får alla en sida per container, och varje sida wrappar sitt innehåll i den här:
 *
 *   <ContainerLayout> ... </ContainerLayout>
 *
 * PROPKONTRAKTET: varje sida under den här layouten skickar en prop
 * `container` ur App\Http\Resources\ContainerResource — `{ ulid, name, kind,
 * account, created_at, updated_at }`. Rubriken läser `container.name` och
 * flikarnas href byggs ur `container.ulid`. Kontrollern gör
 * `ContainerResource::make($container)->resolve($request)`.
 *
 * Den aktiva containern läses INTE här. Den delade propen `activeContainer` bär
 * ULID:t och ingenting annat (issue 51 § Beslut 4) — en sida som behöver
 * containerns namn får det som sin egen `container`-prop, inte ur en utökad
 * delad prop. AppLayout har av samma skäl ingen containerväljare: listan på
 * /containers är ytan där containers byts.
 *
 * **Sektionsmenyn är en flikrad** (issue 101). Fram till dess renderade den här
 * layouten nio länkar i en kolumn ur `containerSections` — en vägg man mötte på
 * varje sida, och en navigering som sade *vad som finns* i stället för *var man
 * är*. Nu renderas flikraden av `UiTabs` (issue 100), som äger formen,
 * tangentbordet och den aktiva fliken; hit hör bara VILKA flikar som finns och
 * vad de heter. De sju sektionerna som inte fick plats — kategorier, taggar,
 * delning, kalender, export, papperskorg och överlåtelse — samlas på
 * inställningssidan, så ingen av dem tappar sin väg. Sedan issue 170 nås den
 * från hjälten över `md:` och från raden under flikraden under `md:` — den
 * lämnade flikraden då (ADR-0050 § 3).
 * Listan bor i containerSections.js; en ny flik är en ny rad där och ingen
 * ändring här.
 *
 * **Den aktiva fliken läses ur adressen och inte här.** `UiTabs` jämför
 * `page.url` med flikarnas egna href, så den här filen håller inget tillstånd
 * och ingen jämförelse: en flik man kan länka till är en flik man kan dela
 * (issue 100, issue 59a § Beslut 1).
 *
 * **Flikarnas etikett kommer ur `t()` med nyckeln `container.nav.<key>`,**
 * aldrig ur en sträng i den här filen ([[ADR-0013 Språk och i18n]]): texten
 * formuleras på servern och slås bara upp på klienten, precis som förut.
 * `label` är tablistens tillgängliga namn och är containerns namn — samma namn
 * den gamla sektionsmenyn bar, och det som säger vilken container raden hör
 * till.
 *
 * **Ingen ihopfällning längre** (issue 68a § Beslut 2). Den fällda menyn fanns
 * för att nio rader ovanför innehållet är nio rader man skrollar förbi; flikraden
 * är ett enda band, och då behövs ingen meny att fälla upp. Träffytan är
 * `min-h-11` — 44 px ur issue 68a § Beslut 3 — och den bor i `UiTabs`, som i
 * varje annan radåtgärd.
 *
 * **Flikraden skrollar i sidled under `md:`** (issue 151 · [[M23 Mobilen och
 * kartan]]). Bandet bröt rader förut, och på en telefon blev containerns fyra
 * flikar och itemets sju till tre rader ovanför innehållet — samma vägg som
 * den fällda menyn var svaret på. Skrollen bor i `UiTabs` och gäller varje
 * flikrad; över `md:` bryter bandet som förut.
 *
 * **Toppraden på mobilen bär containerns bild, namn och en tillbakaknapp**
 * (issue 151). Adressen tillbaka är `/containers` — listan man kom ifrån — och
 * etiketten är skalets egen (`nav.back`): knappen är en ikon, och en ikon utan
 * namn är en knapp en skärmläsare inte kan läsa. Antalet items som bilden visar
 * tas inte med; det finns inte i den här layoutens props, och att hämta det
 * vore en fråga per sida.
 *
 * **Bilden kom med issue 159 · [[ADR-0047 Containerns bild]] § Beslut**, och
 * det är den tredje av de tre ytor bilden ritas på: containerlistan,
 * dashboardens kort och containerns topprad.
 *
 * **Hjälten kom med issue 170 · [[ADR-0050 Desktopdesignen]] § 2–3**, och den
 * är den fjärde ytan: bilden desktopmockupen ritar
 * (`docs/Design/container.jpeg` och `docs/Design/kostnader.png`) är containerns
 * huvud över `md:`, och den ersätter namnraden som stod här förut. Proppen
 * `hero` bär formen — `large` på översikten, `compact` på de andra flikarna —
 * och den som inte skickar något får ingen hjälte och ser sin sida som förut.
 * Det är sidans svar och inte skalets: samma skal ritar översikten, itemlistan,
 * historiken och inställningssidan, och bara den första bär talen.
 *
 * **Under `md:` ritas ingen hjälte alls.** Den mörka toppraden från issue 151
 * bär containerns bild och namn där, och hjältens `hidden md:block` är samma
 * uppdelning som skalets egen: den ena ytan under brytpunkten, den andra över.
 *
 * **Raden *Inställningar* under flikraden är telefonens väg till sektionerna.**
 * Den lämnade flikraden i issue 170 (ADR-0050 § 3), och hjälten som tog över
 * vägen ritas inte här. Utan raden hade en telefon tappat containerns sju
 * sektioner — [[ADR-0042 Designsystemet]] § Konsekvenser förbjuder det — och
 * den ritas därför för var och en, också den som bara läser. Träffytan är
 * `min-h-11` (44 px, issue 68a § Beslut 3), som varje annan radåtgärd.
 *
 * **Talen går genom skalet och byggs inte här.** Den höga hjälten bär
 * översiktens `counts` ovanpå bilden, och de kommer in genom sloten
 * `hero-stats` — layouten frågar ingenting själv, precis som den inte räknar
 * något annat tal på sidan.
 *
 * **Pennan öppnar arket, och den ritas bara för den som får ändra containern**
 * (ADR-0047 § Beslut, "Vem som får göra vad"). Flaggan kommer som `can` från
 * sidan och läses ur samma policyfråga som formuläret på inställningssidan —
 * `ContainerPolicy::update` — och den är presentation: rutten prövar samma
 * grind på nytt. Arket ligger i `ContainerCoverSheet` och är detsamma som
 * avsnittet under containerns inställningar öppnar
 * (resources/js/pages/Containers/Edit.vue): två vägar till samma val.
 *
 * **Plusknappen förmedlas och byggs inte här** (issue 152 · [[ADR-0048
 * Mobilen och plusknappen]] § 2). Layouten tar emot `create` och skickar den
 * vidare till `AppLayout`, som ritar knappen i sidhuvudet och i flikraden. Vad
 * knappen gör — skapa ett item i den här containern, eller öppna en meny — är
 * sidans svar, prövat på servern mot samma policyer som rutterna. Att bygga
 * målet här hade varit en andra formulering av samma fråga, och den hade
 * svarat likadant på varje sida i containern även när sidan vet bättre.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget, eller null.
     * Skalet ritar knappen (issue 152 · [[ADR-0048 Mobilen och plusknappen]]
     * § 2), och den här layouten skickar bara vidare det sidan gav den: vad
     * knappen gör bestäms av sidan, och containerns skal lägger ingenting
     * till. En sida som inte skickar något får ingen knapp.
     */
    create: { type: Object, default: null },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur
     * (issue 159). Den styr om pennan på bilden ritas, och sedan issue 170 även
     * *Redigera container* i hjälten; arket och rutten prövar behörigheten
     * ändå.
     *
     * Flaggan kan inte bo i `container`-proppen: `can` är webbens fält och
     * läggs BREDVID `ContainerResource`, aldrig inuti den (issue 54 § Beslut
     * 9). Den kommer därför från sidan — och en sida som inte skickar den får
     * ingen penna och ingen redigeringsknapp. Översikten, itemlistan,
     * historiken och inställningssidan skickar den; formulären, schemasidorna
     * och itemvyn gör det inte, och deras rader visar bilden utan penna.
     */
    can: { type: Object, default: null },
    /*
     * Hjältens form (issue 170 · [[ADR-0050 Desktopdesignen]] § 2): `large` på
     * översikten, `compact` på de andra flikarna, och `null` — förvalet — på
     * varje sida som inte ska ha någon hjälte. Itemvyn, formulären och
     * schemasidorna skickar ingenting och ser ut som i dag;
     * inställningssidorna får sin låga hjälte av ContainerSettingsLayout, som
     * håller dem i en och samma flik (issue 678).
     *
     * Flaggan är sidans och inte skalets: samma layout ritar alla containerns
     * ytor, och vilken av dem som är containerns huvud är en fråga om sidan.
     */
    hero: { type: String, default: null },
});

const { t } = useTranslations();

const heading = computed(() => props.container.name);

const canUpdate = computed(() => props.can?.update === true);

/*
 * Flikarna i den form `UiTabs` vill ha: `{ key, label, href, count }`. `count`
 * är `null` och inte `0`: ingen av flikarna bär ett tal i bilden, och en nolla
 * hade varit ett påstående om innehållet som den här layouten inte har gjort
 * (issue 100). Servern räknar talen, och den som en dag vill visa ett skickar
 * in det.
 */
const tabs = computed(() =>
    containerTabs.map((tab) => ({
        key: tab.key,
        label: t(`container.nav.${tab.key}`),
        href: tab.href(props.container.ulid),
        count: null,
    })),
);
</script>

<template>
    <AppLayout :create="create">
        <template #topbar>
            <Link
                href="/containers"
                class="inline-flex min-h-11 min-w-11 items-center justify-center"
                :aria-label="t('nav.back')"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-5 w-5"
                    aria-hidden="true"
                >
                    <path d="M15 5l-7 7 7 7"></path>
                </svg>
            </Link>

            <!--
                Containerns bild, i samma fyrkant som bild 2 i
                docs/Design/mobil.png. Utan bild ritar `ContainerCover` den
                neutrala ytan med containertecknet (ADR-0047 § Beslut).
            -->
            <span class="h-10 w-10 shrink-0 overflow-hidden rounded-control">
                <ContainerCover :cover="container.cover" />
            </span>

            <p class="text-title font-semibold">{{ heading }}</p>

            <ContainerCoverSheet
                v-if="canUpdate"
                :container="container"
                v-slot="{ open }"
            >
                <button
                    type="button"
                    class="ml-auto inline-flex min-h-11 min-w-11 items-center justify-center"
                    :aria-label="t('container.cover.edit')"
                    @click="open"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <path d="M4 20h4l10-10-4-4L4 16z"></path>
                        <path d="m14 6 4 4"></path>
                    </svg>
                </button>
            </ContainerCoverSheet>
        </template>

        <div class="flex flex-col gap-8">
            <div>
                <!--
                    Hjälten, där sidan har en (issue 170). Den ersätter
                    namnraden som stod här: över `md:` är hjälten containerns
                    huvud, och två rader med samma namn är både en synlig
                    dubblett och en skärmläsare som läser fel.

                    `hidden md:block` — under brytpunkten bär den mörka
                    toppraden (sloten `topbar` ovan) containerns bild och namn,
                    och där ritas ingen hjälte.

                    Talen kommer från sidan genom `hero-stats` och ritas bara
                    av den höga hjälten: översikten äger sina `counts`, och
                    skalet frågar ingenting själv.
                -->
                <ContainerHero
                    v-if="hero"
                    class="hidden md:block"
                    :hero="hero"
                    :container="container"
                    :can="can"
                >
                    <template #stats>
                        <slot name="hero-stats" />
                    </template>
                </ContainerHero>

                <!-- Namnraden, på varje sida utan hjälte. Under `md:` bär den
                     mörka toppraden samma namn (sloten `topbar` ovan), och två
                     rubriker med samma text är både en synlig dubblett och en
                     skärmläsare som läser fel. -->
                <p v-else class="hidden px-3 py-2 font-medium text-title md:block">{{ heading }}</p>

                <UiTabs :tabs="tabs" :label="heading" />

                <!--
                    Under `md:` ritas ingen hjälte, och *Inställningar* lämnade
                    flikraden när hjälten tog över vägen dit (ADR-0050 § 3).
                    Utan den här raden hade containerns sju sektioner varit
                    oåtkomliga på en telefon — [[ADR-0042 Designsystemet]]
                    § Konsekvenser tillåter inte att en yta tappar sin väg —
                    och den ritas därför för var och en, också den som bara
                    läser. Adressen är inställningssidans egen rutt, och raden
                    ligger utanför `UiTabs` och utanför `TAB_KEYS`: flikraden är
                    fortfarande *Översikt · Items · Historik*.
                -->
                <Link
                    :href="`/containers/${container.ulid}/edit`"
                    class="mt-1 inline-flex min-h-11 items-center px-3 text-sm font-medium text-ink-muted underline md:hidden"
                >
                    {{ t('container.nav.settings') }}
                </Link>
            </div>

            <div class="min-w-0">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
