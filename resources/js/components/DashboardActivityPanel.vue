<script setup>
import UiCard from './UiCard.vue';
import HistoryRow from './HistoryRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Händelsepanelen på dashboarden, se issue 126 och [[ADR-0043 Tre loggar]]
 * § Konsekvenser.
 *
 * **Panelen filtrerar inte.** Raderna kommer färdiga i `events`, ur
 * App\Actions\Audit\ListAuditEvents::forUser() med en femma som gräns — samma
 * läsregel som historikflikarna (issue 116) och `/api` läser genom, men över
 * ALLA användarens konton. Formen kommer ur
 * App\Actions\Audit\PresentAuditEvents. Ett eget `where` här hade varit en
 * andra formulering av läsregeln ([[ADR-0024 Tunna controllers och
 * actions]]), och den hade glidit isär från den som redan finns — en gäst
 * hade kunnat se en rad hon inte får läsa, eller mist sin egen.
 *
 * **Raden är `HistoryRow`** (issue 116) och inte en kopia av den. Panelen
 * skickar `show-container`: raden står utanför sin container här och måste
 * säga vilken den gäller, medan historikflikarna låter bli — där är
 * containern given av sidan (issue 126).
 *
 * **Ingen *Visa alla*.** Sidan med alla händelser finns inte, och en länk dit
 * hade varit en länk till ingenting. Panelen är hela ytan för händelser.
 *
 * **Ramen är `UiCard`** (issue 99), som uppgiftspanelen: rubriken är kortets
 * rubrikrad, och panelen ritar ingen egen ram.
 *
 * **Det tomma läget är en mening och inte en tom panel.** Att ingenting har
 * hänt är ett svar och inte ett fel — samma linje som historikflikens
 * tomtillstånd, men dess ord (*här*) hör till containern och lånas därför
 * inte: panelen säger sitt eget.
 */
const props = defineProps({
    /* Högst fem rader ur händelseloggen, nyast först. */
    events: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('dashboard.activity.heading') }}</template>

        <p v-if="props.events.length === 0" class="text-slate-700">
            {{ t('dashboard.activity.empty') }}
        </p>

        <!--
            Listan är en <ul> och raderna är <li> — formen `UiListRow` kräver,
            och av samma skäl som i historikfliken: en skärmläsare ska höra hur
            många rader det finns innan den läser den första.
        -->
        <ul v-else class="flex flex-col divide-y divide-slate-200">
            <HistoryRow
                v-for="event in props.events"
                :key="event.ulid"
                :row="event"
                show-container
            />
        </ul>
    </UiCard>
</template>
