<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemFilterBar from '../../../components/ItemFilterBar.vue';
import ItemStructureTree from '../../../components/ItemStructureTree.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import ItemViewSwitch from '../../../components/ItemViewSwitch.vue';
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
 *
 * **Fliken har två lägen sedan issue 154** · [[ADR-0046 Containerns karta]]:
 * *Lista*, som är sidan som den var och förblir förval, och *Träd*, som ritar
 * containerns struktur med `ItemStructureTree`. Läget står i querysträngen
 * (`?view=tree`) och `view`-proppen är serverns läsning av den — vyn håller
 * inget eget tillstånd, och därför överlever läget en omladdning och en delad
 * länk. `ItemViewSwitch` ritar växeln; *Karta* är § 157 och finns inte i den
 * förrän kartan finns.
 *
 * **Trädläget filtrerar ingenting.** Trädet är containern som användaren når
 * (App\Actions\Item\ResolveItemTree, samma rotregel som detaljvyns
 * vänsterpanel), medan filtret hör till listan — därför ritas filterraden
 * bara i listläget, och därför bär växelns länkar filtret med sig: ett
 * flikbyte ska kunna gå tillbaka till samma träfflista. Strukturen kommer i
 * `structure`-proppen, och den finns BARA i trädläget — `null` betyder "inte
 * hämtad", och en tom lista hade varit ett svar servern hade gett.
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
    /*
     * Läget fliken står i, ur serverns läsning av `?view=` (issue 154 ·
     * [[ADR-0046 Containerns karta]]): `list` eller `tree`. Servern skriver
     * alltid ett giltigt värde — ett okänt eller saknat läge är `list` — och
     * vyn faller därför aldrig tillbaka själv på något eget.
     */
    view: { type: String, required: true },
    /*
     * Containerns struktur i trädläget, ur App\Actions\Item\ResolveItemTree:
     * `{ulid, name, children}` per nod, rötterna enligt [[ADR-0041 Itemets vy]]
     * och ett item med två föräldrar på båda ställena.
     *
     * `null` betyder INTE HÄMTAD — servern skickar den bara när läget är
     * trädet, av samma skäl som historikens rader bara kommer när den fliken
     * är aktiv (issue 116): en yta ingen ser ska inte kosta en fråga. En tom
     * lista är något annat och ett svar servern HAR gett: hon når ingenting,
     * eller containern är tom, och de två går inte att skilja åt (issue 73
     * § Beslut 6).
     */
    structure: { type: Array, default: null },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget (issue 152 ·
     * [[ADR-0048 Mobilen och plusknappen]] § 2), eller null. Här skapar
     * knappen ett item i containern — samma grind som `can.create` nedan
     * (`ContainerPolicy::createItem()`), så knappen och raden *Nytt item*
     * kommer och går tillsammans.
     */
    create: { type: Object, default: null },
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

/*
 * Sidans adress utan läge, och filtret som servern tillämpade — som
 * querysträng. Växelns länkar byggs ur de två och ur ingenting annat: det
 * aktuella läget lämnas ur adressen (så *Lista* skriver ingen `view` alls,
 * som översiktsfliken inte skriver någon `tab`), och filtret följer med så att
 * ett läge man kommer tillbaka till är samma träfflista.
 *
 * Filtret läses ur `filter`-proppen och inte ur adressen: det är det filter
 * servern FAKTISKT använde, med bortfallna värden redan borta (issue 59a
 * § Beslut 3) — en länk byggd på adressens råtext hade kunnat bära med sig en
 * tagg som inte längre finns.
 */
const base = computed(() => `/containers/${props.container.ulid}/items`);

const filterQuery = computed(() => {
    const params = new URLSearchParams();

    if (props.filter.q !== null) {
        params.set('q', props.filter.q);
    }

    for (const tag of props.filter.tags) {
        params.append('tags[]', tag);
    }

    if (props.filter.category !== null) {
        params.set('category', props.filter.category);
    }

    return params.toString();
});

/*
 * Växelns lägen, i ritad ordning. Det FÖRSTA är förvalet och skrivs utan
 * `view` — samma val som ItemTabs gör med `tab` (issue 100 och 102) — och
 * `map` står inte här: kartan är § 157, och läget visas först när den finns
 * ([[ADR-0046 Containerns karta]] § Beslut).
 */
const views = computed(() => {
    const query = filterQuery.value;
    const withView = query === '' ? '?view=tree' : `?view=tree&${query}`;

    return [
        { key: 'list', label: t('item.view.list'), href: query === '' ? base.value : `${base.value}?${query}` },
        { key: 'tree', label: t('item.view.tree'), href: `${base.value}${withView}` },
    ];
});
</script>

<template>
    <ContainerLayout :container="container" :create="create">
        <Head :title="t('item.index.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.index.heading') }}</h1>

        <Link
            v-if="can.create"
            :href="`/containers/${container.ulid}/items/create`"
            class="mt-4 inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
        >
            {{ t('item.index.create') }}
        </Link>

        <!--
            Växeln (issue 154 · [[ADR-0046 Containerns karta]]). Den står över
            båda ytorna och byter mellan dem med en adress: *Lista* är förvalet
            och skrivs utan `view`, *Träd* bär `?view=tree`. Läget kommer ur
            `view`-proppen — serverns läsning av adressen — så vyn håller
            ingenting i minnet och en omladdning landar i samma läge.
        -->
        <ItemViewSwitch
            class="mt-6"
            :views="views"
            :current="view"
            :label="t('item.view.label')"
        />

        <!-- Listan, oförändrad: filterraden, de tre tomma lägena och raderna. -->
        <template v-if="view === 'list'">
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
        </template>

        <!--
            Trädläget (issue 154). Containerns struktur, ritad av
            ItemStructureTree med fällknappar: rötterna syns och grenarna
            väntar, så en container med sjuhundra items inte står mellan
            användaren och sidan. Trädet är HELA containern inom omfånget —
            filtret hör till listan och ritas därför inte här — och varje led
            är en länk till sitt item (`?path=` följer med, se
            ItemStructureTree.vue).

            Ingen rubrik och ingen egen tom-text: växeln säger vilket läge man
            står i, och ett tomt träd ritar ingenting. Att säga "containern är
            tom" här hade varit samma sak som listans rad, och att säga något
            om vad som dolts är förbjudet (issue 73 § Beslut 6).
        -->
        <section v-else class="mt-6">
            <ItemStructureTree
                collapsible
                :nodes="structure ?? []"
                :container-ulid="container.ulid"
            />
        </section>
    </ContainerLayout>
</template>
