<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Den globala historiken — `GET /history`, se issue 271 och
 * App\Http\Controllers\HistoryController.
 *
 * **Serverns halva först, sidan sedan** (issue 272). Den här filen ritar det
 * minsta som gör rutten användbar: rubriken och listan över dagar. Filtret,
 * diagrammen, containerväljaren med *Account activity* och raden i sidopanelen
 * kommer i 272 — samma uppdelning som issue 254/255 gjorde för dokumentlistan,
 * där servern och formen byggdes i två steg.
 *
 * **Dagarna kommer färdiga och vyn räknar aldrig om en tidpunkt till en dag.**
 * Proppen `days` är `[{date, rows}]`, och `date` är dagen i användarens
 * tidszon, räknad av servern ([[ADR-0044 Användarens dag]]). Klientens klocka
 * är inte hennes tidszon: en händelse 23:30 UTC hör till nästa dygn i
 * Stockholm, och en vikning här hade lagt den på fel dag.
 *
 * **Raden säger vilken container den gäller.** Här kommer raderna från flera
 * containrar — och från ingen alls, kontohändelserna — så `show-container`
 * sätts, precis som i dashboardens händelsepanel (issue 126). Inne på
 * containerns flik är containern given av sidan och raden tiger om den (issue
 * 116).
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, flikens namn i webbläsaren, tomtillståndet och varje rads mening
 * kommer ur `t()`.
 */
defineProps({
    /*
     * Händelserna grupperade per dag i användarens tidszon: `[{date, rows}]`,
     * nyast först. Raderna ur App\Actions\Audit\PresentAuditEvents::byDay().
     */
    days: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t('audit.history.title')" />

        <h1 class="text-heading font-semibold text-ink">{{ t('audit.history.heading') }}</h1>

        <p v-if="days.length === 0" class="mt-6 text-body text-ink-muted">
            {{ t('audit.history.empty') }}
        </p>

        <!--
            En dag är en grupp med sin egen rubrik, och raderna är <li> — samma
            form `UiListRow` kräver, och samma skäl som i varje annan lista: en
            skärmläsare ska höra hur många rader det finns innan den läser den
            första.
        -->
        <section v-for="day in days" :key="day.date" class="mt-6">
            <h2 class="text-title font-semibold text-ink">{{ day.date }}</h2>

            <ul class="mt-2">
                <HistoryRow v-for="row in day.rows" :key="row.ulid" :row="row" show-container />
            </ul>
        </section>
    </AppLayout>
</template>
