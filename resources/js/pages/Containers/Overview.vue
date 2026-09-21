<script setup>
import { Head } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
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
 * bygger ingen egen väg till samma tal.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `container.overview.*`.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /* `{ items, todos }` — antalet items respektive öppna uppgifter inom omfånget. */
    counts: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="container.name" />

        <h1 class="text-2xl font-semibold">{{ container.name }}</h1>

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

        <dl class="mt-8 flex flex-wrap gap-4">
            <div class="min-w-32 rounded border border-slate-200 px-4 py-3">
                <dt class="text-sm text-slate-600">{{ t('container.overview.items') }}</dt>
                <dd class="text-2xl font-semibold">{{ counts.items }}</dd>
            </div>

            <div class="min-w-32 rounded border border-slate-200 px-4 py-3">
                <dt class="text-sm text-slate-600">{{ t('container.overview.todos') }}</dt>
                <dd class="text-2xl font-semibold">{{ counts.todos }}</dd>
            </div>
        </dl>
    </ContainerLayout>
</template>
