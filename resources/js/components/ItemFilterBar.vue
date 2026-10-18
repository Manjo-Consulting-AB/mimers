<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { categoryOptions } from './categoryTree.js';
import { activeFilters } from './itemFilter.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterraden i containerns itemlista — issue 59a § Beslut 1, 2, 5, 6 och 8,
 * och den löpande sökningen i issue 311 · [[ADR-0062 Kartans luft, listans
 * ruta och den löpande sökningen]] § 7 och 8.
 *
 * **Filtret är querysträng, och formuläret skickar en GET** (59a § Beslut 1).
 * `router.get` mot SAMMA rutt som sidan ligger på, med `preserveState` så att
 * fälten står kvar. Ingen POST, ingen egen söksida: ett filtrerat läge är en
 * länk som går att spara, dela och backa ur med webbläsarens bakåtknapp.
 *
 * **Raden söker medan man skriver** (ADR-0062 § 7). Sökfältet skickar 300 ms
 * efter den senaste tangenttryckningen, och flera tryckningar inom fönstret ger
 * ett anrop. Enter — formulärets submit — skickar direkt och tar bort den
 * väntande fördröjningen. Kategorin och taggarna skickar direkt när de ändras.
 * Ett sökord som efter trimning är det servern redan tillämpat skickas inte;
 * tomt räknas som `null`.
 *
 * **En ny sökning avbryter den förra.** Inertia avbryter en pågående synkron
 * visit när en ny startar, men raden sparar ändå den förra visitens
 * `cancelToken` och avbryter den uttryckligen, så att ett äldre svar aldrig
 * skriver över ett nyare.
 *
 * **Historiken följer med.** Sökningar från fältet skickas med `replace`, så
 * att bakåtknappen inte går tillbaka en bokstav i taget. Kategorin, taggarna,
 * chipsens kryss och *Clear* lägger till en post i historiken som i dag.
 *
 * **Fältet står still medan svaret är på väg.** Det är aldrig `disabled`, och
 * `preserveState` behåller fokus och markör. `watch` på `filter` hoppar över
 * synkningen medan en fördröjning väntar eller en sökning från fältet är på
 * väg, så att serverns svar inte skriver över det användaren skriver.
 *
 * **Adressen är itemlistans, och den flyttade i issue 89** · [[ADR-0039
 * Containerns översikt]]. Före flytten var `/containers/{ulid}` listan och
 * den här raden pekade rätt av sig själv; nu är samma URL en översikt, och ett
 * filter som submittade dit hade tappat både listan och filtret. Sökvägen
 * stavas därför ut — komponenten känner inte sin egen rutt, och `props` bär
 * bara containerns ULID.
 *
 * **Komponenten filtrerar ingenting** (59a § Beslut 2). Den skickar tre värden
 * till servern och ritar det svar den får tillbaka; ingen rad sållas här, och
 * ingen klientmatchning sker på `name`. Servern äger urvalet.
 *
 * **Den ritar bara det containern och omfånget har** (59a § Beslut 5). `tags`
 * och `categories` kommer ur `ListTags` och `ListCategories` — anropade i
 * kontrollern, aldrig omskrivna här. Är den ena listan tom ritas dess fält
 * inte alls: ett filter utan alternativ är brus. En mottagare ser därmed bara
 * de taggar som sitter på items hon når och de kategorier som bär sådana
 * items, och kan inte filtrera på något hon inte ser.
 *
 * **Aktiva filter syns, och går att ta bort ett i taget** (59a § Beslut 6).
 * Chipsen byggs av resources/js/components/itemFilter.js — samma etiketter som
 * den tomma träfflistans mening, så de två inte kan glida isär. Ett filter
 * användaren inte kan se att det är på är ett filter hon tror är en bugg.
 *
 * **Ett bortfallet filter sägs rakt ut** (59a § Beslut 3). `filter.dropped`
 * sätts av kontrollern när en ULID i länken inte längre finns i hennes omfång —
 * taggen är raderad, kategorin flyttad — och raden är hela svaret på det. Ingen
 * 422, ingen redirect tillbaka till samma querysträng.
 *
 * **Ingen sträng i JavaScript** (59a § Beslut 8). Varje text kommer ur `t()`
 * med en nyckel under `item.index.filter*`.
 *
 * **Raden är responsiv** (ADR-0062 § 8). Under `sm:` tar sökfältet och
 * kategorin hela bredden var för sig, och taggarna bryter under dem. Från
 * `sm:` växer sökfältet för att fylla raden, och kategorin och taggarna
 * behåller sin bredd. Fälten är 44 px höga och bär designsystemets roller
 * ([[ADR-0042 Designsystemet]]) i stället för radens gamla `slate`- och
 * `blue`-klasser.
 *
 * Fälten speglas ur `filter`-propen med `watch`, inte bara vid montering:
 * chipsen kan ta bort ett filter utan att formuläret rörs, och då måste
 * kryssrutan och väljaren följa med.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Containerns taggar inom omfånget, ur ListTags. */
    tags: { type: Array, required: true },
    /* Containerns kategoriträd inom omfånget, ur ListCategories. */
    categories: { type: Array, required: true },
    /* Filtret så som servern tillämpade det: { q, tags, category, dropped }. */
    filter: { type: Object, required: true },
    /*
     * Läget raden står i, när det ska följa med filtret (issue 293 ·
     * [[ADR-0058 Trädet som tabell]] § 5 och 7). Trädläget skickar `tree`,
     * och då bär varje submit — även chipkryss och "rensa" — `view=tree`, så
     * att ett filter som sätts i trädet stannar där i stället för att landa i
     * listan. Utelämnad betyder listan, och då skrivs ingen `view` alls.
     */
    view: { type: String, default: null },
});

const { t } = useTranslations();

const options = computed(() => categoryOptions(props.categories));
const entries = computed(() => activeFilters(props.filter, props.tags, props.categories, t));

const q = ref('');
const selectedTags = ref([]);
const category = ref(null);

/* Fördröjningen mellan sista tangenttryckningen och sökningen (ADR-0062 § 7). */
const SEARCH_DELAY_MS = 300;

let searchTimer = null;
let cancelToken = null;
let visitSeq = 0;

const searchInFlight = ref(false);

/*
 * Vänteläget för hela filterraden (issue 68a § Beslut 4 och 5): chipkryssen
 * och "rensa" gör samma sorts anrop — en GET — så en enda flagga räcker. Medan
 * svaret är på väg är de stängda och säger att något händer i stället för att
 * se döda ut. Sökfältet självt är aldrig `disabled` (ADR-0062 § 7).
 */
const pending = ref(false);

function clearSearchTimer() {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
}

watch(
    () => props.filter,
    (filter) => {
        /*
         * Medan användaren skriver — eller en sökning från fältet är på väg —
         * får serverns svar inte skriva över fältet. Annars speglas filtret som
         * förut, så att chipsen och väljaren följer med.
         */
        if (searchTimer !== null || searchInFlight.value) {
            return;
        }

        q.value = filter.q ?? '';
        selectedTags.value = [...filter.tags];
        category.value = filter.category;
    },
    { immediate: true, deep: true },
);

/*
 * Skickar filtret som querysträng. Ett fält som inte betyder något — en blank
 * `q`, inga taggar, ingen kategori — lämnas UTANFÖR strängen i stället för att
 * skickas tomt: `?q=` och `?q=   ` är samma sak som ingen `q`, och en URL utan
 * brus går att läsa och dela.
 *
 * `preserveScroll` för att listan inte ska hoppa till toppen varje gång ett
 * kryss sätts, och `preserveState` för att fälten ska stå kvar. En sökning från
 * fältet skickas med `replace: true` (ADR-0062 § 7), så att bakåtknappen inte
 * går tillbaka en bokstav i taget; de andra vägarna lägger till en post.
 *
 * Den förra visiten avbryts uttryckligen innan en ny startar, och `visitSeq`
 * gör att en avbruten visits `onFinish` inte nollställer flaggorna för den som
 * tog över.
 */
function apply(overrides = {}, options = {}) {
    clearSearchTimer();

    if (cancelToken !== null) {
        cancelToken.cancel();
        cancelToken = null;
    }

    const seq = ++visitSeq;

    searchInFlight.value = options.fromField === true;

    const query = overrides.q ?? q.value;
    const tags = overrides.tags ?? selectedTags.value;
    const chosen = overrides.category === undefined ? category.value : overrides.category;

    const params = {};

    /*
     * Läget först, så adressen blir `?view=tree&q=…` och filtret stannar i
     * trädet. Utan `view`-propp skrivs parametern inte alls — listans
     * beteende är oförändrat.
     */
    if (props.view !== null && props.view !== '') {
        params.view = props.view;
    }

    if (String(query ?? '').trim() !== '') {
        params.q = String(query).trim();
    }

    if (tags.length > 0) {
        params.tags = tags;
    }

    if (chosen !== null && chosen !== '') {
        params.category = chosen;
    }

    router.get(`/containers/${props.containerUlid}/items`, params, {
        preserveState: true,
        preserveScroll: true,
        replace: options.replace === true,
        onCancelToken: (token) => {
            if (seq === visitSeq) {
                cancelToken = token;
            }
        },
        onStart: () => {
            if (seq === visitSeq) {
                pending.value = true;
            }
        },
        onFinish: () => {
            if (seq !== visitSeq) {
                return;
            }

            pending.value = false;
            searchInFlight.value = false;
            cancelToken = null;
        },
    });
}

/*
 * Sökningen från fältet. Ett ord som efter trimning är det servern redan
 * tillämpat skickas inte — tomt räknas som `null` — så att en fördröjning som
 * hinner gå ut efter en oförändrad text inte gör ett onödigt anrop.
 */
function search() {
    const next = q.value.trim();
    const current = String(props.filter.q ?? '').trim();

    if (next === current) {
        /*
         * Ordet är det servern redan tillämpat, men en sökning från fältet kan
         * vara på väg med ett ANNAT ord: användaren skrev något och raderade
         * det igen innan svaret kom. Den visiten avbryts, annars landar den och
         * filtrerar listan på ett ord fältet inte längre visar. En avbruten
         * visit lämnar ingen `onFinish` som nollställer flaggorna, så det görs
         * här; `visitSeq` höjs så att en sen återkallelse från den avbrutna
         * visiten inte rör den som eventuellt startar sedan.
         */
        if (searchInFlight.value) {
            visitSeq++;

            if (cancelToken !== null) {
                cancelToken.cancel();
                cancelToken = null;
            }

            pending.value = false;
            searchInFlight.value = false;
        }

        return;
    }

    apply({ q: next }, { replace: true, fromField: true });
}

function onSearchInput() {
    clearSearchTimer();

    searchTimer = setTimeout(() => {
        searchTimer = null;
        search();
    }, SEARCH_DELAY_MS);
}

/* Enter: skicka nu och ta bort den väntande fördröjningen. */
function submitSearch() {
    clearSearchTimer();
    search();
}

/* Ett kryss tar bort ETT filter och behåller resten. */
function remove(entry) {
    if (entry.key === 'q') {
        apply({ q: '' });

        return;
    }

    if (entry.key === 'tag') {
        apply({ tags: selectedTags.value.filter((ulid) => ulid !== entry.value) });

        return;
    }

    apply({ category: null });
}

onBeforeUnmount(() => {
    clearSearchTimer();

    if (cancelToken !== null) {
        cancelToken.cancel();
        cancelToken = null;
    }
});
</script>

<template>
    <form
        class="mt-6 flex flex-wrap items-end gap-3"
        role="search"
        :aria-label="t('item.index.filter_heading')"
        @submit.prevent="submitSearch"
    >
        <label
            for="item-filter-q"
            class="flex w-full flex-col gap-1 text-sm font-medium text-ink sm:min-w-48 sm:flex-1"
        >
            {{ t('item.index.filter_q') }}
            <input
                id="item-filter-q"
                v-model="q"
                type="text"
                name="q"
                class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                @input="onSearchInput"
            >
        </label>

        <!-- Kategorin: samma trädväljare som 57b:s formulär, byggd ur samma
             flata lista. Ingen kategori i containern, inget fält. -->
        <label
            v-if="options.length > 0"
            for="item-filter-category"
            class="flex w-full flex-col gap-1 text-sm font-medium text-ink sm:w-auto"
        >
            {{ t('item.index.filter_category') }}
            <select
                id="item-filter-category"
                v-model="category"
                name="category"
                class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink sm:w-auto"
                @change="apply()"
            >
                <option :value="null">{{ t('item.index.filter_category_all') }}</option>
                <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </label>

        <!-- Taggarna: kryssrutor med sin färgprick, samma prick som TagRow
             och ItemTagList ritar. Ingen tagg i omfånget, inget fält. -->
        <fieldset v-if="tags.length > 0" class="flex w-full flex-col gap-1 sm:w-auto">
            <legend class="text-sm font-medium text-ink">{{ t('item.index.filter_tags') }}</legend>

            <div class="flex flex-wrap gap-x-4 gap-y-1">
                <label
                    v-for="tag in tags"
                    :key="tag.ulid"
                    :for="`item-filter-tag-${tag.ulid}`"
                    class="flex min-h-11 min-w-11 items-center gap-2 text-sm font-normal text-ink"
                >
                    <input
                        :id="`item-filter-tag-${tag.ulid}`"
                        v-model="selectedTags"
                        type="checkbox"
                        name="tags[]"
                        :value="tag.ulid"
                        @change="apply()"
                    >
                    <span
                        aria-hidden="true"
                        class="inline-block h-3 w-3 shrink-0 rounded-full border border-ink-subtle"
                        :style="tag.color ? { backgroundColor: tag.color } : null"
                    />
                    {{ tag.name }}
                </label>
            </div>
        </fieldset>
    </form>

    <p v-if="filter.dropped" class="mt-2 text-sm text-ink-muted">
        {{ t('item.index.filter_dropped') }}
    </p>

    <div v-if="entries.length > 0" class="mt-4 flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-ink">{{ t('item.index.filter_active') }}</span>

        <span
            v-for="entry in entries"
            :key="`${entry.key}-${entry.value ?? ''}`"
            class="flex items-center gap-1 rounded bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted"
        >
            {{ entry.label }}
            <button
                type="button"
                :disabled="pending"
                :aria-label="t('item.index.filter_remove', { filter: entry.label })"
                class="inline-flex min-h-11 min-w-11 items-center justify-center font-medium text-ink-muted"
                @click="remove(entry)"
            >
                &times;
            </button>
        </span>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center text-sm text-accent underline"
            @click="apply({ q: '', tags: [], category: null })"
        >
            {{ pending ? t('common.pending.default') : t('item.index.filter_clear') }}
        </button>
    </div>
</template>
