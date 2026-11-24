<script setup>
import { Link } from '@inertiajs/vue3';
import UiStat from './UiStat.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dashboardens brickor, se issue 124 och docs/Design/main.jpeg.
 *
 * **Brickorna är två, inte mockupens fyra.** *Uppgifter* och *Underhåll* är
 * samma tabell — `schedule` skiljer dem bara åt via `recurrence_type`
 * ([[ADR-0042 Designsystemet]] § Beslut). Här står containerbrickan och
 * uppgiftsbrickan.
 *
 * **Kostnadsbrickan lämnade raden med issue 474** ([[ADR-0090 Dashboardens
 * hjälte]] § Tillägg 2026-11-24): månadens belopp ritas av `HeroCosts`, med
 * valutan utskriven och utan donut. Den här komponenten räknar därför inga
 * pengar alls och tar ingen `costs`-propp — talen den visar är containrar och
 * uppgifter.
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
 * **Brickorna är genvägar** (fynd från testarna 2026-10-03, issue 654):
 * containerbrickan leder till `/containers` och uppgiftsbrickan till `/tasks` —
 * samma lista som talet är hämtat ur. Länken bor här och inte i UiStat, så att
 * containerns översikt kan använda samma tuta olänkad. Den röda *Overdue*-raden
 * står kvar utanför länken: den är en underrad till talet, inte en egen ingång.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `dashboard.stats.*`.
 */
const props = defineProps({
    /* `{ containers, tasks, overdue }` — talen brickorna visar. */
    stats: { type: Object, required: true },
    /*
     * `on-image` när brickorna står i hjälten ovanpå en bild (issue 435 ·
     * [[ADR-0090 Dashboardens hjälte]] § 1). Formen skickas vidare till
     * `UiStat`, som äger glaset och den vita texten. Utan värde ritas
     * brickorna som förut, på ytan.
     */
    tone: { type: String, default: null },
});

const { t } = useTranslations();
</script>

<template>
    <div class="flex flex-wrap items-start gap-4">
        <Link
            href="/containers"
            class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <UiStat
                :value="props.stats.containers"
                :label="t('dashboard.stats.containers')"
                :tone="props.tone"
            />
        </Link>

        <div class="flex flex-col gap-1">
            <Link
                href="/tasks"
                class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <UiStat
                    :value="props.stats.tasks"
                    :label="t('dashboard.stats.tasks')"
                    :tone="props.tone"
                />
            </Link>

            <p class="text-meta text-danger">
                {{ t('dashboard.stats.overdue', { count: props.stats.overdue }) }}
            </p>
        </div>
    </div>
</template>
