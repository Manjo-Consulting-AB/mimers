<script setup>
import { Head } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import InfoPanel from '../../components/InfoPanel.vue';
import UiStat from '../../components/UiStat.vue';
import { useTranslations } from '../../composables/useTranslations.js';

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
 * ingenting: itembrickan är `ListItems` — samma Action som itemsidan ritar sin
 * lista ur — och är därför lika lång som listan per konstruktion, medan
 * uppgiftsbrickan är `ScheduleOccurrence::scopeTodoFor()` avgränsat till
 * containern. Ingen totalsumma, ingen *av N*, ingen rad om att något dolts:
 * ett sådant tal är precis vad omfånget stänger ute.
 *
 * **Uppgifter och underhåll är EN bricka.** `schedule` har inget fält som
 * skiljer dem åt och får inte ett — skillnaden är domänen ([[ADR-0033
 * Produktens omfång]]), och mockupens två brickor är en teckning och inte ett
 * krav.
 *
 * Kostnadsbrickan är inte här: den är issue 86:s ändpunkt, och den här sidan
 * bygger ingen egen väg till samma tal. Talen ritas av `UiStat` (issue 99),
 * som är en form och ingen räknare — den får `counts` rakt igenom och ställer
 * ingen fråga själv. Det är därför bildens fyra rutor är två här: uppgifter
 * och underhåll är EN, och kostnaden har ingen källa i den här kontrollern.
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
 */
const props = defineProps({
    container: { type: Object, required: true },
    /* `{ items, todos }` — antalet items respektive öppna uppgifter inom omfånget. */
    counts: { type: Object, required: true },
    /* Nycklarna på de tips användaren inte dolt, i serverns ordning. */
    tips: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="container.name" />

        <h1 class="text-2xl font-semibold">{{ container.name }}</h1>

        <!-- Panelen äger sin egen marginal: när alla tips är dolda ritas
             ingenting alls, och en ram runt den hade lämnat kvar sin luft. -->
        <InfoPanel :tips="props.tips" />

        <dl class="mt-2 flex flex-col gap-1 text-slate-700">
            <div v-if="container.kind" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.kind') }}</dt>
                <dd>{{ container.kind }}</dd>
            </div>

            <div v-if="container.description" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.description') }}</dt>
                <dd class="whitespace-pre-line">{{ container.description }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-4">
            <UiStat :value="counts.items" :label="t('container.overview.items')" />
            <UiStat :value="counts.todos" :label="t('container.overview.todos')" />
        </div>
    </ContainerLayout>
</template>
