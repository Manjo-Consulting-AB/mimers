<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { forceCollide, forceLink, forceManyBody, forceSimulation } from 'd3-force';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kartans nivå *Neighbourhood* — se issue 393 · [[ADR-0082 Kartan är
 * relationernas yta]] § 5 och 9.
 *
 * **Layouten räknas här och ritas som SVG.** `d3-force` (bara det paketet)
 * flyttar noderna, och sidan ritar dem med designsystemets token, så att
 * mörkt läge byter färgerna på samma ställe som resten av ytan.
 *
 * **Samma grannskap ritas likadant varje gång.** Startpositionerna följer
 * nodernas ordning — serverns namnordning — på en spiral, mitten ligger fast
 * i origo, slumptalskällan är en fast följd och simuleringen körs ett fast
 * antal steg på en gång i stället för i animation. Ingen `Math.random()` och
 * ingen tidtagning ingår, så två besök ger samma bild.
 *
 * **Linjerna följer `ItemMapEdges`:** heldragna till föräldrar och barn och
 * streckade till de relaterade, utan spetsar — placeringen säger inte
 * riktningen här, och relationens slag gör det inte heller.
 *
 * **Noderna är länkar.** Ett klick gör noden till mitt på samma nivå
 * (`?view=map&focus=<nod>&depth=2`), och de når med Tab. Fokus och hovring
 * visar namn och status. Ingen redigering och ingen plusmeny.
 */
const props = defineProps({
    /* `{ulid, name, icon, icon_color, status}` i namnordning. */
    nodes: { type: Array, required: true },
    /* `{from, to, kind}` med ULID:er; `kind` är `parent` eller `related`. */
    edges: { type: Array, required: true },
    /* Mitten, som en nod-ULID. */
    center: { type: String, required: true },
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();

const STEPS = 300;
const RADIUS = 10;
const CENTER_RADIUS = 15;

/* En fast slumptalskälla (LCG), så att inget i layouten beror på tiden. */
function seeded() {
    let state = 1;

    return () => {
        state = (state * 1664525 + 1013904223) % 4294967296;

        return state / 4294967296;
    };
}

/* Layouten: ulid → `{x, y}`, räknad en gång per grannskap. */
const layout = computed(() => {
    const golden = Math.PI * (3 - Math.sqrt(5));

    const simNodes = props.nodes.map((node, index) => {
        const isCenter = node.ulid === props.center;
        const radius = 36 * Math.sqrt(index + 1);

        return {
            ulid: node.ulid,
            x: isCenter ? 0 : radius * Math.cos(index * golden),
            y: isCenter ? 0 : radius * Math.sin(index * golden),
            fx: isCenter ? 0 : null,
            fy: isCenter ? 0 : null,
        };
    });

    const links = props.edges.map((edge) => ({ source: edge.from, target: edge.to }));

    const simulation = forceSimulation(simNodes)
        .randomSource(seeded())
        .force('link', forceLink(links).id((node) => node.ulid).distance(90).strength(0.6))
        .force('charge', forceManyBody().strength(-260))
        .force('collide', forceCollide(30))
        .stop();

    simulation.tick(STEPS);

    const positions = {};

    for (const node of simNodes) {
        positions[node.ulid] = { x: node.x, y: node.y };
    }

    return positions;
});

const viewBox = computed(() => {
    const points = Object.values(layout.value);
    const pad = 80;

    if (points.length === 0) {
        return '0 0 1 1';
    }

    const xs = points.map((point) => point.x);
    const ys = points.map((point) => point.y);
    const minX = Math.min(...xs) - pad;
    const minY = Math.min(...ys) - pad;

    return `${minX} ${minY} ${Math.max(...xs) + pad - minX} ${Math.max(...ys) + pad - minY}`;
});

const lines = computed(() => props.edges
    .filter((edge) => layout.value[edge.from] && layout.value[edge.to])
    .map((edge) => ({
        key: `${edge.kind}:${edge.from}:${edge.to}`,
        x1: layout.value[edge.from].x,
        y1: layout.value[edge.from].y,
        x2: layout.value[edge.to].x,
        y2: layout.value[edge.to].y,
        related: edge.kind === 'related',
    })));

const placed = computed(() => props.nodes.map((node) => ({
    ...node,
    ...layout.value[node.ulid],
    isCenter: node.ulid === props.center,
    href: `/containers/${props.containerUlid}/items?view=map&focus=${node.ulid}&depth=2`,
    statusLabel: t(`item.graph.status.${node.status === 'overdue' ? 'overdue' : 'ok'}`),
})));

const centerName = computed(() => props.nodes.find((node) => node.ulid === props.center)?.name ?? '');

/* Noden som visar sin etikett: den man hovrar över eller har fokus på. */
const active = ref(null);

const activeNode = computed(() => placed.value.find((node) => node.ulid === active.value) ?? null);

function go(event, href) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
        return;
    }

    event.preventDefault();
    router.visit(href);
}
</script>

<template>
    <svg
        :viewBox="viewBox"
        role="group"
        :aria-label="t('item.graph.label', { name: centerName })"
        data-item-graph
        class="hidden h-[32rem] w-full rounded-card border border-border bg-surface md:block"
        preserveAspectRatio="xMidYMid meet"
    >
        <g aria-hidden="true">
            <line
                v-for="line in lines"
                :key="line.key"
                :x1="line.x1"
                :y1="line.y1"
                :x2="line.x2"
                :y2="line.y2"
                :stroke="line.related ? 'var(--color-ink-subtle)' : 'var(--color-accent)'"
                stroke-width="2"
                stroke-linecap="round"
                :stroke-dasharray="line.related ? '6 5' : undefined"
            />
        </g>

        <a
            v-for="node in placed"
            :key="node.ulid"
            :href="node.href"
            :aria-label="`${node.name}, ${node.statusLabel}${node.isCenter ? `, ${t('item.graph.center')}` : ''}`"
            :aria-current="node.isCenter ? 'true' : undefined"
            class="outline-none [&:focus-visible>circle:first-child]:stroke-focus"
            @click="go($event, node.href)"
            @mouseenter="active = node.ulid"
            @mouseleave="active = null"
            @focus="active = node.ulid"
            @blur="active = null"
        >
            <circle :cx="node.x" :cy="node.y" r="22" fill="transparent" stroke-width="3" stroke="transparent" />
            <circle
                :cx="node.x"
                :cy="node.y"
                :r="node.isCenter ? CENTER_RADIUS : RADIUS"
                :fill="node.status === 'overdue' ? 'var(--color-danger)' : 'var(--color-accent)'"
                :stroke="node.isCenter ? 'var(--color-ink)' : 'var(--color-surface)'"
                stroke-width="3"
            />
        </a>

        <g v-if="activeNode" aria-hidden="true" class="pointer-events-none">
            <text
                :x="activeNode.x"
                :y="activeNode.y - 36"
                text-anchor="middle"
                fill="var(--color-ink)"
                stroke="var(--color-surface)"
                stroke-width="4"
                paint-order="stroke"
                font-size="14"
                font-weight="600"
            >{{ activeNode.name }}</text>
            <text
                :x="activeNode.x"
                :y="activeNode.y - 20"
                text-anchor="middle"
                fill="var(--color-ink-muted)"
                stroke="var(--color-surface)"
                stroke-width="4"
                paint-order="stroke"
                font-size="12"
            >{{ activeNode.statusLabel }}</text>
        </g>
    </svg>
</template>
