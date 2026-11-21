<script setup>
import { computed } from 'vue';
import UiBadge from './UiBadge.vue';
import UiIcon from './UiIcon.vue';
import UiListRow from './UiListRow.vue';
import { presentationFor } from './iconPresentation.js';
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
 * (kontoraderna): då står platsen *Account* i stället (M30 · issue 272 ·
 * Beslut 3) — raden hör till kontot, och en tom plats där containernamnet
 * annars står hade lästs som ett ritfel. Dashboardens händelsepanel ritar
 * samma ord, och det är avsiktligt.
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
 *
 * **Ikonrutan följer domänen** (issue 286 · [[ADR-0057 Ikoner och typsnitt]]
 * § 3). Domänen är handlingens första led — `cost_entry.created` blir
 * `cost_entry` — och rollen och tonen kommer ur `iconPresentation.js`
 * (issue 283). Rutan väljs efter vad raden handlar om och aldrig för
 * omväxlingens skull; en okänd domän ritar `history` i `neutral` i stället för
 * att kasta, så en ny handling syns som en rad även innan mappningen känner
 * den. `UiIcon` äger storlek, linjebredd, färg och `aria-hidden` (issue 282),
 * och `UiListRow` centrerar rutan mot texten (issue 283).
 */
const props = defineProps({
    /*
     * En rad ur App\Actions\Audit\PresentAuditEvents:
     * `{ ulid, action, created_at, user, agent, item, container, changed }`.
     *
     * `container` är `{ name }` när raden gäller en container och `null` när
     * den inte gör det; `name` är `null` när containern gallrats. `agent` är
     * `null` när personen handlade själv, `{ ulid, name }` när en agent gjorde
     * det och `{ ulid: null, name: null }` när agenten tagits bort.
     */
    row: { type: Object, required: true },
    /*
     * Ska raden säga vilken plats den gäller? Falskt i historikflikarna, där
     * containern är given av sidan (issue 116), sant i dashboardens
     * händelsepanel (issue 126) och på den globala historiken (M30 · issue
     * 272), där raderna kommer från flera containrar — och från ingen alls.
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
 * Avsändaren, och agenten när en handlade (issue 428 · [[ADR-0087 MCP-server
 * för agenter]] Riktning 3 och 8). `agent` är `null` för en rad en person
 * skrev själv, `{ulid, name}` för en agentrad och `{ulid: null, name: null}`
 * när agenten tagits bort — det sista läget säger *via a removed agent* i
 * stället för att påstå att personen handlade själv. Formen kommer färdig ur
 * App\Actions\Audit\PresentAuditEvents; raden ritar den bara.
 */
const actor = computed(() => {
    const user = props.row.user ?? t('audit.fallback.user');

    if (props.row.agent === null || props.row.agent === undefined) {
        return user;
    }

    return props.row.agent.name === null
        ? t('history.row.actor_via_removed_agent', { user })
        : t('history.row.actor_via_agent', { user, agent: props.row.agent.name });
});

/*
 * Agentmärkningen ritas bredvid avsändaren. Den är en BRICKA och inte en
 * ikon: `UiBadge` bär ordet, `UiIcon` bär rollen, och en skärmläsare hör
 * *Agent* även om den inte ser nyansen (issue 68b § Beslut 7).
 */
const agent = computed(() => props.row.agent ?? null);

/*
 * Meningen: handlingens ord, med den handlande och itemet isatta. Nyckeln
 * byggs ur handlingens EGET namn — `item.created` blir
 * `audit.action.item.created` — så en ny handling inte kan glömmas i en
 * översättningstabell här: den finns i katalogen eller så syns nyckeln.
 */
const sentence = computed(() => t(`audit.action.${props.row.action}`, {
    user: actor.value,
    item: props.row.item ?? t('audit.fallback.item'),
    fields: changed.value.join(', '),
}));

const date = computed(() => eventDate(props.row.created_at));

/*
 * Domänen: handlingens första led. `cost_entry.created` blir `cost_entry`,
 * och det är domänen och inte den fulla handlingen som väljer ikonen.
 */
const domain = computed(() => props.row.action.split('.')[0]);

/*
 * Rollen och tonen ur presentationsmodulen. En okänd domän ger `history` i
 * `neutral` där — raden behöver ingen egen fallback.
 */
const presentation = computed(() => presentationFor(domain.value));

/*
 * Platsen raden gäller, som ord — containerns namn, ersättaren för en gallrad
 * container, eller *Account* för en kontorad. `null` betyder att raden inte
 * ska säga någon plats alls: anroparen bad inte om den (flikarna), och där är
 * containern given av sidan man står på.
 */
const container = computed(() => {
    if (!props.showContainer) {
        return null;
    }

    /*
     * Kontoraden: ingen container att namnge, och platsen är kontot (M30 ·
     * issue 272 · Beslut 3). `audit.fallback.container` hade varit osann —
     * containern är inte gallrad, den har aldrig funnits.
     */
    if (props.row.container === null) {
        return t('audit.history.account_place');
    }

    return props.row.container.name ?? t('audit.fallback.container');
});
</script>

<template>
    <UiListRow>
        <template #icon>
            <UiIcon :name="presentation.role" :tile="presentation.tone" />
        </template>

        <template #title>
            {{ sentence }}
            <!--
                Agentmärkningen: ordet *Agent* med en ikon, bredvid avsändaren
                (issue 428). Den ritas bara när raden bär en agent, och raden
                utan agent ser därför exakt ut som i dag.
            -->
            <UiBadge v-if="agent !== null" class="ml-2 align-middle">
                <UiIcon name="link" :size="16" class="mr-1 inline align-text-bottom" />
                {{ t('history.row.agent_badge') }}
            </UiBadge>
        </template>

        <template v-if="container !== null" #subtitle>{{ container }}</template>

        <template #meta>
            <time :datetime="row.created_at">{{ date.text }}</time>
        </template>
    </UiListRow>
</template>
