<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemIcon from './ItemIcon.vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En nod i containerns karta, se resources/js/components/ItemMap.vue,
 * issue 305 · [[M35 Itemets ikon och kartans kort]] § 305 och
 * [[ADR-0061 Kartans kort och kanter]] § 1–4.
 *
 * **Kortet har ikonen till vänster** (ADR:ns beslut 1): `ItemIcon` med `tile` i
 * en ruta på 40 px och ikonen i storleken 24, och till höger namnet i medelfet
 * med slagets ord under. Slagets ord färgas av slaget — *Parent* i `accent`,
 * *Child* i `success` och *Related* i `ink-muted` — men ordet självt kommer
 * färdigöversatt från anroparen, som äger teckenförklaringen. Kortet har fast
 * bredd, `--radius-card`, `--shadow-card` och en kant i `border`, så att
 * kartans rader står i linje (rutnätet kommer i issue 306).
 *
 * **Mitten är större** (ADR:ns beslut 2): ikonen ovanför namnet, slagets ord
 * under och en kant i `accent` med `accent-soft` som bakgrund. Kring
 * containern ritas `UiIcon` `container` i stället för en item-ikon, och ordet
 * *Container* i stället för *Current item*.
 *
 * **Öppna-ikonen är ett öga i kortets övre högra hörn** ([[ADR-0062 Kartans
 * luft, listans ruta och den löpande sökningen]] § 4 och 5): rollen `open` bär
 * Lucides `eye`, och knappen är fortfarande ett eget mål för tangentbordet med
 * samma `aria-label` som namnger itemet ([[ADR-0059 Kartan med en mitt]] § 3).
 *
 * **Plusmenyn sitter inne i mittkortet** (ADR-0062 § 5): knappen ligger i
 * kortets `relative`-ram, absolut i det nedre högra hörnet, och ritas bara när
 * `rows` inte är tom. Både ögat och plustecknet har 44 px träffyta, och
 * tabbordningen är kortet, ögat och plustecknet — därför står plusknappen efter
 * ögats `<Link>` i markupen. Kring containern finns ingen öppna-länk (den är
 * inget item) och inga rader, så kortet får varken öga eller plus. Ingenting
 * ritas utanför kortets ram i nodens `<li>`.
 *
 * **Noden visar inte antal barn** (ADR:ns beslut 4). Statusen ritas bara när
 * den är `overdue`: en prick i `danger` vid namnet, med ordet i `sr-only` och
 * i `title`. En nod som är `ok` har ingen prick, för den säger bara att
 * ingenting hänt. ⇄ och antalet platser står kvar när platserna är fler än en.
 *
 * **En föräldranod bär sin egen väg från roten** (issue 298 · [[ADR-0059 Kartan
 * med en mitt]] § 9): `node.via` är namnen ned till dess första förälder och
 * står under slagets ord, skild med ›. Raden klipps av och hela vägen ligger i
 * `title`. För en rot är `via` tom och ingen rad ritas.
 *
 * **Kortet märker sig för linjerna** (issue 306 · [[ADR-0061 Kartans kort och
 * kanter]] § 6): `data-map-node` bär ULID:n och `data-map-kind` slaget, så att
 * `ItemMapEdges` kan mäta kortet med `getBoundingClientRect()` och veta vilken
 * linje det ska ha. Märket sitter på kortet och inte på raden, eftersom det är
 * kortets kant linjen slutar vid.
 *
 * Träffytorna är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och fokusringen
 * får aldrig tas bort ([[ADR-0042 Designsystemet]] § Beslut).
 */
const props = defineProps({
    /* Noden: `{ulid, name, icon, icon_color, children, placements}`. */
    node: { type: Object, required: true },
    /* Slaget: `parent`, `child` eller `related` — tomt för mitten. */
    kind: { type: String, default: '' },
    /* Slagets ord, färdigöversatt av anroparen — tomt för containern. */
    label: { type: String, default: '' },
    /* `ok`, `overdue` eller tomt — tomt för containern, som inte är ett item. */
    status: { type: String, default: '' },
    /* Länken som gör noden till mitten, eller null för mitten själv. */
    href: { type: String, default: null },
    /* Länken till itemets sida, eller null för containern. */
    openHref: { type: String, default: null },
    /* Sant för noden i mitten: `aria-current` och accentens yta. */
    current: { type: Boolean, default: false },
    /* Mittens menyrader, `[{key, href}]` — tomma för varje annan nod. */
    rows: { type: Array, default: () => [] },
});

const emit = defineEmits(['open-menu']);

const { t } = useTranslations();

/*
 * Slagens färger (ADR-0061 § 1). Klasserna står som hela strängar: Tailwind
 * läser källkoden statiskt och ser inte en klass som byggs med mallsträng.
 * Mitten har tomt slag och bär accentens färg.
 */
const KIND_CLASSES = {
    parent: 'text-accent',
    child: 'text-success',
    related: 'text-ink-muted',
};

const kindClass = computed(() => KIND_CLASSES[props.kind] ?? 'text-accent');

/* Statusordet är översiktens (`item.index.status_*`), som i listan. */
const overdueWord = computed(() => t('item.index.status_overdue'));
</script>

<template>
    <li class="flex items-stretch gap-1">
        <!--
            Kortet är en länk för varje nod utom mitten. `component :is` håller
            formen i en enda uppsättning markup: en `<Link>` när noden har en
            adress, en `<span>` med `aria-current` när den är mitten. Ramen är
            `relative`, så att öppna-ikonen och plusknappen hamnar i KORTETS
            hörn och inte i radens.
        -->
        <div class="relative flex">
            <component
                :is="href ? Link : 'span'"
                :href="href ?? undefined"
                :aria-current="current ? 'page' : undefined"
                :data-map-node="node.ulid"
                :data-map-kind="current ? 'center' : kind"
                class="flex min-h-11 min-w-0 items-center rounded-card border shadow-card outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="current
                    ? 'w-56 flex-col justify-center gap-1 border-accent bg-accent-soft py-3 pl-8 pr-12 text-center'
                    : 'w-48 gap-3 border-border bg-surface py-2 pl-3 pr-10 text-left'"
            >
                <!-- Mitten: ikonen ovanför namnet, ordet under. -->
                <template v-if="current">
                    <UiIcon
                        v-if="node.kind === 'container'"
                        name="container"
                        :size="24"
                        class="text-accent"
                    />
                    <ItemIcon
                        v-else
                        :name="node.icon"
                        :color="node.icon_color"
                        :size="24"
                        class="shrink-0"
                    />

                    <span class="flex max-w-full min-w-0 items-center gap-1">
                        <span
                            class="min-w-0 truncate text-body font-medium text-ink"
                            :title="node.name"
                        >
                            {{ node.name }}
                        </span>

                        <!-- Pricken ritas bara för overdue, med ordet för skärmläsaren. -->
                        <span
                            v-if="status === 'overdue'"
                            class="h-2 w-2 shrink-0 rounded-pill bg-danger"
                            :title="overdueWord"
                            aria-hidden="true"
                        ></span>
                        <span v-if="status === 'overdue'" class="sr-only">{{ overdueWord }}</span>
                    </span>

                    <span v-if="label" class="text-meta text-accent">{{ label }}</span>

                    <span
                        v-if="node.placements != null && node.placements > 1"
                        class="flex items-center gap-1 text-meta text-ink-muted"
                    >
                        <span aria-hidden="true">&harr; {{ node.placements }}</span>
                        <span class="sr-only">
                            {{ t('item.map.placements', { count: node.placements }) }}
                        </span>
                    </span>
                </template>

                <!-- Noderna: ikonen till vänster, namnet och slagets ord till höger. -->
                <template v-else>
                    <ItemIcon
                        :name="node.icon"
                        :color="node.icon_color"
                        :size="24"
                        tile
                        class="h-10 w-10 shrink-0"
                    />

                    <span class="flex min-w-0 flex-col">
                        <span class="flex min-w-0 items-center gap-1">
                            <span
                                class="min-w-0 truncate text-body font-medium text-ink"
                                :title="node.name"
                            >
                                {{ node.name }}
                            </span>

                            <span
                                v-if="status === 'overdue'"
                                class="h-2 w-2 shrink-0 rounded-pill bg-danger"
                                :title="overdueWord"
                                aria-hidden="true"
                            ></span>
                            <span v-if="status === 'overdue'" class="sr-only">{{ overdueWord }}</span>
                        </span>

                        <span v-if="label" class="text-meta" :class="kindClass">{{ label }}</span>

                        <!-- Förälderns egen väg från roten, avklippt med hela i `title`. -->
                        <span
                            v-if="node.via && node.via.length > 0"
                            class="w-full truncate text-meta text-ink-subtle"
                            :title="node.via.join(' › ')"
                        >
                            {{ node.via.join(' › ') }}
                        </span>

                        <span
                            v-if="node.placements != null && node.placements > 1"
                            class="flex items-center gap-1 text-meta text-ink-muted"
                        >
                            <span aria-hidden="true">&harr; {{ node.placements }}</span>
                            <span class="sr-only">
                                {{ t('item.map.placements', { count: node.placements }) }}
                            </span>
                        </span>
                    </span>
                </template>
            </component>

            <!--
                Öppna itemet, i kortets övre högra hörn. Ikonen är
                `aria-hidden`; etiketten bär namnet, och ytan är 44 px som
                varje annat mål.
            -->
            <Link
                v-if="openHref"
                :href="openHref"
                :aria-label="t('item.map.open', { name: node.name })"
                class="absolute right-0 top-0 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-accent outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <UiIcon name="open" :size="16" />
            </Link>

            <!--
                Plusmenyn, i kortets nedre högra hörn och efter ögat i
                tabbordningen. Bara mitten har rader, och en tom meny ger
                ingen knapp.
            -->
            <button
                v-if="rows.length > 0"
                type="button"
                class="absolute bottom-0 right-0 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-pill text-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :aria-label="t('item.map.node_menu', { name: node.name })"
                @click="emit('open-menu', { rows, trigger: $event.currentTarget, name: node.name })"
            >
                <UiIcon name="add" :size="16" />
            </button>
        </div>
    </li>
</template>
