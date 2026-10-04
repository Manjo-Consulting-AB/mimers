<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import UiCheckbox from './UiCheckbox.vue';
import UiInput from './UiInput.vue';
import { documentFilterQuery } from './documentFilter.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterkolumnen på containerns dokumentflik, se M24 · issue 217 · Beslut 1–6 och
 * `docs/Design/dokument.png` (testarnas fynd 2026-10-03,
 * `docs/Design/findings_20261003_b/docs.png`).
 *
 * **Kolumnen ställer samma fråga som `DocumentFilterBar`, men som flerval.**
 * Servern läste redan flera värden per grupp (issue 216 § Beslut 4), och
 * rutorna skickar listor: `kind[]=image&kind[]=document`. Den mobila
 * filterbaren står kvar orörd under `lg:` och behåller enkelvalet — kolumnen
 * ritas bara över `lg:` (Beslut 5).
 *
 * **En ändring skickas direkt, och ingen *Apply*-knapp finns** (Beslut 3).
 * Varje kryss och varje datumfält gör samma anrop som sorteringen i
 * verktygsraden: en GET mot samma rutt, med filtret som querysträng och utan
 * sidnummer — ett nytt filter är en ny fråga och börjar på sida ett. `sort`
 * och läget `view` följer med i varje anrop, så en filtrering i rutnätet inte
 * tyst byter till listan eller tappar ordningen.
 *
 * **Komponenten filtrerar ingenting** — det är serverns sak. Det enda som
 * sållas här är ALTERNATIVEN i item-gruppen: sökfältet gömmer rader i listan
 * användaren redan fått, och skickar ingenting.
 *
 * **Ett filter utan alternativ ritas inte.** Item- och uppladdargrupperna
 * kräver `filterOptions.items` respektive `filterOptions.uploaders` — en
 * grupp med bara *Alla* är brus, samma regel som i filterbaren. Typen ritas
 * alltid: dess tre värden är fasta, och en träffbild utan bilder är ett
 * giltigt svar.
 *
 * **Adressen stavas ut.** Komponenten känner inte sin egen rutt — `props` bär
 * bara containerns ULID — samma skäl som i ItemFilterBar och
 * DocumentFilterBar.
 *
 * **Ingen sträng i JavaScript** ([[ADR-0013 Språk och i18n]]): varje text
 * kommer ur `t()` under `container.documents.*`. De tre typorden är
 * `item.attachment.kind.*` — samma ord som raden bär.
 *
 * Fälten speglas ur `filter`-proppen med `watch`, inte bara vid montering:
 * servern är den som avgör vad som gäller, och efter en bakåtknapp, ett
 * bortfallet värde eller en omladdning ska rutorna visa det filtret och inte
 * det användaren kryssade sist.
 */
const props = defineProps({
    /* Containerns ULID — adressen byggs ur den. */
    containerUlid: { type: String, required: true },
    /*
     * Filtret så som servern tillämpade det:
     * `{q, kind, item, uploader, from, to, sort}` — listorna är `[]` när
     * gruppen inte filtrerar.
     */
    filter: { type: Object, required: true },
    /*
     * Alternativen med antalen: `{items, uploaders, kinds, total}`, allt ur
     * serverns omfång. Antalen följer inte det valda filtret — siffran
     * bredvid *Image* är alltid antalet bilder användaren når.
     */
    filterOptions: { type: Object, required: true },
    /* Läget — `grid` eller `list` — som följer med varje anrop. */
    view: { type: String, required: true },
});

const { t } = useTranslations();

/*
 * Typerna, i `attachment.kind`s egen ordning. Listan är fast och inte en
 * propp: `kind` kan bara anta de tre värdena, och en grupp byggd ur raderna
 * hade tappat en typ som ingen laddat upp ännu — samma skäl som i
 * DocumentFilterBar.
 */
const kinds = ['image', 'document', 'other'];

const kind = ref([]);
const item = ref([]);
const uploader = ref([]);
const from = ref('');
const to = ref('');
const itemSearch = ref('');

/*
 * *All documents* och *All users* är IKRYSSADE när sin grupp är tom, och de är
 * vanliga refs och ingen `computed`: en computed hade gett samma värde före och
 * efter ett klick, och då patchar Vue inte `checked` — rutan hade stått
 * avkryssad på skärmen medan listan visade allt. Refsen byter värde vid
 * klicket, rutan ritas om, och `watch` nedan rättar dem mot serverns svar.
 */
const allKinds = ref(true);
const allUploaders = ref(true);

/* Vänteläget för hela kolumnen (issue 68a § Beslut 4 och 5): varje kryss,
   varje datumfält och "Rensa alla" gör samma sorts anrop — en GET — så en
   enda flagga räcker. Medan svaret är på väg är kontrollerna stängda. */
const pending = ref(false);

watch(
    () => props.filter,
    (filter) => {
        kind.value = [...filter.kind];
        item.value = [...filter.item];
        uploader.value = [...filter.uploader];
        from.value = filter.from ?? '';
        to.value = filter.to ?? '';
        allKinds.value = filter.kind.length === 0;
        allUploaders.value = filter.uploader.length === 0;
    },
    { immediate: true, deep: true },
);

/* Sökfältet sållar bara de ritas alternativen, aldrig listan på servern. */
const visibleItems = computed(() => {
    const needle = itemSearch.value.trim().toLowerCase();

    if (needle === '') {
        return props.filterOptions.items;
    }

    return props.filterOptions.items.filter((option) => option.name.toLowerCase().includes(needle));
});

/*
 * Skickar filtret som querysträng. `filter`-proppen bär `sort` och `view` står
 * utanför den, så båda följer med utan att nämnas här. Formen bor i
 * documentFilter.js, och sidnumret finns inte i den: ett nytt filter är en ny
 * fråga och börjar på sida ett. `preserveScroll` för att listan inte ska hoppa
 * till toppen, och `preserveState` för att rutorna ska stå kvar.
 */
function apply(overrides) {
    router.get(
        `/containers/${props.containerUlid}/documents?${documentFilterQuery({ ...props.filter, ...overrides }, props.view)}`,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => { pending.value = true; },
            onFinish: () => { pending.value = false; },
        },
    );
}

/*
 * Rutorna bär sina grupper som arrayer. Den lokala raden sätts FÖRST och
 * anropet efter: rutan svarar direkt i handen, och `watch` rättar den mot
 * serverns svar när det kommer — samma ordning som fälten i
 * DocumentFilterBar.
 */
const kindModel = computed({
    get: () => kind.value,
    set: (values) => {
        kind.value = values;
        apply({ kind: values });
    },
});

const itemModel = computed({
    get: () => item.value,
    set: (values) => {
        item.value = values;
        apply({ item: values });
    },
});

const uploaderModel = computed({
    get: () => uploader.value,
    set: (values) => {
        uploader.value = values;
        apply({ uploader: values });
    },
});

/* *All documents* tömmer typgruppen. Ett klick på den — av eller på — betyder
   *ingen typ vald*, och `watch` kryssar i rutan igen när svaret kommer: den
   tomma gruppen ÄR "alla", och en tom grupp kan `v-model` mot en array inte
   uttrycka. */
function toggleAllKinds(value) {
    allKinds.value = value;
    kind.value = [];
    apply({ kind: [] });
}

/* *All users* tömmer uppladdargruppen, på samma sätt. */
function toggleAllUploaders(value) {
    allUploaders.value = value;
    uploader.value = [];
    apply({ uploader: [] });
}

/* Datumfältet skickar så snart ett datum väljs eller töms (`@change`), och
   båda ändarna följer med i samma anrop. */
function changeDates() {
    apply({ from: from.value, to: to.value });
}

/* *Rensa alla* tömmer varje filtergrupp — `q` inräknad — och BEHÅLLER `sort`
   och läget: ordningen och vyn är inga filter, och en rensning ska inte byta
   dem. */
function clearAll() {
    kind.value = [];
    item.value = [];
    uploader.value = [];
    from.value = '';
    to.value = '';
    itemSearch.value = '';
    allKinds.value = true;
    allUploaders.value = true;

    apply({ q: null, kind: [], item: [], uploader: [], from: null, to: null });
}
</script>

<template>
    <aside class="rounded-card border border-border bg-surface p-4">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-body font-semibold text-ink">
                {{ t('container.documents.filter_heading') }}
            </h2>

            <button
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center text-meta text-accent underline disabled:opacity-50"
                @click="clearAll"
            >
                {{ pending ? t('common.pending.default') : t('container.documents.filter_clear_all') }}
            </button>
        </div>

        <!-- Typen (Beslut 2). *All documents* bär totalen och är ikryssad när
             gruppen är tom; de tre typerna bär sina egna antal. -->
        <details open class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('container.documents.type') }}
            </summary>

            <ul class="mt-1 flex flex-col">
                <li>
                    <UiCheckbox
                        id="document-filter-kind-all"
                        :model-value="allKinds"
                        :disabled="pending"
                        @update:model-value="toggleAllKinds"
                    >
                        {{ t('container.documents.filter_all_documents') }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ filterOptions.total }}</span>
                    </UiCheckbox>
                </li>

                <li v-for="value in kinds" :key="value">
                    <UiCheckbox
                        :id="`document-filter-kind-${value}`"
                        v-model="kindModel"
                        :value="value"
                        :disabled="pending"
                    >
                        {{ t(`item.attachment.kind.${value}`) }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ filterOptions.kinds[value] }}</span>
                    </UiCheckbox>
                </li>
            </ul>
        </details>

        <!-- Itemet (Beslut 2): bara de items användaren når. Sökfältet sållar
             alternativen på namn och skickar ingenting. -->
        <details v-if="filterOptions.items.length > 0" open class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('container.documents.filter_item') }}
            </summary>

            <label for="document-filter-item-search" class="sr-only">
                {{ t('container.documents.filter_item_search') }}
            </label>

            <UiInput
                id="document-filter-item-search"
                v-model="itemSearch"
                type="search"
                :disabled="pending"
                class="mt-1 w-full"
            />

            <ul class="mt-1 flex flex-col">
                <li v-for="option in visibleItems" :key="option.ulid">
                    <UiCheckbox
                        :id="`document-filter-item-${option.ulid}`"
                        v-model="itemModel"
                        :value="option.ulid"
                        :disabled="pending"
                    >
                        {{ option.name }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ option.count }}</span>
                    </UiCheckbox>
                </li>
            </ul>
        </details>

        <!-- Uppladdaren: bara de uppladdare användaren redan ser. -->
        <details v-if="filterOptions.uploaders.length > 0" open class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('container.documents.filter_uploader') }}
            </summary>

            <ul class="mt-1 flex flex-col">
                <li>
                    <UiCheckbox
                        id="document-filter-uploader-all"
                        :model-value="allUploaders"
                        :disabled="pending"
                        @update:model-value="toggleAllUploaders"
                    >
                        {{ t('container.documents.filter_all_uploaders') }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ filterOptions.total }}</span>
                    </UiCheckbox>
                </li>

                <li v-for="option in filterOptions.uploaders" :key="option.ulid">
                    <UiCheckbox
                        :id="`document-filter-uploader-${option.ulid}`"
                        v-model="uploaderModel"
                        :value="option.ulid"
                        :disabled="pending"
                    >
                        {{ option.name }}
                        <span class="ml-auto text-meta text-ink-subtle">{{ option.count }}</span>
                    </UiCheckbox>
                </li>
            </ul>
        </details>

        <!-- Datumintervallet (Beslut 2): ihopfällt utom när ett datum är valt,
             så en öppen grupp alltid betyder att intervallet filtrerar. -->
        <details :open="filter.from !== null || filter.to !== null" class="mt-2">
            <summary class="cursor-pointer text-meta font-semibold text-ink">
                {{ t('container.documents.filter_dates') }}
            </summary>

            <div class="mt-1 flex flex-col gap-3">
                <label for="document-filter-dates-from" class="flex flex-col gap-1 text-meta text-ink-muted">
                    {{ t('container.documents.filter_from') }}
                    <UiInput
                        id="document-filter-dates-from"
                        v-model="from"
                        type="date"
                        :disabled="pending"
                        @change="changeDates"
                    />
                </label>

                <label for="document-filter-dates-to" class="flex flex-col gap-1 text-meta text-ink-muted">
                    {{ t('container.documents.filter_to') }}
                    <UiInput
                        id="document-filter-dates-to"
                        v-model="to"
                        type="date"
                        :disabled="pending"
                        @change="changeDates"
                    />
                </label>
            </div>
        </details>
    </aside>
</template>
