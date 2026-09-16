<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Relationssektionen på itemets detaljvy — se issue 58 § Beslut 3, 4, 5, 8
 * och 9.
 *
 * **Tre grupper och varje rad är en länk** (§ Beslut 9). Överordnade,
 * underordnade, syskon — i den ordningen, och sorterade som servern
 * levererade dem (motpartens namn). Raden länkar till motpartens detaljvy,
 * och det är hela navigeringen backlogfilen ber om. En tom grupp ritas inte:
 * tre tomma rubriker säger mindre än en rad om att ingenting är kopplat.
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
 * *Överordnat item*, *Underordnat item* och *Syskon*, och samma ord bär
 * grupprubrikerna. Servern vänder på värdet innan det når `LinkItems`
 * (App\Http\Controllers\ItemLinkController), så det användaren väljer är det
 * detaljvyn visar efter omladdning.
 *
 * **Syskon är det enda valet som inte delar något** (§ Beslut 8), och raden
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
 * (`parent`/`child`/`sibling`) är domänvärden och inte text: de är samma tre
 * nycklar som serverns grupper och som `relation` i `/api`-svaret, och de
 * ritas aldrig för användaren.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* Relationerna grupperade per riktning — nycklarna är relationens värden. */
    links: { type: Object, required: true },
    /* Items användaren får ändra och som inte redan är kopplade. */
    counterparts: { type: Array, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

/* Överordnade, underordnade, syskon — i den ordningen (§ Beslut 9). */
const groups = ['parent', 'child', 'sibling'];

const hasAny = computed(() => groups.some((group) => props.links[group].length > 0));

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
        <p class="mt-1 text-sm text-slate-600">{{ t('item.links.description') }}</p>

        <template v-if="hasAny">
            <div v-for="group in groups" :key="group">
                <template v-if="links[group].length > 0">
                    <h3 class="mt-6 text-sm font-medium text-slate-600">
                        {{ t(`item.links.group.${group}`) }}
                    </h3>

                    <ul class="mt-2 flex flex-col gap-2">
                        <li
                            v-for="link in links[group]"
                            :key="link.item.ulid"
                            class="flex flex-wrap items-center gap-3 rounded border border-slate-300 bg-white px-4 py-2"
                        >
                            <Link
                                :href="`/containers/${containerUlid}/items/${link.item.ulid}`"
                                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                            >
                                {{ link.item.name }}
                            </Link>

                            <button
                                v-if="can.update"
                                type="button"
                                :disabled="pending === link.item.ulid"
                                class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
                                @click="remove(link.item)"
                            >
                                {{ pending === link.item.ulid ? t('common.pending.default') : t('item.links.remove') }}
                            </button>
                        </li>
                    </ul>
                </template>
            </div>
        </template>

        <p v-else class="mt-4 text-sm text-slate-600">{{ t('item.links.empty') }}</p>

        <template v-if="can.update">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.links.form_heading') }}</h3>

            <p v-if="counterparts.length === 0" class="mt-2 text-sm text-slate-600">
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
                <div class="flex flex-col gap-1">
                    <label for="item-link-counterpart" class="text-sm font-medium text-slate-800">
                        {{ t('item.links.counterpart') }}
                    </label>

                    <select
                        id="item-link-counterpart"
                        v-model="form.item"
                        name="item"
                        required
                        class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="">{{ t('item.links.counterpart_none') }}</option>
                        <option v-for="candidate in counterparts" :key="candidate.ulid" :value="candidate.ulid">
                            {{ candidate.name }}
                        </option>
                    </select>

                    <p v-if="form.errors.item" class="text-sm text-red-700">{{ form.errors.item }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="item-link-relation" class="text-sm font-medium text-slate-800">
                        {{ t('item.links.relation.label') }}
                    </label>

                    <select
                        id="item-link-relation"
                        v-model="form.relation"
                        name="relation"
                        required
                        class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="">{{ t('item.links.relation.none') }}</option>
                        <option value="parent">{{ t('item.links.relation.parent') }}</option>
                        <option value="child">{{ t('item.links.relation.child') }}</option>
                        <option value="sibling">{{ t('item.links.relation.sibling') }}</option>
                    </select>

                    <p class="text-sm text-slate-600">{{ t('item.links.relation_note') }}</p>

                    <!-- Riktningen och inte motparten: en cykel handlar om
                         vilket håll kanten går åt, och `item_link.cycle` läggs
                         därför på den här nyckeln (Beslut 6). -->
                    <p v-if="form.errors.relation" class="text-sm text-red-700">{{ form.errors.relation }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                >
                    {{ form.processing ? t('common.pending.default') : t('item.links.submit') }}
                </button>
            </form>
        </template>
    </section>
</template>
