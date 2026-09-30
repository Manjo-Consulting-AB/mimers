<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import FocusMap from './FocusMap.vue';
import FormField from './FormField.vue';
import ItemViewSwitch from './ItemViewSwitch.vue';
import UiButton from './UiButton.vue';
import UiSelect from './UiSelect.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Relationssektionen på itemets detaljvy — se issue 58 § Beslut 3, 4, 5, 8
 * och 9, issue 155 och issue 156 · [[M23 Mobilen och kartan]] § 155 och § 156.
 *
 * **Fliken har två lägen under `md:`** (§ 156): *Lista* är sektionen nedan och
 * förvalet, och *Fokus* är fokuskartan. Växeln är `ItemViewSwitch` — samma
 * komponent som itemfliken använder — och läget står i querysträngen
 * (`?view=focus`), som varje annat läge i den här produkten: ett läge man kan
 * länka till är ett läge man kan dela, och ett val i minnet försvinner vid en
 * omladdning eller pekar fel när webbläsaren går bakåt.
 *
 * **Kartan är ett läge i fliken, inte en andra sektion** (§ 156). Över `md:`
 * står kartan i högerpanelen (resources/js/components/ItemMapPanel.vue), och
 * där ritar den här fliken alltid listan: växeln och fokuskartan är därför
 * `md:hidden`, och listan är dold UNDER `md:` när fokus är valt. Formen är
 * densamma på båda ställena — bara platsen skiljer.
 *
 * **Fyra ytor i en ordning** (§ 155): föräldrarna överst, itemet självt i
 * mitten, barnen under och de relaterade i en lista sist. Det är
 * mobilmockupens bild 5 med tre relationer i stället för fyra: bildens
 * fjärde grupp är struken ([[ADR-0048 Mobilen och plusknappen]] § 4), och de
 * objekten syns i trädet i stället för här.
 *
 * **Itemet självt är ingen grupp.** `self` i `rows` är itemet och inte en
 * nyckel i `links`: noden ritas för sin egen skull, bär ingen motpart och har
 * ingenting att knyta upp. Den är figurens mitt — utan den vore fliken tre
 * listor utan ett subjekt.
 *
 * **Varje rad är en länk** (§ Beslut 9), sorterad som servern levererade
 * listan (motpartens namn). Raden länkar till motpartens detaljvy, och det är
 * hela navigeringen backlogfilen ber om. En tom grupp ritas inte: tre tomma
 * rubriker säger mindre än en rad om att ingenting är kopplat. Noden för
 * itemet ritas däremot alltid när något är kopplat — den är subjektet
 * grupperna hänger under.
 *
 * **Motparten får inte finnas i vyn utanför omfånget** (§ Beslut 3). Servern
 * har redan filtrerat bort den ur `links` — App\Actions\Item\ListItemLinks
 * lägger omfånget i namnfrågan — och den här filen lägger INGENTING ovanpå:
 * ingen rad med bara ULID, inget "dolt item", ingen räknare över hur många
 * länkar som föll bort. Ett spöke säger "det finns något här du inte får se",
 * och den upplysningen är hela läckaget [[ADR-0028 Åtkomst på itemnivå]]
 * § Konsekvenser stänger. Att grupperna ritas ur `links` är därför hela
 * filtret; det finns ingen `v-if` här som gömmer något.
 *
 * **Riktningen presenteras från motpartens sida** (§ Beslut 4). `parent`
 * betyder att motparten ligger ÖVER det här itemet — etiketterna är
 * *Överordnat item*, *Underordnat item* och *Relaterat item*, och samma ord bär
 * grupprubrikerna. Servern vänder på värdet innan det når `LinkItems`
 * (App\Http\Controllers\ItemLinkController), så det användaren väljer är det
 * detaljvyn visar efter omladdning.
 *
 * **Relaterat är det enda valet som inte delar något** (§ Beslut 8), och raden
 * vid riktningsväljaren säger det. Den räknar INTE ut något: ingen fråga om
 * vilka grants som finns och ingen "det här ger N personer åtkomst" — den
 * siffran hör till delningsvyn (issue 55a), och en andra räknare här vore en
 * andra sanning om omfånget. Meningen är förklaring, inte beräkning.
 *
 * **Knyt upp sitter per rad bakom en bekräftelse** (§ Beslut 9), och texten
 * säger att bara kopplingen försvinner — båda itemen finns kvar. Raderingen
 * är hård (issue 14 § Beslut 10) och har ingen papperskorg.
 *
 * Varje sträng kommer ur `lang/` (§ Beslut 10). Relationskoderna
 * (`parent`/`child`/`related`) är domänvärden och inte text: de är samma tre
 * nycklar som serverns grupper och som `relation` i `/api`-svaret, och de
 * ritas aldrig för användaren.
 *
 * **Färgerna är roller och inte palettfärger** (issue 182 · [[ADR-0042
 * Designsystemet]] § Beslut): `text-ink-muted`, `text-ink`, `text-accent` och
 * `text-danger` ur `@theme` i `resources/css/app.css`. Fälten är `FormField`
 * med `UiSelect` i slotten och skicka-knappen är `UiButton` — samma
 * komponenter som de fem formulären i issue 425 består av. Knappen som bär en
 * `@click` — upp-knytningen — står kvar som rå `<button>`: GenomgangTest
 * tillåter bara webbläsarens egna element som klickbar yta, och en
 * `<UiButton>` hade fallit på den regeln.
 *
 * Fältets `id` är fältets NAMN och inte en läsbar etikett — `item` och
 * `relation`, samma nycklar som StoreItemLinkRequest lägger sina fel på. Det
 * är FormFields kontrakt (se resources/js/pages/Auth/useErrorFocus.js):
 * felmeddelandet får id:t `<fält>-error`, och `focusFirstError` slår upp just
 * det. Id:t hette `item-link-counterpart` och `item-link-relation` fram till
 * issue 182, och då pekade uppslaget på ingenting — fokus stod still vid ett
 * fältfel och felet annonserades bara av `role="alert"` i markupen, vilket
 * FormField inte sätter. Namnen följer de fem migrerade formulären.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Itemets namn — noden i figurens mitt (§ 155). */
    itemName: { type: String, required: true },
    /* Relationerna grupperade per riktning — nycklarna är relationens värden. */
    links: { type: Object, required: true },
    /* Items användaren får ändra och som inte redan är kopplade. */
    counterparts: { type: Array, required: true },
    can: { type: Object, required: true },
    /* Kartan ur `map`-proppen (issue 156): `{self, parent, child, related}`. */
    map: { type: Object, required: true },
    /* Relationsfliken — målet för *+N till*, ur `tabs` i Show.vue. */
    overflowHref: { type: String, required: true },
    /* Läget som gäller: `list` eller `focus`, ur serverns läsning. */
    view: { type: String, required: true },
    /* Lägena i ritad ordning till växeln: `{key, label, href}`. */
    views: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/*
 * Är fokusläget valt? Läget kommer färdigläst från servern — `ItemViewSwitch`
 * läser det aldrig ur adressen själv, och den här filen gör det inte heller:
 * två läsningar av samma sträng är två regler som kan glida isär.
 */
const focusing = computed(() => props.view === 'focus');

/*
 * Ordningen på fliken (§ 155): föräldrarna, itemet självt, barnen och de
 * relaterade. `self` är itemet och inte en nyckel i `links` — se docblocken
 * ovan.
 */
const rows = ['parent', 'self', 'child', 'related'];

/* En tom grupp ritas inte. `self` räknas inte: itemet är inget man är kopplad till. */
const hasAny = computed(() => rows.some((row) => row !== 'self' && props.links[row].length > 0));

/*
 * Formuläret. `relation` börjar TOMT och inte på ett förvalt värde: parent
 * och child bär behörighet nedåt ([[ADR-0028 Åtkomst på itemnivå]] § Beslut),
 * och att välja riktning åt användaren vore att tysta utvidga en delning.
 * En tom riktning fastnar i StoreItemLinkRequests `Rule::in` och blir ett
 * fältfel — rätt svar på en fråga användaren inte besvarat.
 *
 * Fälten heter `item` och `relation`, samma kropp som `/api` tar emot:
 * `StoreItemLinkRequest` delas rakt av.
 */
const form = useForm({
    item: '',
    relation: '',
});

function submit() {
    form.post(`/containers/${props.containerUlid}/items/${props.itemUlid}/links`, {
        // Sidan är en lista, och ett hopp till toppen efter en skriven rad
        // tappar läsarens plats.
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: focusFirstError,
    });
}

/*
 * Upp-knytningen. Bekräftelsen är webbläsarens egen dialog med serverns
 * mening ur `lang/` — ingen modal komponent och ingen sträng i JavaScript.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste
 * kunna AVBRYTA navigeringen, samma val som raderingen på detaljvyn.
 *
 * `pending` är radens vänteläge (issue 68a § Beslut 4 och 5): knappen är
 * stängd och byter ord medan servern svarar. Flaggan bär den anropade radens
 * ULID och inte en boolean — listan ritar flera rader ur samma komponent, och
 * bara knappen man tryckte på ska gå i vänteläge (Beslut 4).
 */
const pending = ref(null);

function remove(counterpart) {
    if (! window.confirm(t('item.links.remove_confirm'))) {
        return;
    }

    router.delete(`/containers/${props.containerUlid}/items/${props.itemUlid}/links/${counterpart.ulid}`, {
        onStart: () => { pending.value = counterpart.ulid; },
        onFinish: () => { pending.value = null; },
    });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('item.links.heading') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ t('item.links.description') }}</p>

        <!--
            Växeln (§ 156). Den ritas bara under `md:`: över brytpunkten står
            kartan i högerpanelen, och där är listan det enda fliken visar.
            Träffytan och tangentbordet bor i ItemViewSwitch.
        -->
        <ItemViewSwitch
            class="mt-4 md:hidden"
            :views="views"
            :current="view"
            :label="t('item.map.view.label')"
        />

        <!--
            Fokusläget (§ 156): kartan, i fliken och bara under `md:`. Över
            brytpunkten ritas den av högerpanelen, och två kopior av samma
            karta på samma skärm hade varit samma nod två gånger.
        -->
        <FocusMap
            v-if="focusing"
            class="mt-4 md:hidden"
            :map="map"
            :overflow-href="overflowHref"
        />

        <!--
            Listan. Under `md:` viker den för kartan när fokus är valt, och
            över `md:` står den kvar oavsett läge — läget är mobilens, och en
            flik som tömdes på sin lista för att någon valt ett mobilt läge
            hade varit en tom yta på en bred skärm.
        -->
        <div :class="focusing ? 'hidden md:block' : ''">
            <template v-if="hasAny">
                <template v-for="row in rows" :key="row">
                    <!--
                        Itemet självt, figurens mitt (§ 155). Ingen motpart och
                        ingen upp-knytning: noden är subjektet de andra grupperna
                        hänger under, och etiketten säger vilken nod det är.
                    -->
                    <div
                        v-if="row === 'self'"
                        class="mx-auto mt-6 flex w-full max-w-sm flex-col items-center rounded border border-border bg-surface-sunken px-4 py-3 text-center"
                    >
                        <span class="font-semibold text-ink">{{ itemName }}</span>
                        <span class="mt-1 text-xs text-ink-muted">{{ t('item.links.current') }}</span>
                    </div>

                    <div v-else-if="links[row].length > 0">
                        <h3 class="mt-6 text-sm font-medium text-ink-muted">
                            {{ t(`item.links.group.${row}`) }}
                        </h3>

                        <ul class="mt-2 flex flex-col gap-2">
                            <li
                                v-for="link in links[row]"
                                :key="link.item.ulid"
                                class="flex flex-wrap items-center gap-3 rounded border border-border bg-surface px-4 py-2"
                            >
                                <Link
                                    :href="`/containers/${containerUlid}/items/${link.item.ulid}`"
                                    class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                                >
                                    {{ link.item.name }}
                                </Link>

                                <button
                                    v-if="can.update"
                                    type="button"
                                    :disabled="pending === link.item.ulid"
                                    class="inline-flex min-h-11 items-center text-sm text-danger hover:underline"
                                    @click="remove(link.item)"
                                >
                                    {{ pending === link.item.ulid ? t('common.pending.default') : t('item.links.remove') }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </template>
            </template>

            <p v-else class="mt-4 text-sm text-ink-muted">{{ t('item.links.empty') }}</p>

            <template v-if="can.update">
                <h3 class="mt-8 text-base font-semibold">{{ t('item.links.form_heading') }}</h3>

                <p v-if="counterparts.length === 0" class="mt-2 text-sm text-ink-muted">
                    {{ t('item.links.no_counterparts') }}
                </p>

                <form v-else class="mt-4 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
                    <!--
                        Felet på `item` kommer från StoreItemLinkRequest
                        (okänd ULID) eller ur LinkItems (item_link.self,
                        item_link.cross_container, item_link.pair_exists) —
                        kontrollern lägger de tre sista på just den här nyckeln.
                        Ett meddelande som slänger bort `data` är sämre än
                        felkoden det ersatte, så pair_exists säger vilken relation
                        paret redan har.
                    -->
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.links.counterpart')"
                        id="item"
                        :error="form.errors.item"
                    >
                        <UiSelect
                            id="item"
                            v-model="form.item"
                            :described-by="describedBy"
                            name="item"
                            required
                            class="self-start"
                        >
                            <option value="">{{ t('item.links.counterpart_none') }}</option>
                            <option v-for="candidate in counterparts" :key="candidate.ulid" :value="candidate.ulid">
                                {{ candidate.name }}
                            </option>
                        </UiSelect>
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.links.relation.label')"
                        id="relation"
                        :error="form.errors.relation"
                    >
                        <UiSelect
                            id="relation"
                            v-model="form.relation"
                            :described-by="describedBy"
                            name="relation"
                            required
                            class="self-start"
                        >
                            <option value="">{{ t('item.links.relation.none') }}</option>
                            <option value="parent">{{ t('item.links.relation.parent') }}</option>
                            <option value="child">{{ t('item.links.relation.child') }}</option>
                            <option value="related">{{ t('item.links.relation.related') }}</option>
                        </UiSelect>

                        <p class="text-sm text-ink-muted">{{ t('item.links.relation_note') }}</p>

                        <!-- Riktningen och inte motparten: en cykel handlar om
                             vilket håll kanten går åt, och `item_link.cycle` läggs
                             därför på den här nyckeln (Beslut 6). -->
                    </FormField>

                    <UiButton type="submit" :pending="form.processing" class="self-start">
                        {{ form.processing ? t('common.pending.default') : t('item.links.submit') }}
                    </UiButton>
                </form>
            </template>
        </div>
    </section>
</template>
