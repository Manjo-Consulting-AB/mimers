<script setup>
import { Link } from '@inertiajs/vue3';
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
 * **Ramen är `UiCard`** (issue 99): rubriken är kortets rubrikrad och talen är
 * innehållet. Ingen egen ram och ingen egen rubriknivå.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `dashboard.containers.*`.
 */
const props = defineProps({
    /* `{ ulid, name, items, todos }` — talen är antalet inom användarens omfång. */
    container: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard class="min-w-64 flex-1">
        <template #heading>
            <Link
                :href="`/containers/${props.container.ulid}`"
                class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
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
