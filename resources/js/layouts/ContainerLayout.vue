<script setup>
import { computed, inject, onBeforeUnmount, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
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
 * får alla en sida per container, och varje sida deklarerar den här i sin
 * layoutkedja — mallen bär den inte (issue 275).
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
 * (issue 151), och den ritas HÄR och teleporteras in i skalet (issue 275 ·
 * [[ADR-0056 Flytande navigering]] § 1). Skalet ligger utanför den här
 * layouten i kedjan, och en slot kan bara fyllas nedåt: den som är innerst
 * kan inte fylla en slot hos den som är ytterst. Skalet ritar därför ett mål
 * (`#shell-topbar`) och ett märke, och den här layouten teleporterar in sin
 * markup i målet och märker raden som tagen — märket viker då. Markupen är
 * oförändrad; det är bara var den ritas från som flyttade.
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
 * **Talen byggs inte här.** Sedan issue 680 läser hjälten den delade proppen
 * `containerCounts` själv och ritar sina brickor ur den; skalet frågar
 * ingenting och förmedlar ingenting, precis som det inte räknar något annat
 * tal på sidan.
 *
 * **Flaggan `can` styr vad den som får ändra containern ser** (ADR-0047
 * § Beslut, "Vem som får göra vad"). Den kommer från sidan och läses ur samma
 * policyfråga som formuläret på inställningssidan — `ContainerPolicy::update`
 * — och den är presentation: rutten prövar samma grind på nytt. Här ritar den
 * hjältens *Redigera container* och pennan på containerns bild i toppraden;
 * arket pennan öppnar (`ContainerCoverSheet`) är detsamma som avsnittet under
 * containerns inställningar öppnar (resources/js/pages/Containers/Edit.vue):
 * två vägar till samma val. Pennan läser `can` ur sin egen prop och aldrig ur
 * `page.props.can`: den senare bär sidans EGNA flaggor, och på en item- eller
 * schemasida betyder `can.update` itemets behörighet (issue 275).
 *
 * **Plusknappen byggs inte här och förmedlas inte härifrån** (issue 152 ·
 * [[ADR-0048 Mobilen och plusknappen]] § 2). Den som har ett mål skickar det
 * direkt till `AppLayout`, som ritar knappen i sidhuvudet och i flikraden —
 * ledet ligger först i kedjan och den här layouten rör det inte. Fram till
 * issue 275 tog layouten emot `create` och skickade den vidare; nu går målet
 * förbi den, för skalet ligger utanför och en prop kan inte heller bäras uppåt.
 * Vad knappen gör — skapa ett item i den här containern, eller öppna en meny —
 * är sidans svar, prövat på servern mot samma policyer som rutterna.
 *
 * **Kedjan deklareras av sidan** (issue 275 · [[ADR-0056 Flytande
 * navigering]] § 1). Den här layouten ritar bara sin egen del — hjälten,
 * flikraden, namnraden och sloten — och `AppLayout` ligger utanför den. Sidan
 * skriver `layout: [AppLayout, ContainerLayout]` i sina `defineOptions`, och
 * Inertia behåller båda instanserna mellan två sidor. Layouten ritar alltså
 * aldrig en annan layout, och dess egen mall är rotnoden den fick.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur
     * (issue 159). Sedan issue 170 styr den *Redigera container* i hjälten —
     * pennan på bilden flyttade till skalets topprad i issue 275 — och arket
     * och rutten prövar behörigheten ändå.
     *
     * Flaggan kan inte bo i `container`-proppen: `can` är webbens fält och
     * läggs BREDVID `ContainerResource`, aldrig inuti den (issue 54 § Beslut
     * 9). Den kommer därför från sidan — och en sida som inte skickar den får
     * ingen redigeringsknapp. Översikten, itemlistan,
     * historiken och inställningssidan skickar den; formulären, schemasidorna
     * och itemvyn gör det inte, och deras topprad visar bilden utan penna.
     */
    can: { type: Object, default: null },
    /*
     * Hjältens form (issue 170 · [[ADR-0050 Desktopdesignen]] § 2): `large` på
     * översikten, `compact` på de andra flikarna, och `null` — förvalet — på
     * varje sida som inte ska ha någon hjälte. Itemvyn, formulären och
     * schemasidorna skickar ingenting och ser ut som i dag;
     * inställningssidorna får sin låga hjälte av sitt eget `ContainerLayout`-led
     * sedan issue 275 — de åtta är EN flik (issue 678).
     *
     * Flaggan är sidans och inte skalets: samma layout ritar alla containerns
     * ytor, och vilken av dem som är containerns huvud är en fråga om sidan.
     */
    hero: { type: String, default: null },
});

const { t } = useTranslations();

/*
 * Toppraden på mobilen ritas här men bor i skalet (issue 275 · [[ADR-0056
 * Flytande navigering]] § 1). Skalet ritar målet `#shell-topbar` och ett
 * märke; den här layouten teleporterar in sin markup i målet och sätter
 * flaggan, så att märket viker. Flaggan är skalets och kommer nedåt som en
 * `provide` — den inre layouten kan inte fylla en slot hos den yttre, men den
 * kan märka raden som tagen. `null` för en layout som ritas utanför skalet.
 */
const topbarClaimed = inject('shellTopbar', null);

onMounted(() => {
    if (topbarClaimed) {
        topbarClaimed.value = true;
    }
});

onBeforeUnmount(() => {
    if (topbarClaimed) {
        topbarClaimed.value = false;
    }
});

/* Pennan i toppraden ritas bara för den som får ändra containern (ADR-0047
   § Beslut). Flaggan är presentation: rutten prövar samma grind på nytt. */
const canUpdateCover = computed(() => props.can?.update === true);

const heading = computed(() => props.container.name);

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
    <div class="flex flex-col gap-8">
        <!--
            Toppraden på mobilen, teleporterad in i skalet (issue 275 ·
            [[ADR-0056 Flytande navigering]] § 1). Skalet ligger utanför den
            här layouten i kedjan, och en slot kan bara fyllas nedåt — så
            markupen ritas här och flyttas in i målet `#shell-topbar`, medan
            flaggan `topbarClaimed` får skalets märke att vika. `defer` gör att
            teleporten väntar till efter renderingen: målet finns i skalet,
            som ligger utanför, och är alltså inte klart när det här ledet
            renderas.

            Markupen är oförändrad sedan issue 151 — tillbakaknappen, bilden,
            namnet och pennan bakom `can.update`.
        -->
        <Teleport defer to="#shell-topbar">
            <template v-if="container">
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

                <!-- Containerns bild, i samma fyrkant som bild 2 i
                     docs/Design/mobil.png. Utan bild ritar
                     `ContainerCover` den neutrala ytan med
                     containertecknet (ADR-0047 § Beslut). -->
                <span class="h-10 w-10 shrink-0 overflow-hidden rounded-control">
                    <ContainerCover :cover="container.cover" />
                </span>

                <p class="text-title font-semibold">{{ container.name }}</p>

                <ContainerCoverSheet
                    v-if="canUpdateCover"
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
        </Teleport>

        <div>
            <!--
                Hjälten, där sidan har en (issue 170). Den ersätter
                namnraden som stod här: över `md:` är hjälten containerns
                huvud, och två rader med samma namn är både en synlig
                dubblett och en skärmläsare som läser fel.

                `hidden md:block` — under brytpunkten bär den mörka
                toppraden (skalets egen, ovanför kedjan) containerns bild och namn,
                och där ritas ingen hjälte.

                Sedan issue 680 läser hjälten sina egna tal ur den delade
                proppen `containerCounts`: skalet frågar ingenting och
                skickar ingenting vidare.
            -->
            <ContainerHero
                v-if="hero"
                class="hidden md:block"
                :hero="hero"
                :container="container"
                :can="can"
            />

            <!-- Namnraden, på varje sida utan hjälte. Under `md:` bär den
                 mörka toppraden samma namn (skalets egen, ovanför kedjan), och två
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
</template>
