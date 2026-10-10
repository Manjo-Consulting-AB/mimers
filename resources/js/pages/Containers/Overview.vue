<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerDetailsPanel from '../../components/ContainerDetailsPanel.vue';
import ContainerTasksPanel from '../../components/ContainerTasksPanel.vue';
import CostDonut from '../../components/CostDonut.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import InfoPanel from '../../components/InfoPanel.vue';
import RecentImagesPanel from '../../components/RecentImagesPanel.vue';
import UiCard from '../../components/UiCard.vue';
import UiListRow from '../../components/UiListRow.vue';
import UiStat from '../../components/UiStat.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, { create: props.create }],
        [ContainerLayout, { hero: 'large', container: props.container, can: props.can }],
    ],
});

/*
 * Containerns översikt — containerns egen sida, se issue 89 ·
 * [[ADR-0039 Containerns översikt]].
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource. Fram till issue 89 var itemlistan
 * den här sidan; nu ligger den på `/containers/{ulid}/items` och ritas av
 * pages/Containers/Items/Index.vue.
 *
 * **Huvudet är namn, art och beskrivning.** Arten skrivs ut ORDAGRANT — fältet
 * är fritt ([[ADR-0036 Containerns art]]), så ingen översättningsnyckel byggs
 * ur värdet: `t()` returnerar nyckeln själv när uppslaget misslyckas, och en
 * nyckel byggd ur strängen hade skrivit `container.overview.kind.Segelbåt` på
 * skärmen första gången någon skrev en egen art. Etiketten är `Kind` och inte
 * mockupens *Kategori*, som är upptaget av kategoriträdet på items — ett ord,
 * en betydelse ([[ADR-0032 Produktens ord]]).
 *
 * Beskrivningen ritas som den är skriven. Ingen formatering, ingen tolkning,
 * ingen uppdelning i delar: fältet är en fritext och inte en samling
 * strukturerade fakta (issue 88 · [[ADR-0039 Containerns översikt]] § Beslut).
 * Är den tom utelämnas raden — en tom etikett vore ett påstående om att något
 * saknas.
 *
 * **Brickorna räknar det användaren SJÄLV når** ([[ADR-0028 Åtkomst på
 * itemnivå]] § Konsekvenser, issue 73 § Beslut 6). Talen kommer färdigräknade
 * ur App\Http\Controllers\ContainerController::show(), och vyn räknar
 * ingenting: itembrickan är `ListItems` — samma Action som itemsidan och
 * itempanelen ritar ur — och är därför lika lång som listan per konstruktion,
 * medan uppgiftsbrickan är samma `ListTodo::forContainer()` som
 * uppgiftspanelen. Ingen totalsumma, ingen *av N*, ingen rad om att något
 * dolts: ett sådant tal är precis vad omfånget stänger ute.
 *
 * **Uppgifter och underhåll är EN bricka.** `schedule` har inget fält som
 * skiljer dem åt och får inte ett — skillnaden är domänen ([[ADR-0033
 * Produktens omfång]]), och mockupens två brickor är en teckning och inte ett
 * krav.
 *
 * Talen ritas av `UiStat` (issue 99), som är en form och ingen räknare — den
 * får `counts` rakt igenom och ställer ingen fråga själv.
 *
 * **Informationsytan kom med issue 128**, som sin egen propp: `tips` bär
 * samma lista som dashboarden bär, ur samma App\Support\Tips. Komponenten är
 * den samma — `InfoPanel.vue` ser identisk ut på båda ytorna, och det är
 * avsiktligt: [[ADR-0039 Containerns översikt]] § Konsekvenser säger att
 * informationsrutan är EN ruta, och en andra upplaga här hade kunnat visa
 * ett annat första tips. Texten kommer ur `tips.*` i `lang/`, alltså samma
 * nycklar som på dashboarden och inte `container.overview.*`: tipsen handlar
 * inte om containern man står i.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `container.overview.*` eller
 * `tips.*`.
 *
 * **Hjälten kom med issue 170 · [[ADR-0050 Desktopdesignen]] § 2–3**, och
 * översikten är den sida som skickar `hero="large"`: bilden, arten, namnet och
 * hela beskrivningen står ovanpå containerns bild över `md:`. Sedan issue 680
 * läser hjälten sina egna brickor ur den delade proppen `containerCounts`; hit
 * hör bara vilken form sidan vill ha.
 *
 * **Sidans eget huvud ritas bara under `md:`.** Över brytpunkten bär hjälten
 * namnet, arten och beskrivningen, och samma regel som gäller namnraden i skalet
 * gäller här: två rader med samma text är en synlig dubblett och en skärmläsare
 * som läser fel. Varje rad i `<dl>` är ett fält hjälten upprepar; skulle ett
 * fält tillkomma som hjälten inte bär stannar det synligt på båda bredderna.
 * Under `md:` är huvudet oförändrat — där ritas ingen hjälte.
 *
 * **Talen ritas på två ställen.** Över `md:` bär hjälten dem ur den delade
 * proppen `containerCounts` (issue 680), och under `md:` — där hjälten inte
 * ritas alls — står de kvar i sidans flöde ur `counts` som förut. Att bara
 * behålla hjältens upplaga hade tyst tagit bort de två brickorna från en
 * telefon. Båda är samma siffror ur samma källa på servern (issue 679), och
 * ingen av raderna räknar något själv.
 *
 * **Panelerna kom med issue 172 · [[ADR-0050 Desktopdesignen]] § 7.** Sidan är
 * monteringspunkten och äger ingenting själv: varje panel har sin egen propp
 * och ritar vad servern gav den — vyn räknar ingenting, filtrerar ingenting
 * och formulerar ingen fråga.
 *
 * - *Kommande uppgifter* och *Items* är egna komponenter respektive egna
 *   rader, och båda länkar vidare: uppgifterna till `/tasks` till dess att
 *   containerns uppgiftsflik finns (issue 174), itemen till itemlistan.
 * - *Kostnader* ritas av `CostDonut` — samma komponent som dashboardens — och
 *   panelen ritas inte alls när containern saknar kostnadsrader: en rubrik över
 *   en tom ring är en yta som påstår att det finns något att visa.
 * - *Senaste aktiviteter* ritar `HistoryRow`, och den ber INTE om
 *   containerraden: raderna står inuti sin container här, till skillnad från
 *   dashboardens panel, och ett upprepat containernamn hade sagt samma sak två
 *   gånger (issue 116 § Beslut, issue 126).
 * - *Containerdetaljer* är sin egen komponent och en definitionslista.
 * - *Senaste bilder* kom med issue 173 och är sin egen komponent. Panelen
 *   ritas inte alls när containern saknar bilder — samma regel som
 *   kostnadspanelen, och av samma skäl: en rubrik över ingenting påstår att
 *   det finns något att visa.
 *
 * **Rutnätet följer bilden över `lg:` och staplar panelerna under den.**
 * Behållaren är ett vanligt block tills `lg:` gör den till ett rutnät om tre
 * kolumner; barnen ligger kvar i källordningen, så mobilens sida flyttar sig
 * inte för att skrivbordet fick ett rutnät. Placeringen sker med klasser —
 * samma grepp och samma skäl som dashboardens rutnät (issue 171) — och
 * marginalen sitter kvar på varje panel, så radrytmen är den samma på båda
 * sidor om brytpunkten.
 *
 * Rutnätet är fullt: uppgifterna, kostnaderna och detaljerna på första raden,
 * itemen över två kolumner med aktiviteterna i den tredje, och
 * informationsytan sist i högerspalten — där bilden har den. *Senaste bilder*
 * (issue 173) flyttade in i den tredje radens lediga två kolumner, bredvid
 * informationsytan, och ingen panel flyttade för den: rutnätet hade platsen
 * kvar, och en omflyttning av panelerna hade varit en ändring av issue 172:s
 * yta som den här issuen inte har bett om.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur.
     * Här styr den pennan på bilden i skalets topprad (issue 159 ·
     * [[ADR-0047 Containerns bild]] § Beslut): den som bara får läsa ser
     * varken penna eller ark. Flaggan är presentation; rutten prövar
     * `ContainerPolicy::update` på nytt.
     */
    can: { type: Object, required: true },
    /* `{ items, todos }` — antalet items respektive öppna uppgifter inom omfånget. */
    counts: { type: Object, required: true },
    /* Högst fem rader ur containerns todo-urval, i serverns ordning (issue 172). */
    tasks: { type: Array, required: true },
    /* Containerns fasta summering: `{ totals, breakdown }` (issue 172). */
    costs: { type: Object, required: true },
    /* Högst sex items användaren når, ur samma ListItems-svar som `counts.items` (issue 172). */
    items: { type: Array, required: true },
    /* Högst fem rader ur containerns händelselogg, nyast först (issue 172). */
    events: { type: Array, required: true },
    /*
     * Högst fem bilder på containerns items, nyast först (issue 173):
     * `{ulid, filename, hasThumb, item: {ulid, name}}`. Tom när containern
     * saknar bilder — och då ritas panelen inte alls.
     */
    recentImages: { type: Array, required: true },
    /* `{ kind, currency, account, created_at }` — containerns egna fakta (issue 172). */
    details: { type: Object, required: true },
    /* Nycklarna på de tips användaren inte dolt, i serverns ordning. */
    tips: { type: Array, required: true },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget (issue 152 ·
     * [[ADR-0048 Mobilen och plusknappen]] § 2), eller null. Här skapar
     * knappen ett item i containern, med `?parent` när man står på en nod.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();

const itemListUrl = computed(() => `/containers/${props.container.ulid}/items`);

const itemUrl = (item) => `/containers/${props.container.ulid}/items/${item.ulid}`;
</script>

<template>
    <Head :title="container.name" />

    <!--
        Rutnätet (issue 172). Behållaren är ett vanligt block tills `lg:`
        gör den till ett rutnät om tre kolumner; barnen ligger kvar i
        källordningen, och under `lg:` staplas de precis som förut.
        Marginalen sitter kvar på varje panel — rutnätet lägger bara till
        kolumnavståndet, så radrytmen är den samma på båda sidor om
        brytpunkten.
    -->
    <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8">
        <!--
            Sidans eget huvud ritas bara under `md:`. Över brytpunkten är
            hjälten sidans huvud (ADR-0050 § 2), och den bär samma namn, art
            och beskrivning — två rader med samma text är både en synlig
            dubblett och en skärmläsare som läser fel. Namnet är hjältens
            `<h1>` där, så sidan har en rubrik på varje bredd.
        -->
        <h1 class="text-2xl font-semibold md:hidden">{{ container.name }}</h1>

        <!--
            Informationsytan äger sin egen marginal (se InfoPanel.vue), så
            ramen bär ingen: den placerar panelen sist i högerspalten och
            lämnar ingenting kvar när panelen inte ritas — en tom ram har
            ingen höjd.
        -->
        <div class="lg:col-start-3 lg:row-start-3">
            <InfoPanel :tips="props.tips" />
        </div>

        <!-- Varje rad i listan är ett fält hjälten upprepar — arten och
             beskrivningen — så hela listan hör till huvudet under `md:`. -->
        <dl class="mt-2 flex flex-col gap-1 text-slate-700 md:hidden">
            <div v-if="container.kind" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.kind') }}</dt>
                <dd>{{ container.kind }}</dd>
            </div>

            <div v-if="container.description" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.description') }}</dt>
                <dd class="whitespace-pre-line">{{ container.description }}</dd>
            </div>
        </dl>

        <!-- Talen i sidans flöde under `md:`, där hjälten inte ritas. Samma
             två tal ur samma `counts` — ingen tredje bricka och ingen egen
             räkning. -->
        <div class="mt-8 flex flex-wrap gap-4 md:hidden">
            <UiStat :value="counts.items" :label="t('container.overview.items')" />
            <UiStat :value="counts.todos" :label="t('container.overview.todos')" />
        </div>

        <!-- Kommande uppgifter. Panelen äger sin egen ram och sitt eget
             tomma läge; här står bara var i rutnätet den hör hemma. -->
        <div class="mt-8 lg:col-start-1 lg:row-start-1">
            <ContainerTasksPanel :tasks="props.tasks" />
        </div>

        <!-- Kostnaderna. Panelen ritas inte alls när containern saknar
             kostnadsrader — en rubrik över en tom ring är ett påstående om
             att det finns något att visa. Bildtexten i ringens mitt säger
             *Total* och inte *This month*: den fasta summeringen är hela
             containern och har ingen period (ADR-0038). -->
        <div v-if="props.costs.totals.length" class="mt-8 lg:col-start-2 lg:row-start-1">
            <UiCard>
                <template #heading>{{ t('container.overview.costs') }}</template>

                <CostDonut
                    :totals="props.costs.totals"
                    :breakdown="props.costs.breakdown"
                    :label="t('container.overview.costs_total')"
                />
            </UiCard>
        </div>

        <!-- Containerdetaljer. -->
        <div class="mt-8 lg:col-start-3 lg:row-start-1">
            <ContainerDetailsPanel :details="props.details" />
        </div>

        <!-- Items. Samma lista som itembrickan räknar, klippt till sex av
             servern; raden leder till itemet och panelen vidare till hela
             listan. -->
        <div class="mt-8 lg:col-span-2 lg:col-start-1 lg:row-start-2">
            <UiCard>
                <template #heading>{{ t('container.overview.items') }}</template>

                <template #action>
                    <Link
                        :href="itemListUrl"
                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                    >
                        {{ t('container.overview.view_all') }}
                    </Link>
                </template>

                <p v-if="props.items.length === 0" class="text-slate-700">
                    {{ t('item.index.empty') }}
                </p>

                <!-- Listan är en <ul> och raderna är <li> — formen
                     `UiListRow` kräver, och av samma skäl som i
                     itemlistan: en skärmläsare ska höra hur många rader
                     det finns innan den läser den första. -->
                <ul v-else class="flex flex-col divide-y divide-slate-200">
                    <UiListRow v-for="item in props.items" :key="item.ulid">
                        <template #title>
                            <Link
                                :href="itemUrl(item)"
                                class="inline-flex min-h-11 items-center hover:underline"
                            >
                                {{ item.name }}
                            </Link>
                        </template>
                    </UiListRow>
                </ul>
            </UiCard>
        </div>

        <!-- Senaste aktiviteter. Raden är `HistoryRow` och ber INTE om
             containerraden: raderna står inuti sin container här, till
             skillnad från dashboardens panel (issue 126). Det tomma läget
             är historikflikens eget ord — samma container, samma svar. -->
        <div class="mt-8 lg:col-start-3 lg:row-start-2">
            <UiCard>
                <template #heading>{{ t('container.overview.activity') }}</template>

                <p v-if="props.events.length === 0" class="text-slate-700">
                    {{ t('audit.history.empty') }}
                </p>

                <ul v-else class="flex flex-col divide-y divide-slate-200">
                    <HistoryRow
                        v-for="event in props.events"
                        :key="event.ulid"
                        :row="event"
                    />
                </ul>
            </UiCard>
        </div>

        <!-- Senaste bilder (issue 173). Panelen ritas inte alls när
             containern saknar bilder, och den ritas av sin egen komponent:
             sidan äger bara platsen i rutnätet. Bilderna är länkar till
             sina items, och miniatyren kräver att servern sagt att
             `thumb`-varianten finns. -->
        <div
            v-if="props.recentImages.length"
            class="mt-8 lg:col-span-2 lg:col-start-1 lg:row-start-3"
        >
            <RecentImagesPanel
                :images="props.recentImages"
                :container-ulid="props.container.ulid"
            />
        </div>
    </div>
</template>
