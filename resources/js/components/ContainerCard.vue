<script setup>
import { Link } from '@inertiajs/vue3';
import ContainerCover from './ContainerCover.vue';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Ett containerkort på dashboarden, se issue 124 och docs/Design/main.jpeg.
 *
 * **Kortet visar namn, antal items och antal öppna uppgifter — och ingenting
 * mer.** Foto, undertitel och framdriftsstapel ritas inte: ingen av dem har en
 * datakälla (issue 124 § Klart när, [[ADR-0042 Designsystemet]]), och en ritad
 * stapel utan underlag är ett påstående om att något finns.
 *
 * **Båda talen räknar det användaren SJÄLV når** ([[ADR-0039 Containerns
 * översikt]] § Beslut, [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser). De
 * kommer färdigräknade ur App\Actions\Container\ListContainerSummaries —
 * omfånget löses upp i ETT anrop för alla kort genom
 * App\Actions\Access\ResolveItemScope::forContainers() — och den här filen
 * räknar ingenting. Ingen totalsumma och ingen *av N*: talet är längden på
 * användarens egen lista och avslöjar därför ingenting hon inte redan ser.
 *
 * **Namnet är länken till containerns översikt**, alltså `containers.show` —
 * samma URL som itemlistan låg på före issue 89. Adressen byggs här och inte
 * på servern, samma mönster som resources/js/pages/Containers/Index.vue.
 *
 * **Länkens klickyta är hela kortet** (fynd från testarna 2026-10-03): ett
 * klick på bilden eller talen gjorde ingenting. Ytan sträcks ut med en
 * `::after` på länken i stället för en andra länk runt kortet — en länk runt
 * bild, rubrik och tal hade lästs upp som en enda lång länktext och gett
 * kortet två tabbstopp. `UiCard` får därför `relative`, så `::after` mäter
 * mot kortets ram; attributet faller igenom till kortets rotelement.
 *
 * **Ramen är `UiCard`** (issue 99): rubriken är kortets rubrikrad och talen är
 * innehållet. Ingen egen ram och ingen egen rubriknivå.
 *
 * **Fotot kom med issue 159 · [[ADR-0047 Containerns bild]] § Beslut**, i
 * `UiCard`s `media`-slot över rubrikraden — så kortet ser ut som i
 * `docs/Design/main.jpeg`, där bilden fyller kortets överkant. Bilden är
 * containerns egen och kommer färdig i `container.cover`, ur
 * App\Actions\Container\ListContainerSummaries. Kortet väljer varken bild
 * eller storlek: `ContainerCover` fyller rutan, och rutan är kortets.
 *
 * **Även utan bild ritas ytan** (ADR-0047 § Beslut: en neutral yta med
 * ikonen, aldrig en tom ram). Kortet hade kunnat utelämna raden helt och blivit
 * lägre — men då hade två kort i samma rad olika höjd, och en container utan
 * bild sett ut som ett annat slags kort än de andra.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `dashboard.containers.*`.
 */
const props = defineProps({
    /* `{ ulid, name, items, todos, cover }` — talen är antalet inom användarens omfång. */
    container: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard class="min-w-64 flex-1 relative">
        <template #media>
            <div class="aspect-video w-full">
                <ContainerCover :cover="props.container.cover" variant="medium" />
            </div>
        </template>

        <template #heading>
            <Link
                :href="`/containers/${props.container.ulid}`"
                class="inline-flex min-h-11 items-center text-blue-700 hover:underline after:absolute after:inset-0 after:content-['']"
            >
                {{ props.container.name }}
            </Link>
        </template>

        <div class="flex flex-wrap gap-x-4 gap-y-1 text-meta text-ink-subtle">
            <span>{{ t('dashboard.containers.items', { count: props.container.items }) }}</span>
            <span>{{ t('dashboard.containers.todos', { count: props.container.todos }) }}</span>
        </div>
    </UiCard>
</template>
