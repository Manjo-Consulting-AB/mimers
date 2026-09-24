<script setup>
import UiStat from './UiStat.vue';
import { formatAmount } from './CostDonut.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboardens brickor, se issue 124 och docs/Design/main.jpeg.
 *
 * **Brickorna är tre, inte mockupens fyra.** *Uppgifter* och *Underhåll* är
 * samma tabell — `schedule` skiljer dem bara åt via `recurrence_type`
 * ([[ADR-0042 Designsystemet]] § Beslut). Här står containerbrickan,
 * uppgiftsbrickan och kostnadsbrickan.
 *
 * **Kostnadsbrickan kom med issue 125** och ritas en gång PER VALUTA: en
 * månad med två valutor visar två belopp och aldrig en summa över dem
 * ([[ADR-0040 Underträdets summor]] § Konsekvenser). Beloppet är serverns
 * heltal i minsta valutaenhet, och `formatAmount` bor i CostDonut.vue för att
 * brickan och donuten ska visa samma tal med samma formatering — den hade
 * annars varit en andra sanning om vad ett belopp är. Underraden är månadens
 * namn, och månaden är serverns: sidan tar ingen parameter och en period i
 * querysträngen läses inte ([[ADR-0038 Gränsen för Pro i kostnaderna]]).
 *
 * **Komponenten räknar ingenting.** Talen kommer färdigräknade ur `stats`, som
 * App\Actions\Container\ListContainerSummaries fyllde ur samma svar som
 * `/tasks` ritar. Uppgiftstalet är därför antalet rader i `scopeTodoFor()` per
 * konstruktion, och den försenade underraden är samma gruppering som
 * todo-vyns — serverns datum, aldrig klientens klocka.
 *
 * **Brickan är `UiStat`**, som containerns översikt använder den (issue 99):
 * ett tal och ett ord, ingen egen räkning och ingen form på nytt. UiStat bär
 * två rader och ingen underrad, så den försenade raden står BREDVID tutan och
 * inte inuti den — UiStat ligger utanför den här issuen ruta, och en tredje
 * rad i en delad yta är ett beslut om designsystemet och inte om dashboarden.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `dashboard.stats.*`.
 */
const props = defineProps({
    /* `{ containers, tasks, overdue }` — talen brickorna visar. */
    stats: { type: Object, required: true },
    /* Månadens summa per valuta: `[{currency, amount, count}]`. */
    costs: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <div class="flex flex-wrap items-start gap-4">
        <UiStat :value="props.stats.containers" :label="t('dashboard.stats.containers')" />

        <div class="flex flex-col gap-1">
            <UiStat :value="props.stats.tasks" :label="t('dashboard.stats.tasks')" />

            <p class="text-meta text-danger">
                {{ t('dashboard.stats.overdue', { count: props.stats.overdue }) }}
            </p>
        </div>

        <!--
            Kostnaden. Ingen bricka alls för en månad utan kostnadsrader: det
            finns inget belopp att visa, och en nolla utan valuta hade varit
            ett påstående om något servern inte sade.
        -->
        <div v-for="cost in props.costs" :key="cost.currency" class="flex flex-col gap-1">
            <UiStat
                :value="formatAmount(cost.amount, cost.currency)"
                :label="t('dashboard.stats.costs')"
            />

            <p class="text-meta text-ink-subtle">{{ t('dashboard.costs.month') }}</p>
        </div>
    </div>
</template>
