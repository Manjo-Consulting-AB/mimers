<script setup>
import { computed } from 'vue';
import UiListRow from './UiListRow.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';

/*
 * En rad i historiken, se issue 116 · [[ADR-0043 Tre loggar]]
 * § Händelseloggen.
 *
 * **Raden är en mening och ingenting mer.** Formen kommer ur `UiListRow`
 * (issue 99) — titeln till vänster, metan till höger, träffytan i radens rot —
 * och den här filen fyller den med ord. Ingen sträng står här (SprakTest):
 * meningen är `audit.action.<handling>` ur `lang/en/ui.php`, med en nyckel per
 * handling, och raden vet bara VILKEN handling den ritar.
 *
 * **Ingen åtgärd och ingen länk.** Historiken är läsning: en rad man kan
 * klicka på vore en väg tillbaka in i något som kanske inte finns — itemet är
 * ofta just det som gallrats — och en rad som ser klickbar ut utan att leda
 * någonstans är värre än en rad som inte gör det. Därför bär raden ingen
 * `<Link>` och ingen knapp; träffytan i `UiListRow` räcker för att raden ska
 * gå att markera och läsa.
 *
 * **Tre namn kan saknas, och alla har en ersättare.** `user`, `item` och
 * containerns namn kommer ur App\Actions\Audit\PresentAuditEvents och är
 * `null` när personen raderats, itemet gallrats, containern gallrats — eller
 * när händelsen skrevs av ett jobb och ingen person var inblandad.
 * Ersättarna är *a former user*, *a deleted item* och *a deleted container*,
 * och de står i `lang/` som varje annan mening: ett tomt fält hade lästs som
 * ett ritfel, och raden är inte trasig, den är gammal.
 *
 * **Containern ritas bara när anroparen ber om den** (issue 126). Inuti en
 * container — historikflikarna — är containern given av sidan man står på, och
 * en rad som upprepade dess namn hade sagt samma sak två gånger: flikarna
 * visar därför raden precis som förut. Dashboardens händelsepanel visar rader
 * över alla användarens konton, där står raden utanför sin container och måste
 * säga vilken den gäller. `showContainer` är anroparens val, och namnet ritas
 * under meningen ur `audit.fallback.container` när containern inte längre
 * finns. `container` är `null` när raden inte gäller någon container alls
 * (kontoraderna) — då ritas ingen rad, för en kontorad har ingen container att
 * namnge.
 *
 * **`changed` blir en uppräkning och aldrig ett innehåll.** En ändring bär
 * fältens NAMN (`meta.changed`), och orden kommer ur `audit.field.*` — vad som
 * stod i fältet finns inte i loggen och kan därför inte visas av misstag. Ett
 * fält utan ord visar sin nyckel, precis som `t()` gör överallt annars, och
 * hittas första gången någon läser raden.
 *
 * **Datumet följer datumregeln** (issue 104): `eventDate()` ur
 * resources/js/composables/useRelativeDate.js avgör relativt eller absolut,
 * och den här filen formaterar ingenting själv. `<time datetime>` bär
 * tidsstämpeln maskinläsbart jämte det lästa datumet, samma form som en
 * loggrad förtjänar.
 */
const props = defineProps({
    /*
     * En rad ur App\Actions\Audit\PresentAuditEvents:
     * `{ ulid, action, created_at, user, item, container, changed }`.
     *
     * `container` är `{ name }` när raden gäller en container och `null` när
     * den inte gör det; `name` är `null` när containern gallrats.
     */
    row: { type: Object, required: true },
    /*
     * Ska raden säga vilken container den gäller? Falskt i historikflikarna,
     * där containern är given av sidan (issue 116), sant i dashboardens
     * händelsepanel, där raderna kommer från flera containrar (issue 126).
     */
    showContainer: { type: Boolean, default: false },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

/*
 * Fälten en ändring rörde, som ord. Listan är tom för varje annan handling,
 * och meningen ritar då inget `:fields` — `translate()` lämnar en platshållare
 * den inte får ett värde för orörd, så en mening utan fält bär ingen.
 */
const changed = computed(() =>
    props.row.changed.map((field) => t(`audit.field.${field}`)),
);

/*
 * Meningen: handlingens ord, med den handlande och itemet isatta. Nyckeln
 * byggs ur handlingens EGET namn — `item.created` blir
 * `audit.action.item.created` — så en ny handling inte kan glömmas i en
 * översättningstabell här: den finns i katalogen eller så syns nyckeln.
 */
const sentence = computed(() => t(`audit.action.${props.row.action}`, {
    user: props.row.user ?? t('audit.fallback.user'),
    item: props.row.item ?? t('audit.fallback.item'),
    fields: changed.value.join(', '),
}));

const date = computed(() => eventDate(props.row.created_at));

/*
 * Containern raden gäller, som ord — namnet eller ersättaren för en gallrad
 * container. `null` betyder att raden inte ska säga någon container alls:
 * antingen för att anroparen inte bad om den (flikarna) eller för att raden
 * inte gäller någon (kontoraderna, `container` är `null` i proppen).
 */
const container = computed(() => {
    if (!props.showContainer || props.row.container === null) {
        return null;
    }

    return props.row.container.name ?? t('audit.fallback.container');
});
</script>

<template>
    <UiListRow>
        <template #title>{{ sentence }}</template>

        <template v-if="container !== null" #subtitle>{{ container }}</template>

        <template #meta>
            <time :datetime="row.created_at">{{ date.text }}</time>
        </template>
    </UiListRow>
</template>
