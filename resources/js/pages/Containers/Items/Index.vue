<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../../layouts/AppLayout.vue';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ContainerMap from '../../../components/ContainerMap.vue';
import ItemFilterBar from '../../../components/ItemFilterBar.vue';
import ItemRow from '../../../components/ItemRow.vue';
import ItemStructureTree from '../../../components/ItemStructureTree.vue';
import ItemViewSwitch from '../../../components/ItemViewSwitch.vue';
import { activeFilters, filterSummary } from '../../../components/itemFilter.js';
import { useTranslations } from '../../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, { create: props.create }],
        [ContainerLayout, { hero: 'compact', container: props.container, can: props.can }],
    ],
});

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
 * **Kategorinamnet slås upp i `categories`** — proppen står kvar även sedan
 * raden slutade rita den (issue 212). Servern bygger den ur de redan
 * eager-laddade relationerna (ULID → namn), och uppslaget hör BREDVID
 * resursen och inte inuti den, för `ItemResource` bär bara kategorins ULID —
 * samma linje som issue 54 § Beslut 9. `categoryTree` är något annat: hela
 * trädet, till filterradens väljare.
 *
 * **Serienumret ritas inte här** (57a § Beslut 8). Det hör till detaljvyn.
 *
 * **Raden är `ItemRow` sedan issue 285** · [[M32 Formspråket]]: raden med
 * miniatyr, namn, beskrivning och pil bor i
 * `resources/js/components/ItemRow.vue`, och översiktens itempanel ritar
 * samma komponent. Här står bara listan — formen på raden ägs av den filen.
 *
 * **Raden ritar itemets EGET omslag** (issue 212 · [[ADR-0050
 * Desktopdesignen]]): `covers` är itemets ULID → `{ulid, hasThumb}` eller
 * null, och urvalet är App\Actions\Item\ResolveItemCover — samma bild och
 * samma omfång som servern räknade, ritat som en miniatyr mot
 * `/files/{ulid}?variant=thumb` ([[ADR-0019 Filleverans]]). Servern har
 * prövat att derivatet finns (`hasThumb`); utan det ritas `item`-ikonen i en
 * neutral ruta, för en `<img>` mot en variant som inte finns är en trasig bild
 * (issue 61b § Beslut 1). Containerns eget omslag läses ALDRIG i raden — ett
 * item utan bild ärver inte containerns foto.
 *
 * **`can.create` ritar skapaknappen** (issue 57b § Beslut 2). Flaggan är
 * `ContainerPolicy::createItem()` och sätts mot CONTAINERN, för det är grinden
 * skapandet prövar — en omfångsbegränsad mottagare får `false` och ser ingen
 * knapp: hon skapar barn-items under det hon nått, och den ytan är issue 58.
 * Flaggan är presentation; ruttens `Gate::authorize()` gäller oavsett vad
 * sidan visade.
 *
 * **Statusen visas bara i kartläget** (issue 212 · [[ADR-0050
 * Desktopdesignen]]). `statuses` är itemets ULID → `ok` eller `overdue`,
 * räknat på servern över itemets underträd (issue 92 · [[ADR-0040
 * Underträdets summor]]). Listans rad ritade den förr, men mockupen bär namn,
 * beskrivning och en pil — statusen hör till kartan, som ritar den per nod
 * (`ContainerMapNode.vue`), och proppen står kvar för kartans skull. Vyn
 * räknar ingenting själv — den slår upp och översätter, och TEXTEN ligger i
 * `lang/` precis som resten av sidans ord. Uppslaget ligger bredvid
 * `ItemResource` av samma skäl som `categories` gör det: resursen delas med
 * `/api`, som inte har bett om fältet.
 *
 * **Fliken har tre lägen sedan issue 154 och 157** · [[ADR-0046 Containerns
 * karta]]: *Lista*, som är sidan som den var och förblir förval, *Träd*, som
 * ritar containerns struktur med `ItemStructureTree`, och *Karta*, som ritar
 * samma struktur som noder med en öppen gren per nivå (`ContainerMap`). Läget
 * står i querysträngen (`?view=tree`, `?view=map`) och `view`-proppen är
 * serverns läsning av den — vyn håller inget eget tillstånd, och därför
 * överlever läget en omladdning och en delad länk. `ItemViewSwitch` ritar
 * växeln, och ordningen i `views` är bindande: det första läget är förvalet.
 *
 * **Trädläget tar samma filter som listan sedan issue 293**
 * ([[ADR-0058 Trädet som tabell]] § 5). `q`, `tags[]` och `category` är
 * samma filter som i listan, träffarna är `ListItems` och trädet visar varje
 * träff plus vägen från roten ned till den. Filterraden ritas därför i både
 * list- och trädläget, medan kartläget fortfarande inte filtreras: kartan är
 * containern som användaren når (App\Actions\Item\ResolveItemMap, samma
 * rotregel som detaljvyns vänsterpanel). Växelns länkar bär filtret med sig:
 * ett flikbyte ska kunna gå tillbaka till samma träfflista. Strukturen och
 * kartan kommer i `structure`- och `map`-propparna, och de finns BARA i sina
 * egna lägen — `null` betyder "inte hämtad", och en tom lista hade varit ett
 * svar servern hade gett.
 *
 * **Kartans öppna väg bor i adressen och inte i vyn.** `?path=` bär ledet från
 * roten ned till noden man står på, och `ContainerMap` bygger den nya adressen
 * när en nod öppnas. Servern läser samma sträng igen, så en omladdning och en
 * bakåtknapp landar i samma läge — samma konstruktion som `?path=` i itemvyn
 * (issue 95).
 */
const props = defineProps({
    container: { type: Object, required: true },
    items: { type: Array, required: true },
    /*
     * Kategori-ULID → namn. Servern skickar den; raden ritar den inte längre
     * (issue 212), men proppen står kvar av samma skäl som `statuses` gör det.
     */
    categories: { type: Object, required: true },
    /* Item-ULID → status: `ok` eller `overdue`. */
    statuses: { type: Object, required: true },
    /*
     * Item-ULID → `{ulid, hasThumb}` eller null, ur
     * App\Actions\Item\ResolveItemCover: radens eget omslag. `hasThumb` säger
     * att `thumb`-derivatet finns, och först då ritas en miniatyr.
     */
    covers: { type: Object, required: true },
    /* Containerns taggar inom omfånget, ur ListTags — filterradens kryssrutor. */
    tags: { type: Array, required: true },
    /* Containerns kategoriträd inom omfånget, ur ListCategories — filterradens väljare. */
    categoryTree: { type: Array, required: true },
    /* Filtret som servern tillämpade: { q, tags, category, dropped }. */
    filter: { type: Object, required: true },
    /*
     * `{ create, update }` — behörighetsflaggorna. `create` ritar skapaknappen
     * nedan, och `update` ritar *Redigera container* i hjälten (issue 170 ·
     * [[ADR-0050 Desktopdesignen]] § 2–3). Båda ligger BREDVID resursen och är
     * presentation: rutterna prövar samma policyer på nytt.
     */
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
     * `{ulid, name, match, children}` per nod, rötterna enligt [[ADR-0041
     * Itemets vy]] och ett item med två föräldrar på båda ställena. `match`
     * är sant på en träff — och på varje nod utan filter — och falskt på en
     * förfader som bara är vägen ned till en träff (issue 293 ·
     * [[ADR-0058 Trädet som tabell]] § 5).
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
     * Trädets kolumner, item-ULID → `{category, tags, documents, tasks,
     * updated_at}`, ur App\Actions\Item\ResolveItemTreeRows (issue 292 ·
     * [[ADR-0058 Trädet som tabell]] § 1, 3 och 4). Proppen finns BARA i
     * trädläget, samma form och samma skäl som `structure` ovan: `null`
     * betyder INTE HÄMTAD, och en tom tabell är ett svar servern HAR gett.
     *
     * Vyn ritar ingenting ur den än: tabellen kommer i issue 294, och den
     * läser kolumnerna härifrån i stället för att räkna dem i klienten.
     */
    treeRows: { type: Object, default: null },
    /*
     * Kartan i kartläget, ur App\Actions\Item\ResolveItemMap: `{trail, levels}`.
     * `trail` är den LÖSTA öppna vägen — en begärd väg som inte längre finns är
     * kapad där den brister, så markeringen pekar alltid på en nod som finns —
     * och `levels` är nivåerna i ritad ordning, rötterna först.
     *
     * `null` betyder INTE HÄMTAD, på samma villkor och av samma skäl som
     * `structure` ovan.
     */
    map: { type: Object, default: null },
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
 * `view` — samma val som ItemTabs gör med `tab` (issue 100 och 102).
 */
const views = computed(() => {
    const query = filterQuery.value;

    /*
     * Filtreret hänger efter läget, som `tab` gör i `ItemTabs`: `view` först
     * och `q`, `tags[]` och `category` efter. Adressen skrivs ut per läge i
     * listan nedan i stället för att byggas av en hjälpare — listan ÄR
     * förteckningen över lägena, och ett läge vars adress byggs någon
     * annanstans är ett läge man inte ser.
     */
    const filterTail = query === '' ? '' : `&${query}`;

    return [
        { key: 'list', label: t('item.view.list'), href: query === '' ? base.value : `${base.value}?${query}` },
        { key: 'tree', label: t('item.view.tree'), href: `${base.value}?view=tree${filterTail}` },
        { key: 'map', label: t('item.view.map'), href: `${base.value}?view=map${filterTail}` },
    ];
});
</script>

<template>
    <Head :title="t('item.index.title')" />

    <!--
        Huvudet är gemensamt för de tre lägena (issue 291 · [[ADR-0058 Trädet
        som tabell]] § 7): rubriken, *New item* och växeln står på en rad, i den
        ordningen, och raden ritas utanför lägesgrenarna. `mr-auto` trycker
        knappen och växeln till höger även när `can.create` är falsk.

        Växeln (issue 154 · [[ADR-0046 Containerns karta]]). Den står över
        båda ytorna och byter mellan dem med en adress: *Lista* är förvalet
        och skrivs utan `view`, *Träd* bär `?view=tree`. Läget kommer ur
        `view`-proppen — serverns läsning av adressen — så vyn håller
        ingenting i minnet och en omladdning landar i samma läge.
    -->
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-2xl font-semibold">{{ t('item.index.heading') }}</h1>

        <Link
            v-if="can.create"
            :href="`/containers/${container.ulid}/items/create`"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
        >
            {{ t('item.index.create') }}
        </Link>

        <ItemViewSwitch
            :views="views"
            :current="view"
            :label="t('item.view.label')"
        />
    </div>

    <!--
        Filterraden står under huvudet i lägena *Lista* och *Träd* (issue 293
        · [[ADR-0058 Trädet som tabell]] § 7). Kartan filtreras inte och ritar
        därför ingen rad. Raden ritas en gång och utanför lägesgrenarna, så att
        listan och trädet ser samma filter och samma aktiva chips; `view`
        följer med så att ett filter som skickas i trädläget stannar där.
    -->
    <ItemFilterBar
        v-if="view !== 'map'"
        :container-ulid="container.ulid"
        :tags="tags"
        :categories="categoryTree"
        :filter="filter"
        :view="view"
    />

    <!-- Listan, oförändrad: de tre tomma lägena och raderna. -->
    <template v-if="view === 'list'">
        <p v-if="items.length === 0 && !hasFilter" class="mt-8 text-slate-700">
            {{ t('item.index.empty') }}
        </p>

        <p v-else-if="items.length === 0" class="mt-8 text-slate-700">
            {{ t('item.index.filter_empty', { filters: summary }) }}
        </p>

        <ul v-else class="mt-8 flex flex-col divide-y divide-slate-200">
            <!--
                Raden är `ItemRow` (issue 285 · [[M32 Formspråket]]), samma
                komponent som översiktens itempanel ritar. Listan äger bara
                formen runt raderna: `<ul>` med skiljelinjer, i serverns
                ordning.
            -->
            <ItemRow
                v-for="item in items"
                :key="item.ulid"
                :item="item"
                :cover="covers[item.ulid]"
                :container-ulid="container.ulid"
            />
        </ul>
    </template>

    <!--
        Trädläget (issue 154). Containerns struktur, ritad av
        ItemStructureTree med fällknappar: rötterna syns och grenarna
        väntar, så en container med sjuhundra items inte står mellan
        användaren och sidan. Sedan issue 293 tar trädet samma filter som
        listan ([[ADR-0058 Trädet som tabell]] § 5): servern skickar
        träffarna och vägen ned till dem, och varje nod bär `match`. Varje
        led är en länk till sitt item (`?path=` följer med, se
        ItemStructureTree.vue).

        Ett filter UTAN träffar säger samma sak som listan, med de filter
        användaren själv satt — `item.index.filter_empty` — och ingenting om
        vad som dolts. Ett OFILTRERAT tomt träd ritar ingenting, som förut:
        växeln säger vilket läge man står i, och att säga "containern är tom"
        här hade varit listans rad (issue 73 § Beslut 6).
    -->
    <section v-else-if="view === 'tree'" class="mt-6">
        <p v-if="structure !== null && structure.length === 0 && hasFilter" class="mt-8 text-slate-700">
            {{ t('item.index.filter_empty', { filters: summary }) }}
        </p>

        <ItemStructureTree
            v-else
            collapsible
            :nodes="structure ?? []"
            :container-ulid="container.ulid"
        />
    </section>

    <!--
        Kartläget (issue 157 · [[ADR-0046 Containerns karta]]). Samma
        struktur som trädet, ritad som noder med en öppen gren per nivå:
        kolumner över `md:` och ett rutnät med sökväg under. Den öppna
        vägen står i adressen, och `trail` är serverns LÖSTA väg — en
        begärd väg som inte längre finns är kapad där den brister, så
        markeringen pekar alltid på en nod som finns.

        Ingen rubrik och ingen egen tom-text, av samma skäl som trädet: en
        tom karta ritar ingenting, och ett ord om vad som dolts är förbjudet
        (issue 73 § Beslut 6).
    -->
    <section v-else class="mt-6">
        <ContainerMap
            :map="map ?? { trail: [], levels: [] }"
            :statuses="statuses"
            :container-ulid="container.ulid"
        />
    </section>
</template>
