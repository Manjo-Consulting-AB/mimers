<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ContainerCover from './ContainerCover.vue';
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
 * **Talen bärs av den höga hjälten och kommer från sidan.** `stats`-sloten
 * ritas bara när hjälten är `large`, och den fylls av den sida som äger talen
 * (översikten, ur sina `counts`). Hjälten frågar ingenting själv: en siffra som
 * räknades här hade varit en andra väg till samma tal, och de två hade glidit
 * isär ([[ADR-0039 Containerns översikt]] § Konsekvenser).
 *
 * **`Redigera container` leder till inställningssidan** (ADR-0050 § 3), där
 * containerns sju övriga sektioner bor sedan *Inställningar* lämnade flikraden.
 * Knappen ritas bara när `can.update` är sann — flaggan är presentation, och
 * rutten prövar `ContainerPolicy::update` på nytt (ADR-0047 § Beslut, "Vem som
 * får göra vad"). Pennan på bilden i skalets topprad står kvar på samma villkor
 * och är en annan väg till samma val.
 *
 * Ingen sträng i JavaScript ([[ADR-0013 Språk och i18n]]): knappens ord kommer
 * ur `t()`, och artens värde skrivs ORDAGRANT — fältet är fritt
 * ([[ADR-0036 Containerns art]]), så ingen nyckel byggs ur det.
 */
const props = defineProps({
    /*
     * `large` på översikten, `compact` på de andra flikarna — ADR-0050 § 2.
     * Den här komponenten ritas bara när skalet har ett värde att ge den;
     * frånvaron av en hjälte är skalets beslut och inte hjältens.
     */
    hero: { type: String, required: true },
    /* Containern, ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /* `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur. */
    can: { type: Object, default: null },
});

const { t } = useTranslations();

const canUpdate = computed(() => props.can?.update === true);
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

                    <p class="text-heading font-semibold">{{ container.name }}</p>

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

                <Link
                    v-if="canUpdate"
                    :href="`/containers/${container.ulid}/edit`"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-control border border-border bg-surface px-4 font-medium text-ink outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {{ t('container.hero.edit') }}
                </Link>
            </div>

            <!-- Talen, ovanpå bilden och bara i den höga hjälten (ADR-0050
                 § 2). Den låga bär inga tal, och sloten är tom då. -->
            <div v-if="hero === 'large'" class="mt-2 flex flex-wrap gap-4">
                <slot name="stats" />
            </div>
        </div>
    </section>
</template>
