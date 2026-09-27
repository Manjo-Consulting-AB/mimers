<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ContainerMapNode from './ContainerMapNode.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Containerkartan, se issue 157 · [[M23 Mobilen och kartan]] § 157 och
 * [[ADR-0046 Containerns karta]] § Beslut.
 *
 * **Kartan är strukturträdet ritat som noder, med en öppen gren per nivå.**
 * Den ritar samma träd som strukturpanelen — `structure`-proppen i trädläget
 * och `map`-proppen här kommer ur samma kanter, samma rotregel och samma
 * omfång — men bara en nivå i taget öppnas. Det är därför layouten är trivial:
 * kolumner på en bred skärm och ett rutnät på en telefon, ingen grafalgoritm
 * och inget nytt paket (ADR:ns alternativ).
 *
 * **Två former, en data.** Över `md:` ritas en kolumn per nivå från vänster
 * till höger, med den öppna noden markerad i var sin kolumn; under `md:` ritas
 * bara noden man står på, med sin förälder som en knapp ovanför och barnen i
 * ett rutnät med två kolumner under. Båda läser `levels` och `trail` — formen
 * är den enda skillnaden, och att rita samma nivåer två gånger är billigare än
 * två komponenter som glider isär.
 *
 * **Den öppna vägen bor i adressen, och komponenten håller inget tillstånd.**
 * `trail` är serverns LÖSTA väg: en begärd väg som inte längre finns är kapad
 * där den brister innan vyn ser den (App\Actions\Item\ResolveItemMap), så
 * markeringen pekar alltid på en nod som finns. Att öppna en nod är att bygga
 * en ny adress — `path` med ledet fram till och med noden — och adressen är
 * också svaret på bakåtknappen: servern läser samma sträng igen. Samma
 * konstruktion som `?path=` i itemvyn (issue 95) och `?view=` i växeln (issue
 * 154).
 *
 * **Ingen horisontell skrollning.** Kolumnerna bryter i stället för att skjuta
 * ut: en karta som skrollar i sidled är en karta man inte ser, och regeln
 * gäller hela produkten (issue 68a § Beslut 2).
 *
 * **Plusknappen är skalets och inte kartans.** Den ritas av `AppLayout` ur
 * sidans `create`-propp, och servern pekar den mot den markerade noden (issue
 * 152 · § 157) — komponenten här vet inte att knappen finns.
 *
 * **Sökvägen ovanför kartan är telefonens**, och varje led i den går att
 * trycka på: på en bred skärm syns hela vägen som kolumner, och en brödsmula
 * hade sagt samma sak två gånger.
 */
const props = defineProps({
    /* Kartan: `{trail, levels}` ur App\Actions\Item\ResolveItemMap. */
    map: { type: Object, required: true },
    /* Item-ULID → status (`ok` eller `overdue`), för de ritade noderna. */
    statuses: { type: Object, required: true },
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();

const trail = computed(() => props.map.trail);
const levels = computed(() => props.map.levels);

/*
 * Den markerade noden i en nivå. Servern märker den — vyn jämför aldrig en
 * ULID mot en sträng själv, samma regel som `ItemStructureTree` följer med
 * `activeTrail` — och ett led utan markering ger null i stället för en
 * gissning.
 */
function currentIn(index) {
    return (levels.value[index] ?? []).find((node) => node.current) ?? null;
}

/* Noden man står på, eller null när ingen gren är öppen. */
const current = computed(() => (trail.value.length === 0 ? null : currentIn(trail.value.length - 1)));

/* Föräldern: ledet ovanför. En rot har ingen, och då ritas ingen knapp. */
const parent = computed(() => (trail.value.length < 2 ? null : currentIn(trail.value.length - 2)));

/*
 * Fronten: barnen till noden man står på. Är ingen gren öppen är fronten
 * rötterna — man står i toppen av trädet, och det finns ingenting ovanför.
 */
const frontier = computed(() => levels.value[trail.value.length] ?? []);

/*
 * Sökvägen: leden från roten ned till noden man står på, i ordning. Nivån
 * följer med varje led, för adressen byggs ur den och ett filtrerat led hade
 * annars gett fel nivå — en sökväg som tappade ett steg skulle peka på nästa.
 */
const path = computed(() => trail.value
    .map((ulid, level) => ({ node: currentIn(level), level }))
    .filter((step) => step.node !== null));

/*
 * Adressen som öppnar $ulid på nivå $index: vägen fram till nivån, och så
 * noden. Ledet efter den lämnas utanför med flit — att öppna en annan nod på
 * samma nivå STÄNGER den förra (ADR:ns beslut), och en adress som bar med sig
 * det gamla ledet hade bett servern om att kapa det.
 */
function hrefFor(ulid, index) {
    return `/containers/${props.containerUlid}/items?view=map&path=${[...trail.value.slice(0, index), ulid].join('.')}`;
}
</script>

<template>
    <div role="group" :aria-label="t('item.board.label')">
        <!--
            Den breda skärmen: en kolumn per nivå, vänster till höger, med en
            linje mellan sig. Kolumnerna bryter i stället för att skrolla.
        -->
        <div class="hidden md:flex md:flex-wrap md:items-start md:gap-6">
            <div
                v-for="(column, level) in levels"
                :key="level"
                class="flex min-w-0 flex-col gap-1"
                :class="level > 0 ? 'md:border-l md:border-border md:pl-6' : ''"
            >
                <ul class="flex flex-col gap-1">
                    <ContainerMapNode
                        v-for="node in column"
                        :key="node.ulid"
                        :node="node"
                        :status="statuses[node.ulid]"
                        :href="node.current ? null : hrefFor(node.ulid, level)"
                    />
                </ul>
            </div>
        </div>

        <!-- Telefonen: sökvägen, föräldern, noden man står på och barnen. -->
        <div class="md:hidden">
            <nav v-if="path.length > 0" :aria-label="t('item.board.path')">
                <ol class="flex flex-wrap items-center gap-x-1">
                    <li v-for="step in path" :key="step.node.ulid">
                        <Link
                            :href="hrefFor(step.node.ulid, step.level)"
                            class="inline-flex min-h-11 items-center rounded-control px-2 text-meta text-accent hover:underline"
                        >
                            {{ step.node.name }}
                        </Link>
                    </li>
                </ol>
            </nav>

            <Link
                v-if="parent"
                :href="hrefFor(parent.ulid, trail.length - 2)"
                :aria-label="t('item.board.up', { name: parent.name })"
                class="mt-2 flex min-h-11 w-fit items-center gap-2 rounded-control border border-border bg-surface px-3 text-meta text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <span aria-hidden="true">&uarr;</span>
                <span class="truncate" :title="parent.name">{{ parent.name }}</span>
            </Link>

            <ul v-if="current" class="mt-2">
                <ContainerMapNode :node="current" :status="statuses[current.ulid]" />
            </ul>

            <ul class="mt-4 grid grid-cols-2 gap-2">
                <ContainerMapNode
                    v-for="node in frontier"
                    :key="node.ulid"
                    :node="node"
                    :status="statuses[node.ulid]"
                    :href="hrefFor(node.ulid, trail.length)"
                />
            </ul>
        </div>
    </div>
</template>
