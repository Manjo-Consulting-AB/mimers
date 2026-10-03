<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ContainerCover from './ContainerCover.vue';
import UiStat from './UiStat.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns hjälte, se issue 170 · [[ADR-0050 Desktopdesignen]] § 2–3 och
 * [[ADR-0047 Containerns bild]] § Beslut.
 *
 * **Hjälten är containerns huvud över `md:`.** Den visar bilden, arten, namnet
 * och HELA `description` som undertitel. Beskrivningen delas inte upp (issue
 * 88) och kortas inte (ADR-0050 § 2); blir den för lång är det en senare fråga.
 * Är den tom utelämnas undertiteln helt — en tom rad vore ett påstående om att
 * något saknas, samma regel som översiktens huvud.
 *
 * **Bilden är `ContainerCover` och ingenting annat.** Komponenten väljer inte
 * sin egen storlek — den fyller rutan anroparen ställer den i — och ritar den
 * neutrala ytan med containertecknet när containern saknar bild (ADR-0047
 * § Beslut). Hjälten lägger den som ett lager UNDER texten i stället för att
 * bygga en andra väg till samma bild.
 *
 * **Skuggan ritas alltid, och texten är alltid vit.** Över ett foto behövs den
 * för läsbarheten; över den neutrala ytan hade vit text varit oläsbar utan
 * den. Ett villkorat lager hade tvingat fram två textfärger och två grenar för
 * samma yta, och hjälten hade sett ut som två olika ytor beroende på om
 * containern hade en bild.
 *
 * **Brickorna står i hjälten på varje flik och är länkar** (fynd från
 * testarna 2026-10-03, issue 680). Talen kommer ur den delade proppen
 * `containerCounts` — `{ items, todos }`, byggd i
 * App\Http\Middleware\HandleInertiaRequests::containerCounts() (issue 679) —
 * och hjälten läser den själv ur `page.props`. Hjälten frågar alltså ingenting
 * själv om siffrorna: en räkning här hade varit en andra väg till samma tal,
 * och de två hade glidit isär ([[ADR-0039 Containerns översikt]]
 * § Konsekvenser). Proppen är `null` utanför en container, och då ritas inga
 * brickor.
 *
 * **Brickorna ritas i båda formerna.** `hero` styr bara hjältens HÖJD
 * (`min-h-56` / `min-h-32`); den höga och den låga hjälten bär samma två tal.
 * *Items* leder till itemlistan och *Open tasks* till uppgiftsfliken — samma
 * grepp som dashboardens brickor fick i issue 654: länken bor i hjälten och
 * inte i `UiStat`, som förblir en form ([[ADR-0042 Designsystemet]]
 * § Beslut).
 *
 * **Hjälten bär ingen väg till inställningssidan** (issue 646). Här stod
 * *Redigera container* för den som får ändra (ADR-0050 § 3) och en stillsam
 * länk *Inställningar* i dess ställe för läsaren — två grenar på samma plats
 * och samma adress. Sedan issue 646 ligger *Settings* i flikraden i stället,
 * för alla som når containern: hjälten ritar alltså ingenting som pekar på
 * `/containers/{ulid}/edit`. Layouten skickar fortfarande `can`, och hjälten
 * tar emot den av det skälet, men den används inte här (se `defineProps`).
 * Flikens *Settings* är `view`-grindad som förut, och pennan på bilden i
 * skalets topprad står kvar på `can.update` — den är en annan väg till samma
 * val och bor i skalet, inte här.
 *
 * **Namnet är sidans `<h1>`.** Över `md:` är hjälten sidans huvud, och
 * översikten döljer sitt eget huvud där (se resources/js/pages/Containers/
 * Overview.vue) — rubriken ska finnas på varje bredd, och den här raden är den
 * över brytpunkten.
 *
 * Ingen sträng i JavaScript ([[ADR-0013 Språk och i18n]]): brickornas etiketter
 * kommer ur `t()` med nycklarna `container.overview.items` och
 * `container.overview.todos` — samma nycklar översiktens egna brickor bär — och
 * artens värde skrivs ORDAGRANT, för fältet är fritt
 * ([[ADR-0036 Containerns art]]) och ingen nyckel byggs ur det.
 */
defineProps({
    /*
     * `large` på översikten, `compact` på de andra flikarna — ADR-0050 § 2.
     * Sedan issue 680 styr formen bara hjältens HÖJD (`min-h-56` / `min-h-32`);
     * brickorna ritas i båda. Den här komponenten ritas bara när skalet har ett
     * värde att ge den; frånvaron av en hjälte är skalets beslut och inte
     * hjältens.
     */
    hero: { type: String, required: true },
    /* Containern, ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur.
     * Layouten skickar den fortfarande, och hjälten tar emot den för att den
     * inte ska falla igenom som ett odeklarerat attribut (issue 646); hjälten
     * använder den inte sedan issue 646 flyttade länken till flikraden.
     */
    can: { type: Object, default: null },
});

const page = usePage();

const counts = computed(() => page.props.containerCounts ?? null);

const { t } = useTranslations();
</script>

<template>
    <section
        class="relative overflow-hidden rounded-card bg-shell"
        :class="hero === 'large' ? 'min-h-56' : 'min-h-32'"
    >
        <!--
            Bilden, som ett lager under texten. `aria-hidden` för att ytan är
            pynt: containerns namn står intill, och `ContainerCover` bär redan
            `alt=""` av samma skäl (issue 68a § Beslut 8).
        -->
        <span class="absolute inset-0" aria-hidden="true">
            <ContainerCover :cover="container.cover" variant="medium" />
        </span>

        <span class="absolute inset-0 bg-shell/70" aria-hidden="true"></span>

        <div class="relative flex flex-col gap-4 p-6 text-ink-on-accent">
            <div class="flex items-start gap-4">
                <div class="min-w-0 flex-1">
                    <!-- Arten är ett fritt textfält och skrivs ordagrant. -->
                    <p v-if="container.kind" class="text-meta">{{ container.kind }}</p>

                    <!--
                        Namnet är sidans `<h1>`: över `md:` är hjälten
                        containerns huvud, och de sidor som ritar den döljer
                        sitt eget.
                    -->
                    <h1 class="text-heading font-semibold">{{ container.name }}</h1>

                    <!--
                        Hela beskrivningen, radbrytningarna bevarade och inget
                        avkortande: `whitespace-pre-line` är samma form som
                        översiktens huvud bar (issue 88).
                    -->
                    <p
                        v-if="container.description"
                        class="mt-1 whitespace-pre-line text-body"
                    >
                        {{ container.description }}
                    </p>
                </div>
            </div>

            <!-- Brickorna, ovanpå bilden och i båda formerna (issue 680).
                 Talen kommer ur den delade proppen `containerCounts`, och varje
                 bricka är en länk — samma grepp som dashboardens brickor
                 (issue 654): *Items* till itemlistan, *Open tasks* till
                 uppgiftsfliken. -->
            <div v-if="counts" class="mt-2 flex flex-wrap gap-4">
                <Link
                    :href="`/containers/${container.ulid}/items`"
                    class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <UiStat :value="counts.items" :label="t('container.overview.items')" />
                </Link>

                <Link
                    :href="`/containers/${container.ulid}/tasks`"
                    class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <UiStat :value="counts.todos" :label="t('container.overview.todos')" />
                </Link>
            </div>
        </div>
    </section>
</template>
