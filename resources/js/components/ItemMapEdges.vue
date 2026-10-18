<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/*
 * Kartans linjer, se resources/js/components/ItemMap.vue, issue 306 ·
 * [[M35 Itemets ikon och kartans kort]] § 306 och
 * [[ADR-0061 Kartans kort och kanter]] § 5–8, utan pilspetsar sedan issue
 * 308 · [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 1.
 *
 * **Lagret ritar en kubisk kurva per ritat kort.** Korten står i ett rutnät
 * med fasta platser (ADR-0061 § 7), så lagret behöver ingen layoutalgoritm:
 * det läser kortens lägen med `getBoundingClientRect()` relativt kartans ram
 * och en linje går från mittens kant till kortets närmaste kant (ADR-0061 § 6).
 * Ingen mätning sker i förväg och ingenting räknas fram ur rutnätet — kortens
 * höjd beror på `via` och på teckensnittet.
 *
 * **Färgen säger slaget** (ADR-0061 § 5): föräldrar i `accent`, barn i
 * `success` och relaterade i `ink-subtle`. Färgerna skrivs som token och
 * aldrig som hex, så att mörkt läge byter dem på samma ställe som resten av
 * ytan. De relaterade är streckade, eftersom en relation inte är en struktur.
 *
 * **Linjerna har inga pilspetsar** (ADR-0062 § 1): lagret ritar ingen
 * `<marker>` och ingen `<defs>`, och ingen `<path>` bär `marker-start` eller
 * `marker-end`. Placeringen säger riktningen — mitten i mitten, föräldrarna
 * ovanför och barnen under — och streckningen skiljer relationen från
 * strukturen.
 *
 * **Lagret är osynligt för skärmläsaren och för pekaren** — det är en
 * förklaring till korten, inte ett mål. Det ritas bara från `md:`, där rutnätet
 * finns; under `md:` står korten i en kolumn och ingen båge behövs.
 *
 * **Det ritas om vid en `ResizeObserver` på ramen och när `map` byts.** Ett
 * nytt mitt ger nya kort, och en ny bredd flyttar dem; båda måste mätas igen,
 * annars pekar pilarna på den förra kartan. Ramen hittas ur lagrets egen
 * förälder, så att komponenten inte behöver en propp som sätts efter montering.
 *
 * Korten märks av `ItemMapNode` med `data-map-node` och `data-map-kind`.
 * Dubbletten under `md:` (de relaterade ritas där i en egen lista) har noll
 * storlek och hoppas över — den syns inte, och en linje till den hade pekat
 * på ingenting.
 */
const props = defineProps({
    /* App\Actions\Item\ResolveItemMap:s svar — ett nytt mitt ger nya kort. */
    map: { type: Object, required: true },
});

/* Lagrets rot: svg:en själv. */
const layer = ref(null);

/* En post per ritat kort: `{key, kind, d, color, dashed}`. */
const edges = ref([]);

let observer = null;

/*
 * Slagens färger som token (ADR:ns beslut 5). `ink-subtle` är samma ton som
 * `FocusMap`s relaterade linjer bär i högerpanelen.
 */
const COLORS = {
    parent: 'var(--color-accent)',
    child: 'var(--color-success)',
    related: 'var(--color-ink-subtle)',
};

/* Kartans ram, som lagret ligger i. */
function frameOf() {
    return layer.value?.closest('[data-map-frame]') ?? null;
}

/*
 * Mätningen. Koordinaterna är ramens egna: svg:en fyller ramen och saknar
 * `viewBox`, så en användarenhet är en CSS-pixel.
 */
function measure() {
    const frame = frameOf();
    const svg = layer.value;

    if (!frame || !svg) {
        edges.value = [];
        return;
    }

    // Under `md:` är lagret `display: none` och ritar ingenting.
    const layerBox = svg.getBoundingClientRect();

    if (layerBox.width === 0 || layerBox.height === 0) {
        edges.value = [];
        return;
    }

    const frameBox = frame.getBoundingClientRect();
    const center = frame.querySelector('[data-map-kind="center"]');
    const centerBox = center?.getBoundingClientRect() ?? null;

    if (!centerBox || centerBox.width === 0) {
        edges.value = [];
        return;
    }

    const x = (box) => box.left - frameBox.left;
    const y = (box) => box.top - frameBox.top;

    const centerX = x(centerBox) + centerBox.width / 2;
    const centerTop = y(centerBox);
    const centerBottom = centerTop + centerBox.height;
    const centerMiddle = centerTop + centerBox.height / 2;
    const centerLeft = x(centerBox);
    const centerRight = centerLeft + centerBox.width;

    const lines = [];

    for (const card of frame.querySelectorAll('[data-map-node]')) {
        const kind = card.dataset.mapKind;

        if (!kind || kind === 'center') {
            continue;
        }

        const box = card.getBoundingClientRect();

        // Den andra uppställningen (mobilens relaterade) ritas inte här.
        if (box.width === 0 || box.height === 0) {
            continue;
        }

        const cardX = x(box) + box.width / 2;
        const cardTop = y(box);
        const cardBottom = cardTop + box.height;

        if (kind === 'parent') {
            // Från mittens överkant upp till förälderns underkant.
            const bend = Math.max(16, (centerTop - cardBottom) / 2);

            lines.push({
                key: card.dataset.mapNode,
                kind,
                color: COLORS.parent,
                dashed: false,
                d: `M ${centerX} ${centerTop} C ${centerX} ${centerTop - bend}, ${cardX} ${cardBottom + bend}, ${cardX} ${cardBottom}`,
            });

            continue;
        }

        if (kind === 'child') {
            // Från mittens underkant ned till barnets överkant.
            const bend = Math.max(16, (cardTop - centerBottom) / 2);

            lines.push({
                key: card.dataset.mapNode,
                kind,
                color: COLORS.child,
                dashed: false,
                d: `M ${centerX} ${centerBottom} C ${centerX} ${centerBottom + bend}, ${cardX} ${cardTop - bend}, ${cardX} ${cardTop}`,
            });

            continue;
        }

        if (kind === 'related') {
            // Från mittens sida till kortets närmaste sida: vänsterkortets
            // högerkant och högerkortets vänsterkant.
            const onLeft = cardX < centerX;
            const startX = onLeft ? centerLeft : centerRight;
            const endX = onLeft ? x(box) + box.width : x(box);
            const endY = y(box) + box.height / 2;
            const direction = onLeft ? -1 : 1;
            const bend = Math.max(24, Math.abs(endX - startX) / 2);

            lines.push({
                key: card.dataset.mapNode,
                kind,
                color: COLORS.related,
                dashed: true,
                d: `M ${startX} ${centerMiddle} C ${startX + direction * bend} ${centerMiddle}, ${endX - direction * bend} ${endY}, ${endX} ${endY}`,
            });
        }
    }

    edges.value = lines;
}

/*
 * Ett nytt `map` ger nya kort, men DOM:en hinner inte bytas förrän efter
 * nästa tick — därför mäts det först då.
 */
watch(() => props.map, async () => {
    await nextTick();
    measure();
});

onMounted(async () => {
    await nextTick();
    measure();

    const frame = frameOf();

    if (frame && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => measure());
        observer.observe(frame);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});
</script>

<template>
    <svg
        ref="layer"
        aria-hidden="true"
        class="pointer-events-none absolute inset-0 z-0 hidden h-full w-full md:block"
    >
        <path
            v-for="edge in edges"
            :key="edge.key"
            :d="edge.d"
            fill="none"
            :stroke="edge.color"
            stroke-width="2"
            stroke-linecap="round"
            :stroke-dasharray="edge.dashed ? '6 5' : undefined"
        />
    </svg>
</template>
