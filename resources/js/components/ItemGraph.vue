<script setup>
import { computed, onBeforeUnmount, ref, shallowReactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { forceCollide, forceLink, forceManyBody, forceSimulation, forceX, forceY } from 'd3-force';
import { useTranslations } from '../composables/useTranslations.js';
import { loadIcon } from './itemIconPresentation.js';

/*
 * Kartans nivå *Neighbourhood* — se issue 393 · [[ADR-0082 Kartan är
 * relationernas yta]] § 5 och 9.
 *
 * **Layouten räknas här och ritas som SVG** med designsystemets token, så att
 * mörkt läge byter färgerna på samma ställe som resten av ytan.
 *
 * **Läget `neighbourhood` är ett radialt träd** (issue 396 · [[ADR-0083
 * Grannskapet är itemets släkt]] § 4–7), räknat utan simulering. Mitten står
 * i origo och varje nod på ringen ur sin `ring`. Varje nod är förankrad i den
 * första av sina grannar på ringen innanför (serverns namnordning) — ankaret
 * är ett layoutbegrepp och läser aldrig kanternas `kind` — och får en
 * vinkelsektor i proportion till antalet noder förankrade i den. Övriga
 * grannar på ringen innanför ritas som längre linjer. Ringarna ritas som
 * breda, dämpade cirklar under kanterna. Noden är itemets ikon i `icon_color`
 * med namnet under; mitten har en ring i `accent`. Samma svar ger samma bild:
 * ingen slump och ingen tid.
 *
 * **Läget `container` använder `d3-force`** (bara det paketet). Startpositionerna
 * följer nodernas ordning på en spiral, mitten ligger fast i origo,
 * slumptalskällan är en fast följd och simuleringen körs ett fast antal steg på
 * en gång. Ingen `Math.random()` och ingen tidtagning ingår.
 *
 * **Linjerna följer `ItemMapEdges`:** heldragna till föräldrar och barn och
 * streckade till de relaterade, utan spetsar — placeringen säger inte
 * riktningen här, och relationens slag gör det inte heller.
 *
 * **Noderna är länkar.** Ett klick gör noden till mitt på samma nivå
 * (`?view=map&focus=<nod>&depth=linked`), och de når med Tab. Fokus och hovring
 * visar namn och status. Ingen redigering och ingen plusmeny.
 *
 * **Läget `container` är nivån *Whole container*** (issue 394 · § 6, issue 397 ·
 * [[ADR-0084 Hela containern med itemets ikon]]): samma linjer över hela
 * containern, bara att läsa, och **samma nod som *Neighbourhood*** — en rund
 * platta med itemets ikon. Status `overdue` är en liten prick i `danger` nere
 * till höger; `ok` har ingen. Namnen har fast storlek på skärmen och ritas
 * från början under `ALL_NAMES_BELOW` noder, annars från `LABEL_ZOOM`; i
 * *Neighbourhood* ritas alla namn från start.
 * **Panoreringen och zoomen gäller båda lägena** (issue 396 · § 7). Bilden
 * panoreras med dra eller piltangenterna och zoomas med hjul, nyp, knapparna
 * `+` och `−` eller tangenterna `+` och `−`, till som mest fem steg över
 * normal storlek (`MAX_ZOOM`). `center` markerar `focus` och vyn börjar
 * centrerad på det; sökningen (`query`) tänder träffarna och tonar ned resten
 * utan att gömma något. Ett klick går till *Focus* med noden som mitt. Med
 * `truncated` ritas en mening i stället för grafen, i båda lägena, utan något antal.
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
/* Plattans radie; mitten är större. Layoutens avstånd räknas ur dem. */
const PLATE_RADIUS = 16;
const CENTER_PLATE_RADIUS = 20;
/* Avstånden växer med plattan så att noderna och namnen under dem inte överlappar. */
const COLLIDE_RADIUS = PLATE_RADIUS * 2.75;
const LINK_DISTANCE = PLATE_RADIUS * 7.5;
const SPIRAL_GAP = PLATE_RADIUS * 3;
/* Under så här många noder ritas alla namn från början (ADR-0084 § 4). */
const ALL_NAMES_BELOW = 40;

/* En fast slumptalskälla (LCG), så att inget i layouten beror på tiden. */
function seeded() {
    let state = 1;

    return () => {
        state = (state * 1664525 + 1013904223) % 4294967296;

        return state / 4294967296;
    };
}

/* Kraftlayouten för läget `container`: ulid → `{x, y}`. */
function forceLayout() {
    const golden = Math.PI * (3 - Math.sqrt(5));

    const simNodes = props.nodes.map((node, index) => {
        const isCenter = node.ulid === props.center;
        const radius = SPIRAL_GAP * Math.sqrt(index + 1);

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
        .force('link', forceLink(links).id((node) => node.ulid).distance(LINK_DISTANCE).strength(0.6))
        .force('charge', forceManyBody().strength(-300))
        .force('collide', forceCollide(COLLIDE_RADIUS));

    // Hela containern har inget fast mitt: en svag dragning mot origo håller
    // ihop de fristående noderna.
    simulation.force('x', forceX(0).strength(0.04)).force('y', forceY(0).strength(0.04));

    simulation.stop();

    simulation.tick(STEPS);

    const positions = {};

    for (const node of simNodes) {
        positions[node.ulid] = { x: node.x, y: node.y };
    }

    return positions;
}


const RING_GAP = 120;
const NODE_SPACING = 96;

/*
 * Den radiala layouten för läget `neighbourhood`: ulid → `{x, y}` plus
 * ringarnas radier. Ren beräkning; `nodes` är i namnordning och ordningen ger
 * både ankaret och sektorernas följd.
 */
function radialLayout() {
    const order = new Map(props.nodes.map((node, index) => [node.ulid, index]));
    const ringOf = (ulid) => props.nodes[order.get(ulid)].ring ?? 0;

    const neighbours = new Map(props.nodes.map((node) => [node.ulid, []]));

    for (const edge of props.edges) {
        if (order.has(edge.from) && order.has(edge.to)) {
            neighbours.get(edge.from).push(edge.to);
            neighbours.get(edge.to).push(edge.from);
        }
    }

    // Ankaret: den första grannen på ringen innanför, utan hänsyn till kantens slag.
    const children = new Map(props.nodes.map((node) => [node.ulid, []]));
    let root = props.center && order.has(props.center) ? props.center : null;

    for (const node of props.nodes) {
        if (node.ulid === root || ringOf(node.ulid) === 0) {
            root ??= node.ulid;

            continue;
        }

        const inner = neighbours.get(node.ulid)
            .filter((other) => ringOf(other) === ringOf(node.ulid) - 1)
            .sort((a, b) => order.get(a) - order.get(b));

        if (root !== null) {
            children.get(inner[0] ?? root).push(node.ulid);
        }
    }

    const weight = new Map();

    function weigh(ulid) {
        const total = children.get(ulid).reduce((sum, child) => sum + weigh(child), 1);

        weight.set(ulid, total);

        return total;
    }

    const perRing = new Map();

    for (const node of props.nodes) {
        perRing.set(ringOf(node.ulid), (perRing.get(ringOf(node.ulid)) ?? 0) + 1);
    }

    const radii = [0];

    for (let ring = 1; ring <= Math.max(0, ...perRing.keys()); ring++) {
        radii[ring] = Math.max(radii[ring - 1] + RING_GAP, ((perRing.get(ring) ?? 0) * NODE_SPACING) / (2 * Math.PI));
    }

    const positions = {};

    if (root === null) {
        return { positions, radii };
    }

    weigh(root);
    positions[root] = { x: 0, y: 0 };

    function spread(ulid, from, to) {
        let start = from;

        for (const child of children.get(ulid)) {
            const end = start + ((to - from) * weight.get(child)) / (weight.get(ulid) - 1);
            const angle = (start + end) / 2;

            positions[child] = { x: radii[ringOf(child)] * Math.cos(angle), y: radii[ringOf(child)] * Math.sin(angle) };

            spread(child, start, end);
            start = end;
        }
    }

    spread(root, -Math.PI / 2, (3 * Math.PI) / 2);

    return { positions, radii };
}

const radial = computed(() => (whole.value ? { positions: {}, radii: [0] } : radialLayout()));

const rings = computed(() => radial.value.radii.slice(1));

/* Layouten: ulid → `{x, y}`, räknad en gång per grannskap. */
const layout = computed(() => (whole.value ? forceLayout() : radial.value.positions));

const viewBox = computed(() => {
    const points = Object.values(layout.value);
    const pad = 80;

    if (points.length === 0) {
        return '0 0 1 1';
    }

    // Släkten ligger runt origo: en kvadrat kring yttersta ringen visar allt.
    if (!whole.value) {
        const reach = rings.value[rings.value.length - 1] + pad;

        return `${-reach} ${-reach} ${2 * reach} ${2 * reach}`;
    }

    const xs = points.map((point) => point.x);
    const ys = points.map((point) => point.y);
    const minX = Math.min(...xs) - pad;
    const minY = Math.min(...ys) - pad;

    return `${minX} ${minY} ${Math.max(...xs) + pad - minX} ${Math.max(...ys) + pad - minY}`;
});

/* Kanter mellan ringar är raka; inom en ring är de en båge utåt. */
function pathBetween(from, to) {
    const straight = `M ${from.x} ${from.y} L ${to.x} ${to.y}`;

    if (whole.value || Math.hypot(from.x, from.y).toFixed(3) !== Math.hypot(to.x, to.y).toFixed(3)) {
        return straight;
    }

    const bulge = 1.35;

    return `M ${from.x} ${from.y} Q ${((from.x + to.x) / 2) * bulge} ${((from.y + to.y) / 2) * bulge} ${to.x} ${to.y}`;
}

const lines = computed(() => props.edges
    .filter((edge) => layout.value[edge.from] && layout.value[edge.to])
    .map((edge) => ({
        key: `${edge.kind}:${edge.from}:${edge.to}`,
        d: pathBetween(layout.value[edge.from], layout.value[edge.to]),
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
    iconNodes: icons[node.icon] ?? null,
    tone: TONE_CLASS[node.icon_color] ?? TONE_CLASS.slate,
    short: shortName(node.name),
    href: whole.value
        ? `/containers/${props.containerUlid}/items?view=map&focus=${node.ulid}`
        : `/containers/${props.containerUlid}/items?view=map&focus=${node.ulid}&depth=linked`,
    hit: isHit(node),
    statusLabel: t(`item.graph.status.${node.status === 'overdue' ? 'overdue' : 'ok'}`),
})));

/*
 * Ikonerna: Lucide-namn → ritnoder, hämtade en gång per namn med samma lata
 * glob som `ItemIcon` (`itemIconPresentation.js`). Medan en fil hämtas, och för
 * ett okänt namn, ritas en neutral prick.
 */
const icons = shallowReactive({});

watch(
    () => props.nodes,
    (nodes) => {
        for (const name of new Set(nodes.map((node) => node.icon))) {
            const loader = name in icons ? null : loadIcon(name);

            if (loader) {
                icons[name] = null;
                loader().then((loaded) => {
                    icons[name] = loaded;
                });
            }
        }
    },
    { immediate: true },
);

/* Klasserna står som hela strängar så att Tailwind ser dem (som `ItemIcon`). */
const TONE_CLASS = {
    slate: 'text-tint-slate',
    blue: 'text-tint-blue',
    sky: 'text-tint-sky',
    teal: 'text-tint-teal',
    green: 'text-tint-green',
    amber: 'text-tint-amber',
    orange: 'text-tint-orange',
    violet: 'text-tint-violet',
};

const NAME_LENGTH = 18;

function shortName(name) {
    return name.length > NAME_LENGTH ? `${name.slice(0, NAME_LENGTH - 1)}…` : name;
}

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
/* Knappens och tangentens steg. Taket är fem steg över normal storlek. */
const ZOOM_STEP = 1.25;
const MAX_ZOOM = ZOOM_STEP ** 5;
const LABEL_ZOOM = 1.6;
const DRAG_SLOP = 5;

const svg = ref(null);

/* Skärmstorlekar i pixlar (ADR-0084 § 3): oavsett zoom och antal ringar. */
const NAME_PX = 14;
const HOVER_NAME_PX = 16;
const HOVER_STATUS_PX = 14;
const OUTLINE_PX = 4;
const LABEL_GAP = 4;

/* viewBox-enheter per skärmpixel, reaktivt. */
const pixelUnits = ref(1);
let resizeObserver = null;

function measureUnits() {
    const u = svg.value ? unitsPerPixel() : 1;
    pixelUnits.value = Number.isFinite(u) && u > 0 ? u : 1;
}

watch(svg, (element) => {
    resizeObserver?.disconnect();
    resizeObserver = null;

    if (!element) {
        return;
    }

    measureUnits();

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(measureUnits);
        resizeObserver.observe(element);
    }
}, { flush: 'post' });

watch(() => viewBox.value, () => measureUnits(), { flush: 'post' });

onBeforeUnmount(() => resizeObserver?.disconnect());

/* Pixlar → enheter i lagret (som skalas med zoomen). */
const layerUnits = (px) => (px * pixelUnits.value) / view.value.k;

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

const showNames = computed(() => view.value.k >= LABEL_ZOOM
    || !whole.value
    || (whole.value && props.nodes.length < ALL_NAMES_BELOW));

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
    event.preventDefault();

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

    // Ett drag som slutar utanför svg:n får inte svälja nästa klick.
    if (pointers.size === 0 && event.type === 'pointerleave') {
        dragged = false;
    }
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
        zoomButton(ZOOM_STEP);
    } else if (event.key === '-' || event.key === '_') {
        zoomButton(1 / ZOOM_STEP);
    } else if (event.key === '0') {
        view.value = startView();
    } else {
        return;
    }

    event.preventDefault();
}

function go(event, href) {
    // Ett drag som slutar på en nod är en panorering och ingen navigering.
    // Ett klick från tangentbordet (detail === 0) följer aldrig på ett drag.
    if (event.detail === 0) {
        dragged = false;
    }

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
            v-if="truncated"
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
                class="hidden h-[clamp(32rem,calc(100dvh-14rem),56rem)] w-full touch-none cursor-grab rounded-card border border-border bg-surface md:block"
                preserveAspectRatio="xMidYMid meet"
                :tabindex="0"
                @wheel="onWheel($event)"
                @pointerdown="onPointerDown($event)"
                @pointermove="onPointerMove($event)"
                @pointerup="onPointerEnd($event)"
                @pointercancel="onPointerEnd($event)"
                @pointerleave="onPointerEnd($event)"
                @keydown="onKeydown($event)"
            >
                <g :transform="layerTransform">
                    <g v-if="!whole" aria-hidden="true" data-item-graph-rings>
                        <circle
                            v-for="(radius, index) in rings"
                            :key="index"
                            cx="0"
                            cy="0"
                            :r="radius"
                            fill="none"
                            stroke="#000"
                            stroke-width="4.5"
                        />
                    </g>

                    <g aria-hidden="true" :opacity="whole && needle !== '' ? 0.35 : undefined">
                        <path
                            v-for="line in lines"
                            :key="line.key"
                            :d="line.d"
                            fill="none"
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
                            :r="node.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS"
                            fill="var(--color-surface)"
                            :stroke="node.isCenter ? 'var(--color-accent)' : 'var(--color-border)'"
                            :stroke-width="node.isCenter ? 3 : 1.5"
                        />
                        <g
                            v-if="node.iconNodes"
                            :transform="`translate(${node.x - 10} ${node.y - 10}) scale(${20 / 24})`"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            :class="node.tone"
                        >
                            <component :is="tag" v-for="([tag, attributes], index) in node.iconNodes" :key="index" v-bind="attributes" />
                        </g>
                        <circle v-else :cx="node.x" :cy="node.y" r="4" fill="var(--color-ink-subtle)" />
                        <g v-if="node.status === 'overdue'" data-item-graph-overdue>
                            <circle
                                :cx="node.x + (node.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS) * 0.7"
                                :cy="node.y + (node.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS) * 0.7"
                                r="4.5"
                                fill="var(--color-danger)"
                                stroke="var(--color-surface)"
                                stroke-width="2"
                            />
                        </g>
                    </a>

                    <g aria-hidden="true" class="pointer-events-none">
                        <template v-for="node in placed" :key="node.ulid">
                            <text
                                v-if="(showNames || node.hit || node.isCenter) && node.ulid !== active"
                                :x="node.x"
                                :y="node.y + (node.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS) + LABEL_GAP + layerUnits(NAME_PX) * 0.8"
                                text-anchor="middle"
                                fill="var(--color-ink)"
                                stroke="var(--color-surface)"
                                :stroke-width="layerUnits(OUTLINE_PX)"
                                paint-order="stroke"
                                :font-size="layerUnits(NAME_PX)"
                                font-weight="600"
                                :opacity="needle !== '' && !node.hit ? 0.25 : undefined"
                            >{{ node.short }}</text>
                        </template>
                    </g>

                    <g v-if="activeNode" aria-hidden="true" class="pointer-events-none">
                        <text
                            :x="activeNode.x"
                            :y="activeNode.y - (activeNode.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS) - LABEL_GAP - layerUnits(HOVER_STATUS_PX) * 1.3"
                            text-anchor="middle"
                            fill="var(--color-ink)"
                            stroke="var(--color-surface)"
                            :stroke-width="layerUnits(OUTLINE_PX)"
                            paint-order="stroke"
                            :font-size="layerUnits(HOVER_NAME_PX)"
                            font-weight="600"
                        >{{ activeNode.name }}</text>
                        <text
                            :x="activeNode.x"
                            :y="activeNode.y - (activeNode.isCenter ? CENTER_PLATE_RADIUS : PLATE_RADIUS) - LABEL_GAP"
                            text-anchor="middle"
                            fill="var(--color-ink-muted)"
                            stroke="var(--color-surface)"
                            :stroke-width="layerUnits(OUTLINE_PX)"
                            paint-order="stroke"
                            :font-size="layerUnits(HOVER_STATUS_PX)"
                        >{{ activeNode.statusLabel }}</text>
                    </g>
                </g>
            </svg>

            <div class="absolute right-3 top-3 flex flex-col gap-2">
                <button
                    type="button"
                    :aria-label="t('item.graph.zoom_in')"
                    class="flex h-11 w-11 items-center justify-center rounded-card border border-border bg-surface text-lg font-semibold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus"
                    @click="zoomButton(ZOOM_STEP)"
                >+</button>
                <button
                    type="button"
                    :aria-label="t('item.graph.zoom_out')"
                    class="flex h-11 w-11 items-center justify-center rounded-card border border-border bg-surface text-lg font-semibold text-ink hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-focus"
                    @click="zoomButton(1 / ZOOM_STEP)"
                >&minus;</button>
            </div>
        </div>
    </div>
</template>
