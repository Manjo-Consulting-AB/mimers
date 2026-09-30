<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import ContainerCard from '../components/ContainerCard.vue';
import CostDonut from '../components/CostDonut.vue';
import DashboardActivityPanel from '../components/DashboardActivityPanel.vue';
import DashboardStats from '../components/DashboardStats.vue';
import DashboardTasksPanel from '../components/DashboardTasksPanel.vue';
import InfoPanel from '../components/InfoPanel.vue';
import { useTranslations } from '../composables/useTranslations.js';

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
 * totalsumma per valuta och nedbrytningen per container. Brickan får bara
 * summorna och donuten båda, och ingen av dem räknar något själv. Panelen ritas
 * bara när månaden har någon kostnadsrad alls — en rubrik över en tom ring är
 * en yta som påstår att det finns något att visa.
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
 * **Rutnätet kom med issue 171** ([[ADR-0050 Desktopdesignen]] § 6): över
 * `lg:` står brickorna, kortraderna och kostnaderna i en vänsterdel och
 * uppgifterna, händelserna och informationsytan i en högerspalt. Det är hela
 * ändringen — panelerna är M19:s, propparna är desamma och servern får ingen
 * ny fråga.
 *
 * **Rutnätet är platt och ordningen är källans.** Panelerna ligger kvar i
 * markupen i samma ordning som före issue 171: under `lg:` är behållaren ett
 * vanligt block och staplar dem som förut, så mobilens startsida flyttar sig
 * inte för att skrivbordet fick ett rutnät. Placeringen sker med klasser —
 * högerspalten är tredje kolumnen med en uttalad rad per panel, vänsterdelens
 * block spänner två kolumner och hamnar i de lediga raderna.
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
    /* Månadens kostnader: `{ totals: [{currency, amount, count}], breakdown: [...] }`. */
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
    <AppLayout :create="create">
        <Head :title="t('dashboard.title')" />

        <h1 class="text-2xl font-semibold">{{ t('dashboard.heading') }}</h1>

        <!--
            Rutnätet (issue 171). Behållaren är ett vanligt block tills `lg:`
            gör den till en grid om tre kolumner: barnen ligger kvar i
            källordningen, och under `lg:` staplas de precis som förut.
            Marginalen sitter kvar på varje panel — rutnätet lägger bara till
            kolumnavståndet, så radrytmen är den samma på båda sidor om
            brytpunkten.
        -->
        <div class="lg:grid lg:grid-cols-3 lg:items-start lg:gap-x-8">
            <!--
                Informationsytan äger sin egen marginal (se InfoPanel.vue), så
                ramen bär ingen: den placerar panelen sist i högerspalten och
                lämnar ingenting kvar när panelen inte ritas — en tom ram har
                ingen höjd.
            -->
            <div class="lg:col-start-3 lg:row-start-3">
                <InfoPanel :tips="props.tips" />
            </div>

            <div class="mt-8 lg:col-span-2">
                <DashboardStats :stats="props.stats" :costs="props.costs.totals" />
            </div>

            <section v-for="group in props.containerGroups" :key="group.kind ?? 'others'" class="mt-8 lg:col-span-2">
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

            <section v-if="props.costs.totals.length" class="mt-8 lg:col-span-2">
                <h2 class="text-title font-semibold text-ink">
                    {{ t('dashboard.costs.heading') }}
                </h2>

                <div class="mt-3">
                    <CostDonut :totals="props.costs.totals" :breakdown="props.costs.breakdown" />
                </div>
            </section>

            <div class="mt-8 lg:col-start-3 lg:row-start-1">
                <DashboardTasksPanel
                    :tasks="props.tasks"
                    :has-containers="props.hasContainers"
                    :show-upcoming-tasks="props.showUpcomingTasks"
                />
            </div>

            <div class="mt-8 lg:col-start-3 lg:row-start-2">
                <DashboardActivityPanel :events="props.events" />
            </div>
        </div>
    </AppLayout>
</template>
