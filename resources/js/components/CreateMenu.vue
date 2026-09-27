<script setup>
import { Link } from '@inertiajs/vue3';
import UiSheet from './UiSheet.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Menyn bakom plusknappen på ett item, se [[ADR-0048 Mobilen och plusknappen]]
 * § 2 och [[M23 Mobilen och kartan]] § 152.
 *
 * **Raden är en länk och inget eget formulär.** Menyn skapar ingenting själv —
 * den leder dit sakerna skapas i dag: *Item under* till formuläret med
 * `?parent`, *Relation* till relationsfliken, *Bild eller dokument* till
 * bilagefliken och *Uppgift* till schemaformuläret. Adresserna byggs av
 * App\Support\Frontend\CreateTarget, som också prövar policyerna: en rad
 * användaren inte får använda skickas inte med hit, och en tom meny ger ingen
 * knapp. Den här filen ritar raderna och ingenting annat.
 *
 * **Raden är en `<Link>`** och inte en `<a href>`: menyvalet är en
 * Inertia-navigering som alla andra, och en omladdning hade tappat sidans
 * tillstånd.
 *
 * **Menyn stänger sig själv när en rad väljs.** Arket ligger kvar över den nya
 * sidan annars — en modal som överlever sin egen navigering. Stängningen sker
 * på `click` och inte efter svaret: adressen är känd redan när raden trycks,
 * och en fördröjd stängning hade visat en meny över en sida som redan bytts.
 *
 * **Etiketten byggs ur radens nyckel** — `create.rows.<key>` — så att servern
 * skickar en nyckel och ingen färdig mening ([[ADR-0021 Frontendteknik]]).
 * Nycklarna står i den ordning CreateTarget sänder dem, och det är ADR:ens
 * ordning: *Item under*, *Relation*, *Bild eller dokument*, *Uppgift*.
 *
 * **Rubriken kan namnges av anroparen** (issue 156 · [[M23 Mobilen och kartan]]
 * § 156). Skalets meny heter *Create* och ingenting mer — den hör till sidan
 * man står på — men fokuskartans meny hör till EN nod: raderna under rubriken
 * (*Item under*, *Relation*) är relativa till den noden, och en meny som inte
 * säger vilken nod den gäller är en meny man gissar på. `heading` bär därför
 * nodens namn, och standarden är skalets ord — rubriken är en prop och ingen
 * egen sträng i den här filen.
 */
defineProps({
    /* Sant medan menyn är öppen. AppLayout äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Elementet som öppnade menyn: arkets fäste och fokus vid stängning. */
    trigger: { type: Object, default: null },
    /* `[{ key, href }]` ur App\Support\Frontend\CreateTarget. */
    rows: { type: Array, required: true },
    /* Arkets rubrik, eller null för skalets ord. */
    heading: { type: String, default: null },
});

const emit = defineEmits(['close']);

const { t } = useTranslations();
</script>

<template>
    <UiSheet
        :open="open"
        :trigger="trigger"
        :heading="heading ?? t('create.heading')"
        @close="emit('close')"
    >
        <ul class="flex flex-col">
            <li v-for="row in rows" :key="row.key">
                <Link
                    :href="row.href"
                    class="flex min-h-11 items-center rounded-control px-2 text-body font-medium text-ink hover:bg-surface-sunken"
                    @click="emit('close')"
                >
                    {{ t(`create.rows.${row.key}`) }}
                </Link>
            </li>
        </ul>
    </UiSheet>
</template>
