<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemFilterBar from '../../../components/ItemFilterBar.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import { activeFilters, filterSummary } from '../../../components/itemFilter.js';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Containerns itemlista — containerns förstasida till och med issue 88, se
 * issue 57a § Beslut 1, 4, 6, 8 och 9, och issue 59a § Beslut 1–8.
 *
 * **Sidan flyttade i issue 89** · [[ADR-0039 Containerns översikt]]:
 * containerns egen URL svarar numera med översikten
 * (pages/Containers/Overview.vue), och listan ligger på
 * `/containers/{ulid}/items`. Ingenting i vyn ändrades av flytten — bara
 * adressen — och filtret i querysträngen fungerar oförändrat på den nya URL:en.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Sorteringen kommer från servern** (`orderBy('name')` i
 * App\Actions\Item\ListItem) och vyn sorterar aldrig om (57a § Beslut 8).
 *
 * **Filtret är querysträng och servern äger urvalet** (59a § Beslut 1 och 2).
 * Sidan filtrerar ingenting själv — ingen `computed` som sållar rader, ingen
 * klientmatchning på `name`. Formuläret och de aktiva filtren bor i
 * resources/js/components/ItemFilterBar.vue; här ritas bara resultatet.
 *
 * **Tre lägen i den tomma listan, och inget av dem vet om omfånget**
 * (59a § Beslut 4, issue 73 § Beslut 6):
 *
 *   - inga filter, inga rader  → containern är tom
 *   - filter, inga rader       → de filter ANVÄNDAREN satt, uppräknade
 *   - filter, några rader      → ingenting extra
 *
 * Meningen räknar upp det användaren själv satt — sökordet, taggarnas namn,
 * kategorins namn — och säger aldrig hur många rader som fanns utan filtren,
 * hur många som dolts, eller att resultatet skulle vara ofullständigt. En
 * omfångsbegränsad mottagare får därför ordagrant samma mening som ägaren:
 * etiketterna byggs av resources/js/components/itemFilter.js, som inte
 * känner till omfång alls.
 *
 * **Sidan visar antalet rader den ritar** och ingenting mer (57a § Beslut 4).
 * Ingen totalsumma, ingen "av N", ingen rad om att något dolts.
 *
 * **Kategorinamnet slås upp i `categories`**, som kontrollern bygger ur de
 * redan eager-laddade relationerna (ULID → namn). `ItemResource` bär bara
 * kategorins ULID, och uppslaget hör därför BREDVID resursen och inte inuti
 * den — samma linje som issue 54 § Beslut 9. Saknas uppslaget utelämnas
 * raden; vyn hittar aldrig på ett värde. `categoryTree` är något annat: hela
 * trädet, till filterradens väljare.
 *
 * **Serienumret ritas inte här** (57a § Beslut 8). Det hör till detaljvyn.
 *
 * **Ingen miniatyr** (57a § Beslut 9). Bilagorna är issue 60 och 61, och att
 * rita en miniatyr här hade betytt en egen väg till filoriginet innan
 * [[ADR-0019 Filleverans]] fått sin yta. Rutan nedan lämnar platsen så att
 * issue 61 kan fylla den utan att raden byter form.
 *
 * **`can.create` ritar skapaknappen** (issue 57b § Beslut 2). Flaggan är
 * `ContainerPolicy::createItem()` och sätts mot CONTAINERN, för det är grinden
 * skapandet prövar — en omfångsbegränsad mottagare får `false` och ser ingen
 * knapp: hon skapar barn-items under det hon nått, och den ytan är issue 58.
 * Flaggan är presentation; ruttens `Gate::authorize()` gäller oavsett vad
 * sidan visade.
 *
 * **Radens status kommer ur `statuses`** (issue 92 · [[ADR-0040 Underträdets
 * summor]]): itemets ULID → `ok` eller `overdue`, räknat på servern över
 * itemets underträd. Vyn räknar ingenting själv — den slår upp och översätter,
 * och TEXTEN ligger i `lang/` precis som resten av sidans ord. Uppslaget
 * ligger bredvid `ItemResource` av samma skäl som `categories` gör det:
 * resursen delas med `/api`, som inte har bett om fältet.
 */
const props = defineProps({
    container: { type: Object, required: true },
    items: { type: Array, required: true },
    /* Kategori-ULID → namn, för de kategorier raderna pekar på. */
    categories: { type: Object, required: true },
    /* Item-ULID → status: `ok` eller `overdue`. */
    statuses: { type: Object, required: true },
    /* Containerns taggar inom omfånget, ur ListTags — filterradens kryssrutor. */
    tags: { type: Array, required: true },
    /* Containerns kategoriträd inom omfånget, ur ListCategories — filterradens väljare. */
    categoryTree: { type: Array, required: true },
    /* Filtret som servern tillämpade: { q, tags, category, dropped }. */
    filter: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Läses ur `filter` och inte ur de uppräknade etiketterna: frågan är om
 * användaren har ett filter på, inte om vyn lyckades sätta namn på det.
 */
const hasFilter = computed(() => props.filter.q !== null
    || props.filter.tags.length > 0
    || props.filter.category !== null);

const summary = computed(() => filterSummary(activeFilters(props.filter, props.tags, props.categoryTree, t), t));
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.index.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.index.heading') }}</h1>

        <Link
            v-if="can.create"
            :href="`/containers/${container.ulid}/items/create`"
            class="mt-4 inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
        >
            {{ t('item.index.create') }}
        </Link>

        <ItemFilterBar
            :container-ulid="container.ulid"
            :tags="tags"
            :categories="categoryTree"
            :filter="filter"
        />

        <p v-if="items.length === 0 && !hasFilter" class="mt-8 text-slate-700">
            {{ t('item.index.empty') }}
        </p>

        <p v-else-if="items.length === 0" class="mt-8 text-slate-700">
            {{ t('item.index.filter_empty', { filters: summary }) }}
        </p>

        <ul v-else class="mt-8 flex flex-col divide-y divide-slate-200">
            <li v-for="item in items" :key="item.ulid" class="flex items-start gap-4 py-4">
                <div
                    aria-hidden="true"
                    class="h-12 w-12 shrink-0 rounded border border-slate-200 bg-slate-50"
                />

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-3">
                        <Link
                            :href="`/containers/${container.ulid}/items/${item.ulid}`"
                            class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                        >
                            {{ item.name }}
                        </Link>

                        <!-- Ordet är dämpat och undantaget syns: mockupen
                             sätter OK på varje rad, och poängen med raden är
                             att det som AVVIKER ska hittas utan att öppna
                             sextio items. -->
                        <span
                            class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                            :class="statuses[item.ulid] === 'overdue'
                                ? 'bg-red-50 text-red-700'
                                : 'text-slate-500'"
                        >
                            {{ t(`item.index.status_${statuses[item.ulid]}`) }}
                        </span>
                    </div>

                    <p
                        v-if="categories[item.category] || item.manufacturer || item.model"
                        class="mt-1 flex flex-wrap gap-x-4 text-sm text-slate-600"
                    >
                        <span v-if="categories[item.category]">{{ categories[item.category] }}</span>
                        <span v-if="item.manufacturer">{{ item.manufacturer }}</span>
                        <span v-if="item.model">{{ item.model }}</span>
                    </p>

                    <ItemTagList class="mt-2" :tags="item.tags" />
                </div>
            </li>
        </ul>
    </ContainerLayout>
</template>
