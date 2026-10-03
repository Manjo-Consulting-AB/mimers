<script setup>
import UiCard from './UiCard.vue';
import HistoryRow from './HistoryRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Händelsepanelen på itemets översikt, se issue 213 och [[ADR-0043 Tre loggar]]
 * § Händelseloggen.
 *
 * **Panelen filtrerar inte.** Raderna kommer färdiga i `events`, ur
 * App\Actions\Audit\ListAuditEvents::forItem() med en femma som gräns — samma
 * läsregel som historikflikarna (issue 116) läser genom, men klippt i anropets
 * ände. Formen kommer ur App\Actions\Audit\PresentAuditEvents. Ett eget `where`
 * här hade varit en andra formulering av läsregeln ([[ADR-0024 Tunna
 * controllers och actions]]), och den hade glidit isär från den som finns — en
 * gäst hade kunnat se en rad hon inte får läsa, eller mist sin egen.
 *
 * **Raden är `HistoryRow`** (issue 116) och inte en kopia av den. Panelen ber
 * INTE om `show-container`: historikflikarnas rad står inuti sin container och
 * säger därför inte vilken den gäller — och översikten är samma yta som de,
 * given av sidan man står på (issue 213). Det är skillnaden mot
 * DashboardActivityPanel, som visar rader över alla konton och därför måste
 * namnge containern.
 *
 * **Ingen *Visa alla*.** Historikfliken finns och panelen kunde peka på den,
 * men länken är ingen issue: panelen är hela sin yta tills någon beslutar
 * annat.
 *
 * **Ramen är `UiCard`** (issue 99), som containerns aktivitetspanel och
 * uppgiftspanelen: rubriken är kortets rubrikrad, och panelen ritar ingen egen
 * ram.
 *
 * **Det tomma läget är en mening och inte en tom panel.** Att ingenting har
 * hänt är ett svar och inte ett fel, och orden är historikflikens — samma
 * fråga, samma svar: vad har hänt med itemet.
 */
const props = defineProps({
    /* Högst fem rader ur händelseloggen, nyast först. */
    events: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('item.show.activity') }}</template>

        <p v-if="props.events.length === 0" class="text-slate-700">
            {{ t('audit.history.empty') }}
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
            />
        </ul>
    </UiCard>
</template>
