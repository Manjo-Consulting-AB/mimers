<script setup>
import UiStat from './UiStat.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboardens brickor, se issue 124 och docs/Design/main.jpeg.
 *
 * **Brickorna är två, inte mockupens fyra.** *Uppgifter* och *Underhåll* är
 * samma tabell — `schedule` skiljer dem bara åt via `recurrence_type`
 * ([[ADR-0042 Designsystemet]] § Beslut) — och den tredje brickan, kostnaden,
 * är issue 125. Här står containerbrickan och uppgiftsbrickan.
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
    </div>
</template>
