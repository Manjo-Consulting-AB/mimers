<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Träfflistan bredvid kartan, se issue 299 · [[M34 Kartan med en mitt]] § 299
 * och [[ADR-0059 Kartan med en mitt]] § 10.
 *
 * **Filtret väljer mitten men gömmer aldrig något runt den.** Listan ritar
 * exakt de träffar servern skickade (`App\Actions\Item\ListItems`), och kartan
 * ritar samma grannar som utan filter. Komponenten sållar ingenting själv och
 * känner inte till omfånget — servern äger urvalet, som i listan.
 *
 * **En träff leder till kartan med sig själv som mitt** — `?view=map&focus=`
 * — och filtret följer med i adressen, så att mittbytet stannar i samma
 * träfflista. Den träff som REDAN är mitten är ingen länk: den bär
 * `aria-current` i stället, samma regel som mittnoden i kartan.
 *
 * ***Show all N in list*** följer när `total` är större än antalet noder, och
 * leder till listläget med samma filter. Servern skickar högst tjugo noder och
 * hela antalet, så vyn räknar ingenting själv.
 *
 * **Ett filter utan träffar ritar listans tomma text** (`item.index.filter_empty`)
 * med de filter användaren själv satt. Meningen säger ingenting om vad
 * omfånget höll tillbaka — samma ord som listan (issue 73 § Beslut 6).
 *
 * Träffytorna är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och texten
 * kommer ur `lang/` som all annan text ([[ADR-0013 Språk och i18n]]).
 */
const props = defineProps({
    /* `{nodes: [{ulid, name}], total}` ur `ListItems`, i listans ordning. */
    hits: { type: Object, required: true },
    /* Kartans mitt: `{kind, ulid, …}` — träffen som är mitten ritas utan länk. */
    center: { type: Object, required: true },
    /* Containerns ULID — träffarnas adresser byggs ur den. */
    containerUlid: { type: String, required: true },
    /* Filtret som querysträng, utan inledande `?`, och tomt när inget är satt. */
    filterQuery: { type: String, default: '' },
    /* Filtren uppräknade, ur `filterSummary` — listans tomma mening. */
    summary: { type: String, required: true },
});

const { t } = useTranslations();

const base = computed(() => `/containers/${props.containerUlid}/items`);

/* Samma form som växelns `filterTail`: `&` och strängen, eller ingenting. */
const filterTail = computed(() => (props.filterQuery === '' ? '' : `&${props.filterQuery}`));

/* En träff centrerar kartan och behåller filtret i adressen. */
const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}${filterTail.value}`;

/* Listläget med samma filter — ingen `view`, som växelns *Lista*. */
const listHref = computed(
    () => (props.filterQuery === '' ? base.value : `${base.value}?${props.filterQuery}`),
);

/* Sant när träffen är mitten: containern är aldrig en träff. */
const isCurrent = (node) => props.center.kind === 'item' && props.center.ulid === node.ulid;

/* Fler träffar finns än listan ritar: *Show all N in list*. */
const hasMore = computed(() => props.hits.total > props.hits.nodes.length);
</script>

<template>
    <!--
        Till vänster om kartan från `md:` och ovanför den under `md:`:
        DOM-ordningen är densamma, och `Index.vue` väljer riktning.
    -->
    <aside :aria-label="t('item.map.hits')" class="md:w-64 md:shrink-0">
        <!-- Ett filter utan träffar säger samma sak som listan. -->
        <p v-if="hits.nodes.length === 0" class="text-slate-700">
            {{ t('item.index.filter_empty', { filters: summary }) }}
        </p>

        <template v-else>
            <ul class="flex flex-col">
                <li v-for="node in hits.nodes" :key="node.ulid">
                    <span
                        v-if="isCurrent(node)"
                        aria-current="true"
                        class="flex min-h-11 items-center rounded-control bg-surface-sunken px-2 font-medium text-ink"
                    >
                        {{ node.name }}
                    </span>

                    <Link
                        v-else
                        :href="focusHref(node)"
                        class="flex min-h-11 items-center rounded-control px-2 text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        {{ node.name }}
                    </Link>
                </li>
            </ul>

            <Link
                v-if="hasMore"
                :href="listHref"
                class="mt-2 inline-flex min-h-11 items-center rounded-control px-2 font-medium text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('item.map.show_all', { count: hits.total }) }}
            </Link>
        </template>
    </aside>
</template>
