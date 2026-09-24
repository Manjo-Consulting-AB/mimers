<script setup>
import { Head } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerns historikflik — containerns egen sida, se issue 116 ·
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan och inställningssidan. Fliken är en egen adress
 * (`/containers/{ulid}/history`) och ingen panel på en annan sida: en flik man
 * kan länka till är en flik man kan dela, och historiken är den enda av
 * containerns flikar som hade behövt ett undantag från det (issue 100).
 *
 * **Raderna kommer färdiga och vyn ställer ingen fråga.** Proppen `rows` är
 * App\Actions\Audit\PresentAuditEvents svar — högst hundra rader, nyast först,
 * med namnen redan uppslagna. Vilka rader användaren får läsa avgjordes av
 * App\Actions\Audit\ListAuditEvents i kontrollern (issue 108), och den regeln
 * upprepas inte här: en gäst ser sina egna rader och ägaren allas, ur samma
 * svar, och vyn kan inte se skillnad på dem.
 *
 * **Ingen paginering och inget filter.** De hundra senaste är API:ets egen
 * gräns (issue 40 § Beslut 8), och fliken visar samma lista. Behövs mer är det
 * ett eget beslut, och en sida som skrollar utan ände är inte det svaret.
 *
 * **En tom rad och inget filtrerat läge.** Fliken har inget filter, så en tom
 * lista betyder att ingenting har hänt — inte att något dolts. Meningen är
 * därför *ingenting har hänt här ännu* och aldrig *inget matchar*: den andra
 * hade varit osann på den här ytan, och de två tillstånden säger olika saker
 * (issue 99, samma skillnad som `UiEmptyState` ritar). Raden är en `<p>` och
 * ingen egen komponent, som på kategoriernas, taggarnas och papperskorgens
 * sidor.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): rubriken,
 * flikens namn i webbläsaren, tomtillståndet och varje rads mening kommer ur
 * `t()` — radens ur resources/js/components/HistoryRow.vue, resten ur
 * `audit.history.*`. Sidans titel är en egen nyckel och inte flikens etikett,
 * som på kategoriernas och taggarnas sidor.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /* Raderna ur App\Actions\Audit\PresentAuditEvents, nyast först. */
    rows: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('audit.history.title')" />

        <h1 class="text-2xl font-semibold">{{ t('audit.history.heading') }}</h1>

        <p v-if="rows.length === 0" class="mt-6 text-sm text-slate-600">
            {{ t('audit.history.empty') }}
        </p>

        <!--
            Listan är en <ul> och raderna är <li> — formen `UiListRow` kräver,
            och av samma skäl som i varje annan lista: en skärmläsare ska höra
            hur många rader det finns innan den läser den första.
        -->
        <ul v-else class="mt-4">
            <HistoryRow v-for="row in rows" :key="row.ulid" :row="row" />
        </ul>
    </ContainerLayout>
</template>
