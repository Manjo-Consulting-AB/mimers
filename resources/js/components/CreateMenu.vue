<script setup>
import { computed } from 'vue';
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
 * **En rad får bära sitt eget namn** (M28 · testarnas fynd 2026-10-07):
 * containerns dokument- och kostnadsflik listar en rad per item, och namnet är
 * användarens eget — det finns ingen nyckel att slå upp. `row.label` ritas då
 * i stället för uppslagningen, och en rad med en nyckel och inget namn ritas
 * som förut.
 *
 * **Rubriken kan namnges av anroparen** (issue 156 · [[M23 Mobilen och kartan]]
 * § 156). Skalets meny heter *Add* och ingenting mer — den hör till sidan
 * man står på — men fokuskartans meny hör till EN nod: raderna under rubriken
 * (*Item under*, *Relation*) är relativa till den noden, och en meny som inte
 * säger vilken nod den gäller är en meny man gissar på.
 *
 * `heading` bär därför antingen en NYCKEL efter `create.` — containerns
 * flikar skickar `pick_item`, som blir *Choose item* (M28) — eller en färdig
 * text, som fokuskartans nodnamn. En nyckel som inte finns i katalogen
 * returneras som sig själv av `t()`, och då ritas det skickade värdet
 * ordagrant: en nod som heter *Motor* hade annars fått rubriken
 * `create.Motor`. Standarden är skalets ord, och rubriken är en prop och
 * ingen egen sträng i den här filen.
 */
const props = defineProps({
    /* Sant medan menyn är öppen. AppLayout äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Elementet som öppnade menyn: arkets fäste och fokus vid stängning. */
    trigger: { type: Object, default: null },
    /* `[{ key, href }]` ur App\Support\Frontend\CreateTarget. */
    rows: { type: Array, required: true },
    /* Arkets rubrik: en nyckel efter `create.`, eller en färdig text. */
    heading: { type: String, default: null },
});

const emit = defineEmits(['close']);

const { t } = useTranslations();

/*
 * Rubriken, ur `heading` och skalets eget ord (se docblocken ovan): en nyckel
 * slås upp, och ett värde utan nyckel ritas ordagrant. `t()` svarar med nyckeln
 * själv när uppslagningen missar, och jämförelsen är det som skiljer en nod som
 * heter *Motor* från en nyckel som saknas i katalogen.
 */
const sheetHeading = computed(() => {
    if (props.heading === null) {
        return t('create.heading');
    }

    const key = `create.${props.heading}`;
    const text = t(key);

    return text === key ? props.heading : text;
});
</script>

<template>
    <UiSheet
        :open="open"
        :trigger="trigger"
        :heading="sheetHeading"
        @close="emit('close')"
    >
        <ul class="flex flex-col">
            <li v-for="row in rows" :key="row.key">
                <Link
                    :href="row.href"
                    class="flex min-h-11 items-center rounded-control px-2 text-body font-medium text-ink hover:bg-surface-sunken"
                    @click="emit('close')"
                >
                    {{ row.label ?? t(`create.rows.${row.key}`) }}
                </Link>
            </li>
        </ul>
    </UiSheet>
</template>
