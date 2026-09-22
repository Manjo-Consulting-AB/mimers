<script setup>
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kartans plats, högerpanelen i trepanelslayouten — se
 * resources/js/pages/Containers/Items/Show.vue, issue 103 ·
 * [[M17 Designsystemet]] § 103 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Panelen är TOM med flit, och det är hela dess innehåll.** Fokuskartan
 * behöver ingen ny fråga — `ListItemLinks` *är* dess noder — men den behöver
 * en layout, och den är eget arbete som inte är beslutat. Att rita noder här
 * hade varit att bygga fokuskartan i smyg; att fylla ytan med en hälsning
 * hade varit att lova den.
 *
 * **En tom panel som väntar är inte samma sak som en trasig yta.** Därför
 * står ingenting här som säger att något saknas: ingen tom-tillståndsruta
 * (`UiEmptyState` skiljer *inget alls* från *inget som matchar*, och båda
 * vore ett påstående om innehållet), ingen rad om att kartan kommer, ingen
 * räknare och ingen ikon. Kvar står rubriken — platsens namn — och en yta med
 * höjd, så att panelen håller sin kolumn i layouten i stället för att falla
 * ihop till en rubrik.
 *
 * Ytan är `aria-hidden`: den bär ingen information, och en skärmläsare ska
 * möta panelens namn och ingenting mer.
 */
const { t } = useTranslations();
</script>

<template>
    <UiCard>
        <template #heading>{{ t('item.map.heading') }}</template>

        <div aria-hidden="true" class="min-h-64 rounded-control bg-surface-muted"></div>
    </UiCard>
</template>
