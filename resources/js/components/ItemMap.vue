<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import CreateMenu from './CreateMenu.vue';
import ItemMapEdges from './ItemMapEdges.vue';
import ItemMapNode from './ItemMapNode.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerns karta, se issue 297 · [[M34 Kartan med en mitt]] § 297 och
 * [[ADR-0059 Kartan med en mitt]] § 3–8, omritad i issue 306 ·
 * [[ADR-0061 Kartans kort och kanter]] § 5–8 och luftad i issue 308 ·
 * [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 1–3.
 *
 * **En mitt och en ring.** Mitten står i mitten, föräldrarna ovanför, barnen
 * under och de relaterade i två kolumner på var sin sida — index 0, 2 och 4
 * till vänster och index 1, 3 och 5 till höger, i serverns ordning.
 * Komponenten vandrar inte i grafen, sorterar inte om en grupp och räknar
 * ingenting: servern har redan skickat de noder som ritas och gruppens `total`
 * (App\Actions\Item\ResolveItemMap).
 *
 * **Kortets form bor i `ItemMapNode`** (issue 305 · [[ADR-0061 Kartans kort och
 * kanter]] § 1–4): ikonen till vänster, namnet och slagets ord till höger, och
 * öppna-ikonen i kortets hörn. Vyn skickar slaget som propp så att noden kan
 * färga ordet rätt.
 *
 * **Rutnätet fyller panelen** (ADR-0061 § 7 och ADR-0062 § 2): föräldrarna
 * står på en rad ovanför mitten, barnen under med högst fyra per rad — bredden
 * `md:max-w-[52.5rem]` rymmer fyra kort (4 × `w-48`) och tre mellanrum på
 * 24 px, så en femte rad bryts och serverns tak på tolv ger högst tre rader —
 * och de relaterade står i en båge på var sida. Från `md:` är det `gap-6`
 * mellan korten, 24 px mellan barnens rader, `mt-14` mellan raderna föräldrar,
 * mitten och barnen, och `gap-12` mellan mitten och de relaterades kolumner.
 * Den mittersta på en sida — den andra noden på den sidan — står längst ut:
 * kolumnen är riktad mot mitten och den noden skjuts ut med en marginal.
 * Korten har fast bredd, så raderna står i linje. Under `md:` gäller dagens
 * avstånd, och allt står i en kolumn med de relaterade under barnen.
 *
 * **Linjerna bor i `ItemMapEdges`** (ADR-0061 § 5 och 6, ADR-0062 § 1): ett
 * SVG-lager under korten, i kartans ram, som mäter korten och ritar en kubisk
 * kurva per ritat kort. Linjerna har inga pilspetsar. Lagret finns bara från
 * `md:`, där rutnätet finns.
 *
 * **Alla noder utom mitten är länkar till kartan med sig själva som mitt** —
 * `?view=map&focus=<ulid>` — och öppna-ikonen bredvid leder till itemets sida.
 * Mitten ÄR sidan man står på och är därför ingen centreringslänk
 * (ADR:ns beslut 2 och 3).
 *
 * **Plusmenyn sitter bara på mittnoden** (ADR:ns beslut 4): raderna kommer ur
 * `map.rows`, som är tomma kring containern, och `ItemMapNode` ritar ingen
 * knapp när de är tomma. Menyn är monterad från början av samma skäl som i
 * `FocusMap`: `UiSheet` visar sitt `<dialog>` när `open` ÄNDRAS, och ett ark
 * som föds med `open` redan sant hade aldrig visats.
 *
 * **Formen är fast.** Taken per grupp är serverns — fyra föräldrar, tolv barn
 * och sex relaterade — och vyn fördelar bara de relaterade på två sidor:
 * varannan nod till vänster och varannan till höger, utan att sortera om.
 * Ingenting mäts här och ingenting skrollar i sidled: linjerna mäts i
 * `ItemMapEdges`. Över `md:` står de relaterade på sidorna; under `md:` står
 * de under barnen i serverns ordning (ADR-0061 § 8 och ADR-0062 § 3).
 *
 * **Teckenförklaringen har fyra rader** (ADR-0059 § 8): *Parent*, *Child*
 * och *Related* med gruppens `total`, och *Same item*, som förklarar ⇄. Orden
 * kommer ur `lang/` som all annan text ([[ADR-0013 Språk och i18n]]), och
 * prickarna bär linjernas färger (ADR-0061 § 5).
 *
 * ***+N till* visas när `total` är större än antalet ritade noder** och leder
 * till relationsfliken kring ett item och till listläget kring containern —
 * målen byggs av `Index.vue`, som äger containerns adresser.
 *
 * **Vägen till mitten står ovanför kartan** (issue 298 · ADR:ns beslut 9):
 * containerns namn först, som leder till kartan utan `focus`, sedan varje led i
 * `map.path`. Alla led utom det sista görs till mitten; det sista är mitten och
 * är ingen länk. ⇄ med antalet platser står efter vägen när mitten har fler än
 * en. Vägen räknas av servern — vyn vandrar inte i grafen.
 */
const props = defineProps({
    /* App\Actions\Item\ResolveItemMap:s svar: `{center, container, path, parent, child, related, rows}`. */
    map: { type: Object, required: true },
    /* Item-ULID → `ok` eller `overdue`, ur `ItemStatus`. */
    statuses: { type: Object, required: true },
    /* Containerns ULID — kartans adresser byggs ur den. */
    containerUlid: { type: String, required: true },
    /* Målet för *+N till*: relationsfliken eller listläget. */
    overflowHref: { type: String, required: true },
    /*
     * Filtret som querysträng, utan inledande `?` (issue 299 · [[ADR-0059
     * Kartan med en mitt]] § 10). Nodernas och sökvägens länkar bär det, så att
     * ett mittbyte stannar i samma träfflista. Tom sträng när inget filter är
     * satt, och då skrivs ingen parameter alls.
     */
    filterQuery: { type: String, default: '' },
});

const { t } = useTranslations();

const base = computed(() => `/containers/${props.containerUlid}/items`);

/*
 * Filtret följer med nodernas och sökvägens länkar (issue 299 · [[ADR-0059
 * Kartan med en mitt]] § 10), med samma form som växelns `filterTail`: `&` och
 * sedan strängen, eller ingenting alls. `filterQuery` är redan serverns
 * läsning av filtret, så en tagg som fallit bort följer inte med.
 */
const filterTail = computed(() => (props.filterQuery === '' ? '' : `&${props.filterQuery}`));

const focusHref = (node) => `${base.value}?view=map&focus=${node.ulid}${filterTail.value}`;

const openHref = (node) => `${base.value}/${node.ulid}`;

/*
 * Sökvägens första led: containern, som leder till kartan utan `focus`.
 * Sista ledet i `map.path` är mitten och ritas utan länk — de övriga centrerar.
 */
const containerHref = computed(() => `${base.value}?view=map${filterTail.value}`);
const pathLast = computed(() => props.map.path.length - 1);

const statusFor = (node) => props.statuses[node.ulid] ?? '';

const parents = computed(() => props.map.parent.nodes);
const children = computed(() => props.map.child.nodes);

/*
 * De relaterade fördelas på varannan sida (ADR-0062 § 3): index 0, 2 och 4
 * till vänster och index 1, 3 och 5 till höger. Ordningen är serverns —
 * motpartens namn stigande — och den delas av, aldrig sorteras om: "den
 * första" ska vara samma nod i kartan som i relationsfliken. Serverns tak är
 * sex relaterade, alltså tre per sida.
 */
const relatedLeft = computed(
    () => props.map.related.nodes.filter((_, index) => index % 2 === 0),
);
const relatedRight = computed(
    () => props.map.related.nodes.filter((_, index) => index % 2 === 1),
);

/*
 * Hur många noder som inte fick plats, per grupp. Noll betyder att gruppen
 * ritar allt den har och ingen *+N till*-rad finns.
 */
const moreParents = computed(
    () => Math.max(0, props.map.parent.total - props.map.parent.nodes.length),
);
const moreChildren = computed(
    () => Math.max(0, props.map.child.total - props.map.child.nodes.length),
);
const moreRelated = computed(
    () => Math.max(0, props.map.related.total - props.map.related.nodes.length),
);

/* Slagen i teckenförklaringens ordning, och samma tre noderna ritas i. */
const kinds = ['parent', 'child', 'related'];

/* Slagens prickar, i linjernas färger (ADR-0061 § 5). Mitten har ingen. */
const DOTS = {
    parent: 'bg-accent',
    child: 'bg-success',
    related: 'bg-ink-subtle',
};

const legend = computed(() => kinds.map((kind) => ({
    key: kind,
    label: t(`item.map.kind.${kind}`),
    total: props.map[kind].total,
})));

/*
 * Mitten: containern eller itemet. Kring containern bär kortet ikonen
 * `container` och ordet *Container* i stället för *Current item* — den är inget
 * item och har därför ingen status, ingen öppna-ikon och inga rader
 * ([[ADR-0061 Kartans kort och kanter]] § 2).
 */
const center = computed(() => props.map.center);
const centerLabel = computed(() => (
    center.value.kind === 'item' ? t('item.links.current') : t('item.map.kind.container')
));
const centerOpenHref = computed(
    () => (center.value.kind === 'item' ? openHref(center.value) : null),
);

/*
 * Nodmenyn: raderna, elementet som öppnade den och nodens namn. Arket fäster
 * under elementet över `md:` och lämnar tillbaka fokus när det stängs.
 */
const menuOpen = ref(false);
const menuTrigger = ref(null);
const menuRows = ref([]);
const menuName = ref('');

function openMenu({ rows, trigger, name }) {
    menuRows.value = rows;
    menuTrigger.value = trigger;
    menuName.value = name;
    menuOpen.value = true;
}

function closeMenu() {
    menuOpen.value = false;
}
</script>

<template>
    <div>
        <!--
            Vägen till mitten (issue 298 · ADR:ns beslut 9): containern först,
            sedan leden från roten ned till mitten. Varje led utom det sista
            centrerar; mitten själv är ingen länk. Kring containern är vägen
            tom och raden ritas inte.
        -->
        <nav
            v-if="map.path.length > 0"
            :aria-label="t('item.map.path')"
            class="mb-4 flex flex-wrap items-center justify-center gap-1 text-meta text-ink-muted"
        >
            <Link
                :href="containerHref"
                class="rounded-control px-1 py-0.5 text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ map.container.name }}
            </Link>

            <template v-for="(led, index) in map.path" :key="led.ulid">
                <span aria-hidden="true">›</span>

                <Link
                    v-if="index < pathLast"
                    :href="focusHref(led)"
                    class="rounded-control px-1 py-0.5 text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {{ led.name }}
                </Link>

                <span v-else class="px-1 py-0.5 font-medium text-ink">{{ led.name }}</span>
            </template>

            <!-- Fler platser än en: ⇄ och antalet står efter vägen. -->
            <span
                v-if="map.center.placements > 1"
                class="ml-1 flex items-center gap-1"
            >
                <span aria-hidden="true">&harr; {{ map.center.placements }}</span>
                <span class="sr-only">
                    {{ t('item.map.placements', { count: map.center.placements }) }}
                </span>
            </span>
        </nav>

        <!--
            Kartans ram (issue 306 · [[ADR-0061 Kartans kort och kanter]]
            § 6–7). SVG-lagret ligger i ramen och mäter korten relativt den;
            korten ligger ovanpå det. Från `md:` står de i rutnätet — under
            `md:` är ramen en kolumn, och de relaterade ritas under barnen.
        -->
        <div data-map-frame class="relative">
            <ItemMapEdges :map="map" />

            <div class="relative z-10 flex w-full flex-col items-center">
                <!--
                    Föräldrarna ovanför, högst fyra. Från `md:` är det `gap-6`
                    mellan korten och bredden rymmer fyra kort och tre
                    mellanrum på 24 px, så raden bryts aldrig före serverns tak.
                -->
                <ul
                    v-if="parents.length > 0"
                    class="flex w-full flex-wrap justify-center gap-3 md:gap-6 md:max-w-[52.5rem]"
                >
                    <ItemMapNode
                        v-for="node in parents"
                        :key="node.ulid"
                        :node="node"
                        kind="parent"
                        :label="t('item.map.kind.parent')"
                        :status="statusFor(node)"
                        :href="focusHref(node)"
                        :open-href="openHref(node)"
                        @open-menu="openMenu"
                    />
                </ul>

                <Link
                    v-if="moreParents > 0"
                    :href="overflowHref"
                    class="mt-2 flex min-h-11 w-fit items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
                >
                    {{ t('item.map.more', { count: moreParents }) }}
                </Link>

                <!--
                    Mitten med de relaterade i en båge på var sin sida. BÅDA
                    kolumnerna ritas även när en sida är tom över `md:` — de är
                    lika breda och håller mitten i mitten. Noderna fördelas på
                    varannan sida: index 0, 2 och 4 till vänster och 1, 3 och 5
                    till höger. Kolumnerna är riktade mot mitten, och den andra
                    noden på varje sida — `index === 1` i den sidans ordning —
                    skjuts ut: `mr-8` åt vänster och `ml-8` åt höger. `gap-12`
                    håller luft mellan mitten och kolumnerna. Under `md:` är
                    sidorna gömda och de relaterade står under barnen i
                    serverns ordning i stället.
                -->
                <div class="mt-6 flex w-full items-center justify-center md:mt-14 md:gap-12">
                    <ul class="hidden min-w-0 flex-1 flex-col items-end gap-3 md:flex">
                        <ItemMapNode
                            v-for="(node, index) in relatedLeft"
                            :key="node.ulid"
                            :class="index === 1 ? 'md:mr-8' : ''"
                            :node="node"
                            kind="related"
                            :label="t('item.map.kind.related')"
                            :status="statusFor(node)"
                            :href="focusHref(node)"
                            :open-href="openHref(node)"
                            @open-menu="openMenu"
                        />
                    </ul>

                    <ul class="shrink-0">
                        <ItemMapNode
                            :node="center"
                            :label="centerLabel"
                            :status="statusFor(center)"
                            :open-href="centerOpenHref"
                            :rows="map.rows"
                            current
                            @open-menu="openMenu"
                        />
                    </ul>

                    <!--
                        De relaterade är fler än sex: *+N till* står sist i den
                        högra kolumnen, i samma flöde som noderna den räknar.
                    -->
                    <ul class="hidden min-w-0 flex-1 flex-col items-start gap-3 md:flex">
                        <ItemMapNode
                            v-for="(node, index) in relatedRight"
                            :key="node.ulid"
                            :class="index === 1 ? 'md:ml-8' : ''"
                            :node="node"
                            kind="related"
                            :label="t('item.map.kind.related')"
                            :status="statusFor(node)"
                            :href="focusHref(node)"
                            :open-href="openHref(node)"
                            @open-menu="openMenu"
                        />

                        <li v-if="moreRelated > 0">
                            <Link
                                :href="overflowHref"
                                class="flex min-h-11 items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
                            >
                                {{ t('item.map.more', { count: moreRelated }) }}
                            </Link>
                        </li>
                    </ul>
                </div>

                <!--
                    Barnen under, högst fyra per rad. Serverns tak är tolv, så
                    det blir högst tre rader; från `md:` rymmer bredden fyra
                    kort och tre mellanrum på 24 px, med `gap-6` både mellan
                    korten och mellan raderna.
                -->
                <ul
                    v-if="children.length > 0"
                    class="mt-6 flex w-full flex-wrap justify-center gap-3 md:mt-14 md:gap-6 md:max-w-[52.5rem]"
                >
                    <ItemMapNode
                        v-for="node in children"
                        :key="node.ulid"
                        :node="node"
                        kind="child"
                        :label="t('item.map.kind.child')"
                        :status="statusFor(node)"
                        :href="focusHref(node)"
                        :open-href="openHref(node)"
                        @open-menu="openMenu"
                    />
                </ul>

                <Link
                    v-if="moreChildren > 0"
                    :href="overflowHref"
                    class="mt-2 flex min-h-11 w-fit items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
                >
                    {{ t('item.map.more', { count: moreChildren }) }}
                </Link>
            </div>
        </div>

        <!--
            Under `md:` står de relaterade under barnen, i serverns ordning.
            Samma noder som i sidokolumnerna, men utan varannan-fördelningen —
            en karta som skrollar i sidled är en karta man inte ser.
        -->
        <ul
            v-if="relatedLeft.length > 0 || relatedRight.length > 0"
            class="mt-4 flex flex-wrap justify-center gap-2 md:hidden"
        >
            <ItemMapNode
                v-for="node in map.related.nodes"
                :key="node.ulid"
                :node="node"
                :label="t('item.map.kind.related')"
                :status="statusFor(node)"
                :href="focusHref(node)"
                :open-href="openHref(node)"
                @open-menu="openMenu"
            />

            <li v-if="moreRelated > 0">
                <Link
                    :href="overflowHref"
                    class="flex min-h-11 items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
                >
                    {{ t('item.map.more', { count: moreRelated }) }}
                </Link>
            </li>
        </ul>

        <!--
            Teckenförklaringen: de tre slagen med gruppens `total`, och ⇄
            förklarad med ord. En karta vars märken inte förklaras är en karta
            man gissar på.
        -->
        <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
            <li
                v-for="row in legend"
                :key="row.key"
                class="flex items-center gap-2 text-meta text-ink-muted"
            >
                <span
                    aria-hidden="true"
                    class="h-2.5 w-2.5 shrink-0 rounded-pill"
                    :class="DOTS[row.key]"
                ></span>
                {{ t('item.map.legend', { kind: row.label, count: row.total }) }}
            </li>

            <li class="flex items-center gap-2 text-meta text-ink-muted">
                <span aria-hidden="true">&harr;</span>
                {{ t('item.map.same_item') }}
            </li>
        </ul>

        <!--
            Nodens meny, monterad från början — se `FocusMap` och
            `ItemMapNode`. Inget `v-if` här: arket öppnas av en ÄNDRING av
            `open`, och ett ark som monteras med `open` redan sant hinner aldrig
            se den.
        -->
        <CreateMenu
            :open="menuOpen"
            :trigger="menuTrigger"
            :rows="menuRows"
            :heading="menuName"
            @close="closeMenu"
        />
    </div>
</template>
