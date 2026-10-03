<script setup>
import ContainerCover from './ContainerCover.vue';

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
 * **Talen bärs av den höga hjälten och kommer från sidan.** `stats`-sloten
 * ritas bara när hjälten är `large`, och den fylls av den sida som äger talen
 * (översikten, ur sina `counts`). Hjälten frågar ingenting själv: en siffra som
 * räknades här hade varit en andra väg till samma tal, och de två hade glidit
 * isär ([[ADR-0039 Containerns översikt]] § Konsekvenser).
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
 * Ingen sträng i JavaScript ([[ADR-0013 Språk och i18n]]): hjälten slår inte
 * upp en enda nyckel sedan issue 646 — texten den bar försvann med länkarna —
 * och artens värde skrivs ORDAGRANT, för fältet är fritt
 * ([[ADR-0036 Containerns art]]) och ingen nyckel byggs ur det.
 */
defineProps({
    /*
     * `large` på översikten, `compact` på de andra flikarna — ADR-0050 § 2.
     * Den här komponenten ritas bara när skalet har ett värde att ge den;
     * frånvaron av en hjälte är skalets beslut och inte hjältens.
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
            <ContainerCover :cover="container.cover" />
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

            <!-- Talen, ovanpå bilden och bara i den höga hjälten (ADR-0050
                 § 2). Den låga bär inga tal, och sloten är tom då. -->
            <div v-if="hero === 'large'" class="mt-2 flex flex-wrap gap-4">
                <slot name="stats" />
            </div>
        </div>
    </section>
</template>
