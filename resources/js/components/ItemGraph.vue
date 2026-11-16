<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { forceCollide, forceLink, forceManyBody, forceSimulation, forceX, forceY } from 'd3-force';
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
 *
 * **Läget `container` är nivån *Whole container*** (issue 394 · § 6): samma
 * layout och samma linjer över hela containern, bara att läsa. Bilden
 * panoreras med dra eller piltangenterna och zoomas med hjul, nyp, knapparna
 * `+` och `−` eller tangenterna `+` och `−`. Noderna färgas efter status och
 * namnen ritas först över en zoomnivå. `center` markerar `focus` och vyn börjar
 * centrerad på det; sökningen (`query`) tänder träffarna och tonar ned resten
 * utan att gömma något. Ett klick går till *Focus* med noden som mitt. Med
 * `truncated` ritas en mening i stället för grafen, utan något antal.
 */
const props = defineProps({
    /* `{ulid, name, icon, icon_color, status}` i namnordning. */
    nodes: { type: Array, required: true },
    /* `{from, to, kind}` med ULID:er; `kind` är `parent` eller `related`. */
    edges: { type: Array, required: true },
    /* Mitten, som en nod-ULID. I läget `container` är den valfri (`focus`). */
    center: { type: String, default: null },
    containerUlid: { type: String, required: true },
    /* `neighbourhood` (issue 393) eller `container` (issue 394). */
    mode: { type: String, default: 'neighbourhood' },
    /* Sökfältets `?q=`: träffarna tänds i läget `container`. */
    query: { type: String, default: null },
    /* Containern är över taket: en mening i stället för grafen. */
    truncated: { type: Boolean, default: false },
});

const whole = computed(() => props.mode === 'container');

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
        .force('collide', forceCollide(30));

    // Hela containern har inget fast mitt: en svag dragning mot origo håller
    // ihop de fristående noderna.
    if (whole.value) {
        simulation.force('x', forceX(0).strength(0.04)).force('y', forceY(0).strength(0.04));
    }

    simulation.stop();

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

/* Sökningen: en nod är en träff när namnet innehåller `q` (skiftlägesfritt). */
const needle = computed(() => (props.query ?? '').trim().toLowerCase());

function isHit(node) {
    return needle.value !== '' && node.name.toLowerCase().includes(needle.value);
}

const placed = computed(() => props.nodes.map((node) => ({
    ...node,
    ...layout.value[node.ulid],
    isCenter: node.ulid === props.center,
    href: whole.value
        ? `/containers/${props.containerUlid}/items?view=map&focus=${node.ulid}`
        : `/containers/${props.containerUlid}/items?view=map&focus=${node.ulid}&depth=2`,
    hit: isHit(node),
    statusLabel: t(`item.graph.status.${node.status === 'overdue' ? 'overdue' : 'ok'}`),
})));

const centerName = computed(() => props.nodes.find((node) => node.ulid === props.center)?.name ?? '');

/* Noden som visar sin etikett: den man hovrar över eller har fokus på. */
const active = ref(null);

const activeNode = computed(() => placed.value.find((node) => node.ulid === active.value) ?? null);

/*
 * Panorering och zoom (läget `container`). Vyn är `{k, x, y}`: lagret ritas
 * med `translate(x y) scale(k)` i viewBox-enheter. `svg` hålls för att räkna om
 * skärmpixlar till viewBox-enheter.
 */
const MIN_ZOOM = 0.4;
const MAX_ZOOM = 8;
const LABEL_ZOOM = 1.6;
const DRAG_SLOP = 5;

const svg = ref(null);

const viewCenter = computed(() => {
    const [x, y, w, h] = viewBox.value.split(' ').map(Number);

    return { x: x + w / 2, y: y + h / 2 };
});

const view = ref(startView());

function startView() {
    const spot = props.center ? layout.value[props.center] : null;

    return spot ? { k: 1, x: viewCenter.value.x - spot.x, y: viewCenter.value.y - spot.y } : { k: 1, x: 0, y: 0 };
}

const layerTransform = computed(() => `translate(${view.value.x} ${view.value.y}) scale(${view.value.k})`);

const showNames = computed(() => view.value.k >= LABEL_ZOOM);

/* Skärmpixlar → viewBox-enheter (preserveAspectRatio `meet`). */
function unitsPerPixel() {
    const rect = svg.value.getBoundingClientRect();
    const [, , w, h] = viewBox.value.split(' ').map(Number);

    return Math.max(w / rect.width, h / rect.height) || 1;
}

/* Zoom runt en punkt i viewBox-enheter (utan lager). */
function zoomAround(factor, px, py) {
    const k = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, view.value.k * factor));
    const ratio = k / view.value.k;

    view.value = {
        k,
        x: px - (px - view.value.x) * ratio,
        y: py - (py - view.value.y) * ratio,
    };
}

function zoomButton(factor) {
    zoomAround(factor, viewCenter.value.x, viewCenter.value.y);
}

function pointOf(event) {
    const rect = svg.value.getBoundingClientRect();
    const scale = unitsPerPixel();
    const [x, y, w, h] = viewBox.value.split(' ').map(Number);

    return {
        x: x + w / 2 + (event.clientX - rect.left - rect.width / 2) * scale,
        y: y + h / 2 + (event.clientY - rect.top - rect.height / 2) * scale,
    };
}

function onWheel(event) {
    const point = pointOf(event);

    zoomAround(event.deltaY < 0 ? 1.2 : 1 / 1.2, point.x, point.y);
}

const pointers = new Map();
let pinchDistance = null;
let travelled = 0;
let dragged = false;

function onPointerDown(event) {
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    travelled = 0;
    dragged = false;
    pinchDistance = null;
}

function onPointerMove(event) {
    const last = pointers.get(event.pointerId);

    if (!last) {
        return;
    }

    const current = { x: event.clientX, y: event.clientY };

    if (pointers.size >= 2) {
        pointers.set(event.pointerId, current);

        const [a, b] = [...pointers.values()];
        const distance = Math.hypot(a.x - b.x, a.y - b.y);

        if (pinchDistance !== null && distance > 0) {
            const middle = pointOf({ clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 });

            zoomAround(distance / pinchDistance, middle.x, middle.y);
        }

        pinchDistance = distance;
        dragged = true;

        return;
    }

    travelled += Math.abs(current.x - last.x) + Math.abs(current.y - last.y);
    pointers.set(event.pointerId, current);

    if (travelled > DRAG_SLOP) {
        dragged = true;

        const scale = unitsPerPixel();

        view.value = {
            ...view.value,
            x: view.value.x + (current.x - last.x) * scale,
            y: view.value.y + (current.y - last.y) * scale,
        };
    }
}

function onPointerEnd(event) {
    pointers.delete(event.pointerId);
    pinchDistance = null;
}

const STEP = 40;

function onKeydown(event) {
    if (event.target !== svg.value || event.metaKey || event.ctrlKey || event.altKey) {
        return;
    }

    const pan = { ArrowLeft: [STEP, 0], ArrowRight: [-STEP, 0], ArrowUp: [0, STEP], ArrowDown: [0, -STEP] }[event.key];

    if (pan) {
        view.value = { ...view.value, x: view.value.x + pan[0], y: view.value.y + pan[1] };
    } else if (event.key === '+' || event.key === '=') {
        zoomButton(1.25);
    } else if (event.key === '-' || event.key === '_') {
        zoomButton(1 / 1.25);
    } else if (event.key === '0') {
        view.value = startView();
    } else {
        return;
    }

    event.preventDefault();
}

function go(event, href) {
    // Ett drag som slutar på en nod är en panorering och ingen navigering.
    if (dragged) {
        event.preventDefault();
        dragged = false;

        return;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) {
        return;
    }

    event.preventDefault();
    router.visit(href);
}
</script>

<template>
    <div class="hidden md:block">
        <p
            v-if="whole && truncated"
            data-item-graph-truncated
            class="rounded-card border border-border bg-surface p-4 text-sm text-ink-muted"
        >{{ t('item.graph.truncated') }}</p>

        <div v-else class="relative">
            <svg
                ref="svg"
                :viewBox="viewBox"
                role="group"
                :aria-label="whole ? t('item.graph.label_all') : t('item.graph.label', { name: centerName })"
                data-item-graph
                class="hidden h-[32rem] w-full rounded-card border border-border bg-surface md:block"
                :class="whole ? 'touch-none cursor-grab' : ''"
                preserveAspectRatio="xMidYMid meet"
                :tabindex="whole ? 0 : undefined"
                @wheel.prevent="whole && onWheel($event)"
                @pointerdown="whole && onPointerDown($event)"
                @pointermove="whole && onPointerMove($event)"
                @pointerup="whole && onPointerEnd($event)"
                @pointercancel="whole && onPointerEnd($event)"
                @pointerleave="whole && onPointerEnd($event)"
                @keydown="whole && onKeydown($event)"
            >
                <g :transform="whole ? layerTransform : undefined">
                    <g aria-hidden="true" :opacity="whole && needle !== '' ? 0.35 : undefined">
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
                        :data-hit="whole && node.hit ? '' : undefined"
                        :opacity="whole && needle !== '' && !node.hit ? 0.25 : undefined"
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
                            :stroke="node.isCenter || (whole && node.hit) ? 'var(--color-ink)' : 'var(--color-surface)'"
                            stroke-width="3"
                        />
                    </a>

                    <g v-if="whole" aria-hidden="true" class="pointer-events-none">
                        <template v-for="node in placed" :key="node.ulid">
                            <text
                                v-if="(showNames || node.hit || node.isCenter) && node.ulid !== active"
                                :x="node.x"
                                :y="node.y - 20"
                                text-anchor="middle"
                                fill="var(--color-ink)"
                                stroke="var(--color-surface)"
                                :stroke-width="4 / view.k"
                                paint-order="stroke"
                                :font-size="12 / view.k"
                                font-weight="600"
                                :opacity="needle !== '' && !node.hit ? 0.25 : undefined"
                            >{{ node.name }}</text>
                        </template>
                    </g>

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
                </g>
            </svg>

            <div v-if="whole" class="absolute right-3 top-3 flex flex-col gap-2">
                <button
                    type="button"
                    :aria-label="t('item.graph.zoom_in')"
                    class="flex h-11 w-11 items-center justify-center rounded-card border border-border bg-surface text-lg font-semibold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus"
                    @click="zoomButton(1.25)"
                >+</button>
                <button
                    type="button"
                    :aria-label="t('item.graph.zoom_out')"
                    class="flex h-11 w-11 items-center justify-center rounded-card border border-border bg-surface text-lg font-semibold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus"
                    @click="zoomButton(1 / 1.25)"
                >&minus;</button>
            </div>
        </div>
    </div>
</template>
