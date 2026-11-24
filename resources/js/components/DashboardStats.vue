<script setup>
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
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
 * ett tal och ett ord, ingen egen räkning och ingen form på nytt. Sedan issue
 * #1651 har `UiStat` en standardslot under etiketten, och den försenade raden
 * ritas INUTI tutan som en underrad — den första underraden i en taltuta, ett
 * medvetet beslut om designsystemet ([[ADR-0042 Designsystemet]] § Beslut).
 * Utan slotinnehåll ritas `UiStat` exakt som förut.
 *
 * **Brickorna fyller radens höjd** (issue #1651): `UiStat` får `h-full` och
 * fyller länken, som sträcks till hjälte-radens höjd. Uppgiftsbrickan med den
 * försenade raden är den högsta, och raden sträcker de andra till samma höjd.
 *
 * **Brickorna är genvägar** (fynd från testarna 2026-10-03, issue 654):
 * containerbrickan leder till `/containers` och uppgiftsbrickan till `/tasks` —
 * samma lista som talet är hämtat ur. Länken bor här och inte i UiStat, så att
 * containerns översikt kan använda samma tuta olänkad. Den röda *Overdue*-raden
 * ligger inuti uppgiftsbrickans länk: den är en underrad till talet i samma
 * ruta, inte en egen ingång.
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
    <div class="flex flex-wrap gap-4">
        <Link
            href="/containers"
            class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <UiStat
                class="h-full"
                :value="props.stats.containers"
                :label="t('dashboard.stats.containers')"
                :tone="props.tone"
            />
        </Link>

        <Link
            href="/tasks"
            class="rounded-card outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <UiStat
                class="h-full"
                :value="props.stats.tasks"
                :label="t('dashboard.stats.tasks')"
                :tone="props.tone"
            >
                <!--
                    Den försenade underraden ligger inuti uppgiftsbrickans
                    ruta och inuti länken till `/tasks` (issue #1651). Vid noll
                    ritas ingen rad alls — inte en tom och inte en med noll.
                -->
                <p
                    v-if="props.stats.overdue > 0"
                    class="mt-1 flex items-center gap-1 text-meta text-danger"
                >
                    <UiIcon name="overdue" :size="16" />
                    {{ t('dashboard.stats.overdue', { count: props.stats.overdue }) }}
                </p>
            </UiStat>
        </Link>
    </div>
</template>
