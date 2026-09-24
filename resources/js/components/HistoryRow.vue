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
 * **Två namn kan saknas, och båda har en ersättare.** `user` och `item` kommer
 * ur App\Actions\Audit\PresentAuditEvents och är `null` när personen raderats,
 * itemet gallrats — eller när händelsen skrevs av ett jobb och ingen person
 * var inblandad. Ersättarna är *a former user* och *a deleted item*, och de
 * står i `lang/` som varje annan mening: ett tomt fält hade lästs som ett
 * ritfel, och raden är inte trasig, den är gammal.
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
     * `{ ulid, action, created_at, user, item, changed }`.
     */
    row: { type: Object, required: true },
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
</script>

<template>
    <UiListRow>
        <template #title>{{ sentence }}</template>

        <template #meta>
            <time :datetime="row.created_at">{{ date.text }}</time>
        </template>
    </UiListRow>
</template>
