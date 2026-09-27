<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Trädet ur issue 94, ritat av en komponent som renderar sig SJÄLV — se
 * resources/js/components/CategoryTree.vue och issue 56a § Beslut 2.
 *
 * Ingen handskriven utplattning per nivå: `<ul>`/`<li>` och en rekursiv
 * komponent räcker, och servern har redan sorterat varje nivå på namnet — vyn
 * sorterar aldrig om (issue 57a § Beslut 8). Komponenten refererar sig själv
 * vid namn i mallen, och filnamnet ÄR komponentens namn, så filen får inte
 * döpas om utan att mallen ändras med.
 *
 * **Noderna är förekomster och inte items** (App\Support\Item\ItemTreeNode):
 * ett item med två föräldrar står på båda ställena, och den som klickar på
 * den ena landar på den förekomsten. `:key` är därför hela ledet och inte
 * ULID:n — samma item två gånger i samma `<ul>` är två noder och inte en
 * dubblett.
 *
 * **Länken bär vägen i querysträngen**, samma konstruktion som brödsmulan och
 * förekomstlistan i issue 95: ett klick på ett led landar på samma förekomst
 * och inte på en godtycklig, och `?path=` betyder samma sak på varje items
 * sida. Adressen byggs här och aldrig på serversidan — rutten är itemets egen,
 * och panelen pekar inte utanför containern.
 *
 * **Markeringen kommer ur `activeTrail` och aldrig ur adressen.** Komponenten
 * läser inte `page.url` och jämför ingen querysträng själv: vilken väg som är
 * den aktuella har servern redan avgjort — en väg som inte längre finns är
 * utbytt mot den första i ordningen innan vyn ser den (issue 95) — och en
 * andra jämförelse här hade varit en andra regel som glider ifrån den första
 * ([[ADR-0041 Itemets vy]] § Beslut). Markeringen är `aria-current` OCH en
 * vikt och en yta, aldrig en färg allena.
 *
 * **Ingen räknare och inget tomt tillstånd.** Ett träd som är tomt ritar
 * ingenting: svaret får inte avslöja hur många items som filtrerats bort
 * (issue 73 § Beslut 6), och en rad om att grenen är slut hade varit precis
 * den upplysningen.
 *
 * **Två lägen: `link` och `pick`** (issue 153 · [[ADR-0048 Mobilen och
 * plusknappen]] § 3). I detaljvyn är ett led en LÄNK till förekomsten; i
 * föräldraväljaren är samma led ett VAL. Bara elementet skiljer: `<Link>`
 * mot `<button>`, och i pick-läget bär noden `can_create` ur `structure` —
 * ett item hon får se men inte skapa under ritas och går inte att välja
 * (`:disabled`). Komponenten frågar aldrig servern om lov: flaggan kommer i
 * proppen, och rutten prövar samma policy på nytt när formuläret skickas.
 *
 * `containerUlid`, `trail` och `activeTrail` används bara i link-läget —
 * väljaren har ingen förekomst att markera och ingen adress att bygga — och
 * de är därför valfria. `trail` fylls ändå av rekursionen i båda lägena:
 * den är nodens nyckel i listan.
 *
 * **`collapsible` är ett TREDJE läge och inte en andra komponent** (issue 154
 * · [[ADR-0046 Containerns karta]]). Trädläget i itemfliken ritar hela
 * containern, och en container med sjuhundra items får inte stå mellan
 * användaren och sidan: varje nod med barn får därför en fällknapp, och
 * grenen under den ritas först när den är öppen. Panelen (issue 103) och
 * föräldraväljaren (issue 153) är oförändrade — de ritar trädet öppet, och
 * standarden är `false` med flit: en andra trädkomponent hade varit en andra
 * regel om ordning, indrag och tomhet, och den hade glidit ifrån den här vid
 * första ändringen.
 *
 * **Tillståndet bor hos den instans som ritar nivån.** Varje nivå är en egen
 * instans av komponenten, och `open` håller den här nivåns noder — nycklade på
 * hela ledet, samma sträng som `:key`, så samma item på två ställen fälls upp
 * var för sig. Ingen propp och inget event uppåt: en karta i roten hade tvingat
 * varje nivå att skicka sitt klick genom alla leden mellan.
 *
 * **Stängt är utgångsläget.** Rötterna syns, grenarna väntar — det är
 * containerns översikt och inte dess innehåll, och den som vill se allt på en
 * gång har listan ([[ADR-0046 Containerns karta]] § Motivering).
 *
 * Fällknappen är en `<button>` och ingenting annat: en gren är inte en adress,
 * och `aria-expanded` bär tillståndet medan `aria-label` säger vad trycket GÖR
 * — nodens namn står redan på raden bredvid. Träffytan är `min-h-11`.
 */
const props = defineProps({
    /*
     * Noderna på den här nivån, ur `structure`-proppen — `{ulid, name,
     * children}`, och i pick-läget också `can_create`.
     */
    nodes: { type: Array, required: true },
    containerUlid: { type: String, default: '' },
    /*
     * ULID:na från roten ned till den nivå noderna står på, alltså ledet
     * ovanför varje nod här. Tom för rötterna.
     */
    trail: { type: Array, default: () => [] },
    /*
     * ULID:na längs den AKTUELLA vägen, ur `paths` (issue 95) — inte ur
     * adressen, se docblocken ovan. Bara link-läget.
     */
    activeTrail: { type: Array, default: () => [] },
    /* Sant i föräldraväljaren: leden är valbara knappar i stället för länkar. */
    pick: { type: Boolean, default: false },
    /* ULID:n för det valda itemet i pick-läget, eller null för inget val. */
    selected: { type: String, default: null },
    /*
     * Sant i trädläget: varje nod med barn får en fällknapp och grenen under
     * den ritas bara när den är öppen. Panelen och väljaren ritar trädet
     * öppet och lämnar flaggan falsk.
     */
    collapsible: { type: Boolean, default: false },
});

const emit = defineEmits(['choose']);

const { t } = useTranslations();

/*
 * Den här nivåns öppna noder, nycklade på ledet. Varje nivå är en egen instans
 * — se docblocken — så tabellen behöver bara rymma de noder som ritas här.
 */
const open = ref({});

function isOpen(node) {
    return open.value[nodeTrail(node).join('.')] === true;
}

function toggle(node) {
    const key = nodeTrail(node).join('.');

    open.value[key] = ! isOpen(node);
}

/*
 * Ledet från roten NED till noden: förfäderna plus noden själv. Det är både
 * länkens `?path=` och den här nodens plats i trädet.
 */
function nodeTrail(node) {
    return [...props.trail, node.ulid];
}

function nodeHref(node) {
    return `/containers/${props.containerUlid}/items/${node.ulid}?path=${nodeTrail(node).join('.')}`;
}

/*
 * Är det här den aktuella förekomsten? Ledet ska vara lika långt och lika
 * långt ned — en prefixmatchning hade tänt varje förfader till itemet, och
 * de är inte förekomster av det.
 */
function isCurrent(node) {
    const trail = nodeTrail(node);

    return trail.length === props.activeTrail.length
        && trail.every((ulid, index) => ulid === props.activeTrail[index]);
}

/*
 * Är det här det valda ledet? Samma ULID två gånger i trädet är samma item på
 * två ställen — ett item med två föräldrar — och båda leden markeras. Att
 * markera bara ett av dem hade påstått att valet gällde en förekomst, och en
 * förälder är ett item och inte en väg.
 */
function isChosen(node) {
    return props.selected !== null && props.selected === node.ulid;
}
</script>

<template>
    <ul class="flex flex-col gap-1">
        <li v-for="node in nodes" :key="nodeTrail(node).join('.')" class="flex flex-col gap-1">
            <div class="flex items-center gap-1">
                <!--
                    Fällknappen (issue 154). Bara i trädläget, och bara på en
                    nod som har något att fälla upp: en knapp på ett löv hade
                    varit en knapp som inte gör något. Den står FÖRE ledet, så
                    att raden börjar med grenen och inte med namnet — samma
                    ordning som mockupen ritar den.
                -->
                <button
                    v-if="collapsible && node.children.length > 0"
                    type="button"
                    :aria-expanded="isOpen(node) ? 'true' : 'false'"
                    :aria-label="isOpen(node)
                        ? t('item.structure.collapse', { name: node.name })
                        : t('item.structure.expand', { name: node.name })"
                    class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="toggle(node)"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-4 w-4"
                        :class="isOpen(node) ? 'rotate-90' : ''"
                        aria-hidden="true"
                    >
                        <path d="m9 6 6 6-6 6"></path>
                    </svg>
                </button>

                <button
                    v-if="pick"
                    type="button"
                    :disabled="!node.can_create"
                    :aria-current="isChosen(node) ? 'true' : null"
                    class="inline-flex min-h-11 items-center rounded-control px-2 text-left text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                    :class="isChosen(node)
                        ? 'bg-accent-soft font-semibold text-accent'
                        : 'text-ink-muted hover:bg-surface-sunken'"
                    @click="emit('choose', node)"
                >
                    {{ node.name }}
                </button>

                <Link
                    v-else
                    :href="nodeHref(node)"
                    :aria-current="isCurrent(node) ? 'true' : null"
                    class="inline-flex min-h-11 items-center rounded-control px-2 text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    :class="isCurrent(node)
                        ? 'bg-accent-soft font-semibold text-accent'
                        : 'text-ink-muted hover:bg-surface-sunken'"
                >
                    {{ node.name }}
                </Link>
            </div>

            <!--
                Grenen under noden, och bara när den är öppen i trädläget.
                Löv ritar ingenting: ett barnlöst led har inget att fälla upp.
            -->
            <ItemStructureTree
                v-if="node.children.length > 0 && (! collapsible || isOpen(node))"
                class="ml-6 border-l border-border pl-4"
                :nodes="node.children"
                :container-ulid="containerUlid"
                :trail="nodeTrail(node)"
                :active-trail="activeTrail"
                :pick="pick"
                :selected="selected"
                :collapsible="collapsible"
                @choose="emit('choose', $event)"
            />
        </li>
    </ul>
</template>
