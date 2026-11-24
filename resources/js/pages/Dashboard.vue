<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import ContainerCard from '../components/ContainerCard.vue';
import DashboardActivityPanel from '../components/DashboardActivityPanel.vue';
import DashboardHero from '../components/DashboardHero.vue';
import DashboardStats from '../components/DashboardStats.vue';
import DashboardTasksPanel from '../components/DashboardTasksPanel.vue';
import HeroCosts from '../components/HeroCosts.vue';
import InfoPanel from '../components/InfoPanel.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: (props) => [AppLayout, { create: props.create }] });

/*
 * Dashboarden — startsidan efter inloggning, se issue 122 och
 * docs/Design/main.jpeg. Panelen *Kommande uppgifter* är den första av M19:s
 * paneler; brickorna (124), kostnaderna (125), händelserna (126), klockan
 * (127) och informationsytan (128) kommer efter.
 *
 * **Sidan ritar paneler och äger ingenting själv.** Den här filen är
 * monteringspunkten: varje panel är sin egen komponent med sin egen propp, och
 * den här sidan skickar vidare vad servern gav den — den räknar ingenting,
 * filtrerar ingenting och formulerar ingen fråga. Det är hela skälet att M19:s
 * issues kan byggas parallellt: en ny panel krockar om en rad här och en rad i
 * App\Http\Controllers\DashboardController, inte om varandras innehåll.
 *
 * **Komponenten hette `Dashboard` redan före issue 122** — då var den todo-vyn,
 * och `/dashboard` var dess adress. Rutten och ruttnamnet står kvar därför att
 * ramverket skickar en nyinloggad användare hit (issue 64 § Beslut 1), och
 * sidnamnet är kontraktet mot `import.meta.glob` över `pages/` (issue 51).
 *
 * **Uppgifterna kommer ur samma urval som `/tasks`.** Servern klipper de fem
 * första i App\Http\Controllers\DashboardController, och panelen formulerar
 * inget eget `where` — se resources/js/components/DashboardTasksPanel.vue.
 * Sedan issue 134 följer urvalet användarens växel för framtida uppgifter, och
 * `showUpcomingTasks` skickas vidare till panelen som ritar den.
 *
 * **Brickorna och kortraderna kom med issue 124.** Gruppen är serverns:
 * `containerGroups` kommer ur App\Actions\Container\ListContainerSummaries och
 * bär en `kind` per grupp — `null` för högen, artens sträng för en art med
 * minst två containrar ([[ADR-0036 Containerns art]]). Sidan ritar rubriken
 * för högen ur `lang/` och skriver artens namn ORDAGRANT: fältet är fritt och
 * har ingen översättningsnyckel, så en sträng från användarens tangentbord får
 * aldrig slås upp (samma linje som Containers/Overview.vue).
 *
 * **Kostnaderna kom med issue 125**, som sin egen propp: `costs` bär månadens
 * totalsumma per valuta. Sedan issue 474 ritas den av `HeroCosts` — rubriken
 * *Costs*, perioden *This month* och en rad per valuta — och ingen donut.
 * Varken summeringen eller brickorna räknar något själva.
 *
 * **Händelserna kom med issue 126**, som sin egen propp: `events` bär de fem
 * senaste raderna ur händelseloggen över alla användarens konton, ur
 * App\Actions\Audit\ListAuditEvents::forUser() och
 * App\Actions\Audit\PresentAuditEvents. Panelen filtrerar ingenting själv och
 * ritar heller ingen *Visa alla* — sidan med alla händelser finns inte.
 *
 * **Informationsytan kom med issue 128**, som sin egen propp: `tips` bär
 * nycklarna på de tips användaren inte kryssat bort, i App\Support\Tips
 * ordning. Panelen är resources/js/components/InfoPanel.vue, och den står
 * också på containerns översikt — samma komponent, samma propp, samma lista
 * ([[ADR-0039 Containerns översikt]] § Konsekvenser). Servern skickar
 * nycklar och aldrig färdiga meningar; texten slås upp ur `lang/` i
 * komponenten. Den ritas överst, där mockupens exempelbanner stod, och bara
 * när listan har något kvar.
 *
 * **Rutnätet kom med issue 171** ([[ADR-0050 Desktopdesignen]] § 6) och
 * **ställdes om i issue 252**: över `lg:` ligger en översta rad med brickorna
 * till vänster och kostnadskortet till höger, och under den två kolumner som
 * var och en är en egen `flex flex-col` — containergrupperna i den vänstra,
 * uppgifterna och händelserna i den högra. Kolumnerna delar ingen radhöjd, så
 * en uppgiftspanel som växer flyttar ingenting i den vänstra kolumnen; det var
 * just det som lämnade tomrummet när barnen låg i samma rutnät och delade
 * rader (issue 252 § Beslut 2). Panelerna är M19:s, propparna är desamma och
 * servern får ingen ny fråga.
 *
 * **Under `lg:` är behållaren en flexkolumn och de två kolumnerna ritar ingen
 * egen box** (`contents`): deras barn blir syskon i flexkolumnen och ordnas
 * med `order-*` till Beslut 3:s ordning — brickorna, kostnaden, uppgifterna,
 * containrarna, händelserna och sist informationsytan. Utan `contents` hade
 * uppgifterna och containrarna följts åt i källordningen, och Beslut 3 hade
 * inte gått att uppfylla utan att bryta Beslut 2.
 *
 * **Översta raden ritas i en hjälte sedan issue 435** ([[ADR-0090
 * Dashboardens hjälte]] § 1 och 6): `<h1>` och den gamla raden med brickorna
 * och kostnadskortet är ersatta av `DashboardHero`, som ritar hälsningen,
 * rubriken, undertiteln, brickorna och kostnadssummeringen inne i personens
 * hjältebild eller standardbilden. Sedan issue 474 är summeringen `HeroCosts`
 * i stället för brickor och donut. Sidan ritar därför brickorna och
 * summeringen EN gång till under bandet, i ett block som bara syns under
 * `md:` — det är dit ADR-0090 § 6 flyttar dem på en telefon. Propparna och
 * talen är desamma som förut; hjälten får `stats` och `costs` och räknar
 * ingenting själv.
 */
const props = defineProps({
    /* Högst fem rader ur todo-urvalet, i serverns ordning. */
    tasks: { type: Array, required: true },
    /* Har användaren någon container alls? Skiljer de två tomma lägena åt. */
    hasContainers: { type: Boolean, required: true },
    /* Växelns sparade läge, vidare till uppgiftspanelen (issue 134). */
    showUpcomingTasks: { type: Boolean, required: true },
    /* `{ containers, tasks, overdue }` — talen brickorna visar. */
    stats: { type: Object, required: true },
    /* Korten, grupperade på art: `[{ kind, containers }]`. */
    containerGroups: { type: Array, required: true },
    /* Månadens kostnader: `{ totals: [{currency, amount, count}] }`. */
    costs: { type: Object, required: true },
    /* Högst fem rader ur händelseloggen, nyast först. */
    events: { type: Array, required: true },
    /* Nycklarna på de tips användaren inte dolt, i serverns ordning. */
    tips: { type: Array, required: true },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget (issue 152 ·
     * [[ADR-0048 Mobilen och plusknappen]] § 2), eller null. På den här sidan
     * skapar knappen en container; `null` när ingen av användarens konton får
     * skapa, och då ritar skalet ingen knapp.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <!--
        Den översta raden (ADR-0090 § 1): hälsningen, rubriken, undertiteln,
        brickorna och donuten ritas inne i personens hjältebild eller i
        standardbilden. Under `md:` är hjälten ett lågt band (ADR-0090 § 6),
        och då ritar sidan brickorna och donuten under den — se blocket nedan.
    -->
    <DashboardHero :stats="props.stats" :costs="props.costs" />

    <!--
        Uppställningen (issue 252). Över `lg:` är behållaren ett rutnät om
        tre kolumner. Den översta raden står utanför sedan issue 435 — den är
        hjälten — och under den står två kolumner som var och en är en egen
        `flex flex-col`. Kolumnerna delar ingen radhöjd — det var det som
        lämnade tomrummet när uppgiftspanelen växte (Beslut 2).

        Under `lg:` är behållaren en flexkolumn och de två kolumnerna ritar
        ingen egen box (`contents`), så deras barn blir syskon här och kan
        ordnas med `order-*` till Beslut 3. Utan `contents` hade
        uppgifterna och containrarna följts åt i källordningen.
    -->
    <div class="flex flex-col lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8">
        <!--
            Under `md:` står brickorna och kostnadssummeringen under
            hjältebandet (ADR-0090 § 6 och tillägget 2026-11-24). Blocket är
            dolt över `md:`, för där ritar `DashboardHero` samma saker inne i
            bilden. Source-ordningen gör att det står först i flexkolumnen,
            utan `order` (Beslut 3).
        -->
        <div class="mt-8 md:hidden">
            <DashboardStats :stats="props.stats" />

            <HeroCosts class="mt-8" :totals="props.costs.totals" />
        </div>

        <!--
            Vänster kolumn (Beslut 2): containergrupperna, en efter en.
            Kolumnen är ett eget `flex flex-col` över `lg:`, så grupperna
            staplas här och ligger inte i rutnätet — en högerkolumn som
            växer flyttar dem därför inte. Under `lg:` ritar kolumnen ingen
            box (`contents`), och grupperna bär sin egen marginal och sin
            ordning efter uppgifterna (Beslut 3).
        -->
        <div class="contents lg:col-span-2 lg:row-start-2 lg:mt-8 lg:flex lg:flex-col lg:gap-8">
            <section v-for="group in props.containerGroups" :key="group.kind ?? 'others'" class="order-2 mt-8 lg:order-none lg:mt-0">
                <h2 class="text-title font-semibold text-ink">
                    {{ group.kind ?? t('dashboard.containers.others') }}
                </h2>

                <div class="mt-3 flex flex-wrap items-stretch gap-4 lg:grid lg:grid-cols-2">
                    <ContainerCard
                        v-for="container in group.containers"
                        :key="container.ulid"
                        :container="container"
                    />
                </div>
            </section>
        </div>

        <!--
            Höger kolumn (Beslut 2): uppgifterna och därunder händelserna —
            och informationsytan sist, som förut. Marginalen sitter på
            panelerna (informationsytan äger sin egen), så kolumnen bär
            ingen `gap`: en ram runt panelen hade gjort ramen, och inte
            kolumnen, till panelens förälder. Under `lg:` ritar kolumnen
            ingen box (`contents`), och barnen bär sin ordning (Beslut 3).
        -->
        <div class="contents lg:col-start-3 lg:row-start-2 lg:mt-8 lg:flex lg:flex-col">
            <DashboardTasksPanel
                class="order-1 mt-8 lg:order-none lg:mt-0"
                :tasks="props.tasks"
                :has-containers="props.hasContainers"
                :show-upcoming-tasks="props.showUpcomingTasks"
            />

            <div class="order-3 mt-8 lg:order-none">
                <DashboardActivityPanel :events="props.events" />
            </div>

            <!--
                Informationsytan äger sin egen marginal — den ritas bara när
                det finns tips — så den får bara sin ordning här, och
                därför en egen ram: en klass utifrån kan inte ärvas av en
                rot som är villkorad.
            -->
            <div class="order-4 lg:order-none">
                <InfoPanel :tips="props.tips" />
            </div>
        </div>
    </div>
</template>
