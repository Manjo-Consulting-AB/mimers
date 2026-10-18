<script setup>
import { computed } from 'vue';
import { coverUrl } from './coverPresentation.js';

/*
 * Itemets omslagsbild på översikten — se issue 307 ·
 * [[M35 Itemets ikon och kartans kort]] § 307 och [[ADR-0041 Itemets vy]]
 * § Beslut.
 *
 * **Upplösningen bor på servern.** `cover` är `{ulid, variants}` — den valda
 * bilagan om den finns, annars itemets äldsta bild, annars null — och kommer
 * ur App\Actions\Item\ResolveItemCover. Vyn letar aldrig själv bland
 * bilagorna: en andra regel för vad som är itemets bild hade glidit ifrån den
 * första (ADR-0041 § Beslut).
 *
 * **Bilden beskärs aldrig.** `object-contain` skalar hela bilden inom rutan,
 * och `max-h-64` håller höjden fast så ett högt foto inte trycker ner
 * snabbfakta. Bredden kommer från anroparen — översikten ger den en kolumn
 * från `md:` och hela bredden under — och `w-full` fyller den utan att
 * förvränga: `object-contain` bevarar proportionerna och lämnar tomrum i
 * stället för att klippa.
 *
 * **Adressen byggs av `coverUrl`** ur `coverPresentation.js`: `medium` när
 * varianten finns, originalet annars. Regeln är densamma som containerns bild
 * och väljaren använder, och bor där av samma skäl — två formuleringar hade
 * glidit isär (issue 683 § Beslut 1).
 *
 * **Utan bild ritas ingenting.** Ingen ram och ingen platshållare: ett item
 * utan bild ska inte se ut som ett item med en trasig bild, och
 * *Quick facts* tar hela bredden (ADR-0041 § Beslut).
 *
 * **`alt` är itemets namn.** Bilden är itemets ansikte och står ensam utan en
 * intilliggande rubrik på smala skärmar; en tom alt-text hade gjort den
 * osynlig för en skärmläsare (till skillnad från containerns bild, som alltid
 * står intill containerns namn).
 */
const props = defineProps({
    /* `{ ulid, variants }` eller null, ur `cover`-proppen. */
    cover: { type: Object, default: null },
    /* Itemets namn — bildens alt-text. */
    alt: { type: String, required: true },
});

const url = computed(() => coverUrl(props.cover, 'medium'));
</script>

<template>
    <img v-if="url" :src="url" :alt="alt" class="max-h-64 w-full rounded-lg object-contain">
</template>
