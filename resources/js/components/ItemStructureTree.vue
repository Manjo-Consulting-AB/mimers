<script setup>
import { Link } from '@inertiajs/vue3';

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
 */
const props = defineProps({
    /* Noderna på den här nivån, ur `structure`-proppen — `{ulid, name, children}`. */
    nodes: { type: Array, required: true },
    containerUlid: { type: String, required: true },
    /*
     * ULID:na från roten ned till den nivå noderna står på, alltså ledet
     * ovanför varje nod här. Tom för rötterna.
     */
    trail: { type: Array, required: true },
    /*
     * ULID:na längs den AKTUELLA vägen, ur `paths` (issue 95) — inte ur
     * adressen, se docblocken ovan.
     */
    activeTrail: { type: Array, required: true },
});

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
</script>

<template>
    <ul class="flex flex-col gap-1">
        <li v-for="node in nodes" :key="nodeTrail(node).join('.')" class="flex flex-col gap-1">
            <Link
                :href="nodeHref(node)"
                :aria-current="isCurrent(node) ? 'true' : null"
                class="inline-flex min-h-11 items-center rounded-control px-2 text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="isCurrent(node)
                    ? 'bg-accent-soft font-semibold text-accent'
                    : 'text-ink-muted hover:bg-surface-sunken'"
            >
                {{ node.name }}
            </Link>

            <ItemStructureTree
                v-if="node.children.length > 0"
                class="ml-6 border-l border-border pl-4"
                :nodes="node.children"
                :container-ulid="containerUlid"
                :trail="nodeTrail(node)"
                :active-trail="activeTrail"
            />
        </li>
    </ul>
</template>
