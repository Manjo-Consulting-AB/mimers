<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterfältet på containerns dokumentflik, se issue 178 ·
 * [[ADR-0050 Desktopdesignen]] § 12 och `docs/Design/dokument.png`.
 *
 * **Filtret är querysträng, och formuläret submittar med GET** — samma
 * konstruktion som ItemFilterBar (issue 59a § Beslut 1). `router.get` mot
 * SAMMA rutt som sidan ligger på, med `preserveState` så att fälten står
 * kvar. Ingen POST, ingen egen söksida: ett filtrerat läge är en länk som går
 * att spara, dela och backa ur med webbläsarens bakåtknapp.
 *
 * **Adressen stavas ut.** Komponenten känner inte sin egen rutt — `props` bär
 * bara containerns ULID — och samma skäl som i ItemFilterBar: en adress byggd
 * ur `window.location` hade tappat filtret när sidan flyttade.
 *
 * **Komponenten filtrerar ingenting.** Den skickar värdena till servern och
 * ritar det svar den får tillbaka; ingen rad sållas här. Servern äger urvalet,
 * och en klient som sållade hade visat fel lista för den som laddar om sidan
 * (itemFilter.js § docblock, samma linje).
 *
 * **Den ritar bara de val som finns** (Beslut 2). `items` och `uploaders`
 * kommer ur servern — items inom omfånget och uppladdarna bakom de bilagor
 * användaren redan ser — och är den ena listan tom ritas dess fält inte alls:
 * ett filter utan alternativ är brus, och en mottagare ska inte kunna filtrera
 * på något hon inte ser. `kind` ritas alltid: dess värden är fasta och en
 * träffbild utan bilder är ett giltigt svar och inte ett tomt fält.
 *
 * **Typens tre ord är `item.attachment.kind.*` och inga kopior.** Samma ord
 * som raden bär i listan, en nyckel — två uppräkningar av samma tre typer
 * glider isär, och den ena hade förr eller senare glömt en.
 *
 * **Ett värde som inte betyder något lämnas UTANFÖR strängen.** En tom `kind`,
 * en tom datumruta och `sort=newest` — förvalet — skickas inte: en URL utan
 * brus går att läsa och dela, och `?kind=` och ingen `kind` är samma fråga.
 *
 * **Sorteringen har flyttat ut** (M24 · Beslut 6). Fältet står i verktygsraden
 * över listan, bredvid lägesväxeln, för det är en fråga om LISTAN och inte ett
 * filter på den — men den är fortfarande serverns och bärs vidare av varje
 * anrop härifrån: `apply()` skickar `filter.sort` när den inte är `newest`,
 * och "Rensa" rör den inte.
 *
 * **"Rensa" rensar filtren och behåller sorteringen** — den går till samma
 * adress utan en enda filterparameter, vilket är samma sak som ett tomt
 * filter, men med `sort` kvar så ordningen användaren valde står kvar.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * varje text kommer ur `t()` under `container.documents.*`.
 *
 * Fälten speglas ur `filter`-proppen med `watch` och inte bara vid montering:
 * servern är den som avgör vad som gäller, och efter en bakåtknapp eller en
 * omladdning ska fälten visa det filtret och inte det användaren råkade skriva
 * sist.
 */
const props = defineProps({
    /* Containerns ULID — adressen byggs ur den. */
    containerUlid: { type: String, required: true },
    /* Items användaren når i containern, `{ulid, name}`, ur servern. */
    items: { type: Array, required: true },
    /* Uppladdarna bakom bilagorna användaren ser, `{ulid, name, count}`. */
    uploaders: { type: Array, required: true },
    /*
     * Filtret så som servern tillämpade det:
     * `{q, kind, item, uploader, from, to, sort}` — listorna är `[]` när
     * gruppen inte filtrerar (Beslut 4).
     */
    filter: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Typerna, i `attachment.kind`s egen ordning. Listan är fast och inte en
 * propp: `kind` kan bara anta de tre värdena (App\Actions\Attachment\
 * StoreAttachment::kindFromMime()), och en väljare byggd ur raderna hade
 * tappat en typ som ingen laddat upp ännu.
 */
const kinds = ['image', 'document', 'other'];

const kind = ref('');
const item = ref('');
const uploader = ref('');
const from = ref('');
const to = ref('');

/*
 * Vänteläget för hela raden (issue 68a § Beslut 4 och 5): submit och "rensa"
 * gör samma sorts anrop — en GET — så en enda flagga räcker. Medan svaret är
 * på väg är kontrollerna stängda och säger att något händer i stället för att
 * se döda ut.
 */
const pending = ref(false);

/*
 * Fälten speglas ur `filter`-proppen, och listorna (Beslut 4) läses som sitt
 * FÖRSTA värde: enkelvalet står kvar i det här fältet, och flera värden per
 * grupp hör till filterkolumnen som kommer senare. Fältet skickar därför
 * fortfarande ett skalärt värde — servern normaliserar det till en lista.
 */
watch(
    () => props.filter,
    (filter) => {
        kind.value = filter.kind[0] ?? '';
        item.value = filter.item[0] ?? '';
        uploader.value = filter.uploader[0] ?? '';
        from.value = filter.from ?? '';
        to.value = filter.to ?? '';
    },
    { immediate: true, deep: true },
);

/*
 * Skickar filtret som querysträng.
 *
 * `preserveScroll` för att listan inte ska hoppa till toppen varje gång ett
 * fält ändras, och `preserveState` för att fälten ska stå kvar. `page`
 * utelämnas med flit: ett nytt filter är en ny fråga, och den börjar på sida
 * ett — en kvarvarande `?page=3` hade visat en tom sida för en träfflista som
 * bara har en.
 */
function apply() {
    const params = {};

    if (kind.value !== '') {
        params.kind = kind.value;
    }

    if (item.value !== '') {
        params.item = item.value;
    }

    if (uploader.value !== '') {
        params.uploader = uploader.value;
    }

    if (from.value !== '') {
        params.from = from.value;
    }

    if (to.value !== '') {
        params.to = to.value;
    }

    // Sorteringen kommer ur `filter`-proppen och inte ur ett eget fält: den
    // väljs i verktygsraden, och förvalet skrivs inte ut — `sort=newest` är
    // samma fråga som ingen `sort`.
    if (props.filter.sort !== 'newest') {
        params.sort = props.filter.sort;
    }

    router.get(`/containers/${props.containerUlid}/documents`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/* Rensningen: samma anrop utan en enda filterparameter. Sorteringen rörs
   inte — den väljs i verktygsraden och står kvar över en rensning. */
function clear() {
    kind.value = '';
    item.value = '';
    uploader.value = '';
    from.value = '';
    to.value = '';

    apply();
}
</script>

<template>
    <form
        class="mt-6 flex flex-wrap items-end gap-3"
        role="search"
        :aria-label="t('container.documents.filter_aria')"
        @submit.prevent="apply"
    >
        <label
            for="document-filter-kind"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('container.documents.type') }}
            <select
                id="document-filter-kind"
                v-model="kind"
                name="kind"
                :disabled="pending"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
                <option value="">{{ t('container.documents.filter_all') }}</option>
                <option v-for="value in kinds" :key="value" :value="value">
                    {{ t(`item.attachment.kind.${value}`) }}
                </option>
            </select>
        </label>

        <!-- Itemet: bara de items användaren når. Ingen item i omfånget,
             inget fält — en väljare med bara *Alla* är brus. -->
        <label
            v-if="items.length > 0"
            for="document-filter-item"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('container.documents.filter_item') }}
            <select
                id="document-filter-item"
                v-model="item"
                name="item"
                :disabled="pending"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
                <option value="">{{ t('container.documents.filter_all') }}</option>
                <option v-for="option in items" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </label>

        <!-- Uppladdaren: bara de uppladdare användaren redan ser (Beslut 2).
             Servern har redan avgränsat listan till hennes omfång. -->
        <label
            v-if="uploaders.length > 0"
            for="document-filter-uploader"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('container.documents.filter_uploader') }}
            <select
                id="document-filter-uploader"
                v-model="uploader"
                name="uploader"
                :disabled="pending"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
                <option value="">{{ t('container.documents.filter_all') }}</option>
                <option v-for="option in uploaders" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </label>

        <!-- Datumintervallet. Två datumfält och ingen datumväljare: ett
             `type="date"` är webbläsarens egen kontroll, fungerar med
             tangentbord och skärmläsare, och kräver inget paket. -->
        <label
            for="document-filter-from"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('container.documents.filter_from') }}
            <input
                id="document-filter-from"
                v-model="from"
                type="date"
                name="from"
                :disabled="pending"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
        </label>

        <label
            for="document-filter-to"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('container.documents.filter_to') }}
            <input
                id="document-filter-to"
                v-model="to"
                type="date"
                name="to"
                :disabled="pending"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
        </label>

        <button
            type="submit"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ pending ? t('common.pending.default') : t('container.documents.filter_submit') }}
        </button>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center text-sm text-blue-700 underline disabled:opacity-50"
            @click="clear"
        >
            {{ pending ? t('common.pending.default') : t('container.documents.filter_clear') }}
        </button>
    </form>
</template>
