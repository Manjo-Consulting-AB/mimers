<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemTagList from './ItemTagList.vue';
import UiIcon from './UiIcon.vue';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Itemfliken i läget *Träd* som tabell (issue 294 · [[ADR-0058 Trädet som
 * tabell]] § 1, 2, 3, 5 och 8).
 *
 * **Trädet är en tabell.** Kolumnerna är *Name*, *Category*, *Tags*,
 * *Documents*, *Tasks* och *Last updated*, och rubrikerna kommer ur
 * `lang/en/ui.php` som varje annan användarvänd text. Det här är en EGEN
 * komponent och inte en fjärde form av `ItemStructureTree`: en tabellrad och
 * ett led i en panel är olika element, och panelen (issue 103) och
 * föräldraväljaren (issue 153) ritar trädet som förut.
 *
 * **Containern är rotraden** (§ 2). Den har containerns namn och ingenting i
 * de andra kolumnerna, och trädets rötter står under den. Raden är öppen från
 * början, så trädet ser ut som i dag tills någon fäller upp en gren.
 *
 * **Noderna är förekomster och inte items**, samma regel som i
 * `ItemStructureTree.vue`: ett item med två föräldrar står på båda ställena.
 * Radens nyckel är därför hela ledet och inte ULID:n, så samma item två
 * gånger i trädet är två rader — med samma siffror, för siffrorna hör till
 * itemet (§ 3).
 *
 * **Länken bär vägen i querysträngen**, precis som `ItemStructureTree` gör:
 * ett klick på ett led landar på samma förekomst och inte på en godtycklig.
 * Adressen byggs här och aldrig på serversidan.
 *
 * **Kolumnerna räknas inte här.** `treeRows` är item-ULID → `{category, tags,
 * documents, tasks, updated_at}` ur App\Actions\Item\ResolveItemTreeRows
 * (issue 292), och vyn slår bara upp. `Last updated` ritas enligt datumregeln
 * med `eventDate()` (issue 104), aldrig som en egen formatering (§ 1).
 * Taggraderna återbrukar `ItemTagList`: högst två ritas, och resten blir
 * `+N` (§ 1).
 *
 * **Filtret gäller trädet** (§ 5). `filtered` säger att servern har filtrerat,
 * och då är träffarna halvfeta medan vägen från roten ned till dem inte är
 * det — vikten skiljer dem, inte bara en färg. Grenarna som leder till en
 * träff är öppna från början, så träffarna syns utan klick; utan filter är
 * utgångsläget stängt, som i trädläget har varit sedan issue 154.
 *
 * **Tillståndet bor i den här instansen.** `open` nycklas på ledet, så samma
 * item på två ställen fälls upp var för sig — samma val som
 * `ItemStructureTree` gjorde. `containerOpen` är rotradens egen flagga och
 * börjar sann.
 *
 * **`expandAll` är *Expand all* i filterraden** (issue 295 · [[ADR-0058
 * Trädet som tabell]] § 6). Flaggan ägs av vyn och kommer hit som en propp,
 * eftersom knappen står i filterraden och inte i tabellen. När den slås på
 * är varje gren öppen som standard; ett eget tryck på en fällknapp vinner
 * fortfarande över flaggan. Varje växling av flaggan glömmer de egna
 * trycken, så att en andra tryckning landar i utgångsläget — rotraden öppen
 * och, med filter, grenarna till träffarna — och inte i en blandning av
 * gamla tryck.
 *
 * **Smal skärm** (§ 8): under `md:` ritas bara namnet, med kategorin som
 * undertext. Kolumnerna kommer fram från `md:`; tabellen skrollar aldrig i
 * sidled, den fäller i stället undan.
 */
const props = defineProps({
    /* Trädets rötter ur `structure`-proppen: `{ulid, name, match, children}`. */
    nodes: { type: Array, required: true },
    /*
     * Item-ULID → `{category, tags, documents, tasks, updated_at}`, ur
     * App\Actions\Item\ResolveItemTreeRows. Samma form som sidans `treeRows`-
     * propp; vyn räknar ingenting själv.
     */
    treeRows: { type: Object, default: () => ({}) },
    containerUlid: { type: String, required: true },
    containerName: { type: String, required: true },
    /*
     * Sant när servern har filtrerat trädet (issue 293): träffarna ritas
     * halvfeta och grenarna till dem är öppna från början. Utan filter är
     * varje nod en träff, och då hade flaggan gjort hela trädet halvfett.
     */
    filtered: { type: Boolean, default: false },
    /*
     * Sant när *Expand all* är påslaget (issue 295 · [[ADR-0058 Trädet som
     * tabell]] § 6). Knappen bor i filterraden och tillståndet i vyn; här
     * öppnar flaggan varje gren som standard.
     */
    expandAll: { type: Boolean, default: false },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

/* Rotradens gren: öppen från början (§ 2). */
const containerOpen = ref(true);

/* Den här instansens fällda grenar, nycklade på ledet. */
const open = ref({});

/*
 * Ledets nyckel: ULID:na från roten ned till noden, med punkt emellan — samma
 * sträng som `?path=` bär och som `:key` använder.
 */
function trailKey(trail) {
    return trail.join('.');
}

/*
 * Är grenen öppen? Ett uttryckligt tryck vinner; annars är utgångsläget
 * stängt, utom i ett filtrerat träd där grenen leder till en träff och alltså
 * ska synas utan klick (§ 5), eller när *Expand all* är påslaget (§ 6).
 */
function isOpen(key) {
    return open.value[key] ?? (props.expandAll || props.filtered);
}

/*
 * En växling av *Expand all* nollställer de egna trycken: påslagen öppnar
 * flaggan allt, och avslagen lämnar den utgångsläget kvar — rotraden öppen
 * och, med filter, grenarna till träffarna (§ 6). Utan nollställningen hade
 * en andra tryckning återvänt till en blandning av tryck gjorda däremellan.
 */
watch(() => props.expandAll, () => {
    open.value = {};
    containerOpen.value = true;
});

function toggle(key) {
    open.value[key] = ! isOpen(key);
}

/*
 * Trädet utplattat till de rader som syns, i ritad ordning. Rekursionen
 * hoppar över en stängd gren: barnen finns men ritas inte förrän grenen är
 * öppen, och en rad som inte syns ska inte heller finnas i listan.
 */
function walk(nodes, trail, depth, rows) {
    for (const node of nodes) {
        const nodeTrail = [...trail, node.ulid];
        const key = trailKey(nodeTrail);

        rows.push({ node, key, depth });

        if (node.children.length > 0 && isOpen(key)) {
            walk(node.children, nodeTrail, depth + 1, rows);
        }
    }

    return rows;
}

/* Rotradens gren: är den stängd ritas inga itemrader alls. */
const visibleRows = computed(() => (containerOpen.value ? walk(props.nodes, [], 0, []) : []));

function nodeHref(row) {
    return `/containers/${props.containerUlid}/items/${row.node.ulid}?path=${row.key}`;
}

/* Kolumnerna för itemet, eller en tom rad om servern inte kände igen det. */
function cells(node) {
    return props.treeRows[node.ulid] ?? {};
}

/* Högst två taggar ritas; resten blir `+N` (§ 1). */
function shownTags(node) {
    return (cells(node).tags ?? []).slice(0, 2);
}

function extraTags(node) {
    return Math.max((cells(node).tags ?? []).length - 2, 0);
}

/* Träffen skiljs från vägen med vikten, och bara i ett filtrerat träd (§ 5). */
function isMatch(node) {
    return props.filtered && node.match === true;
}
</script>

<template>
    <table v-if="nodes.length > 0" class="w-full border-collapse text-left">
        <thead>
            <tr class="border-b border-border">
                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                    {{ t('item.tree.name') }}
                </th>
                <th scope="col" class="hidden py-2 pr-4 text-meta font-medium text-ink-subtle md:table-cell">
                    {{ t('item.tree.category') }}
                </th>
                <th scope="col" class="hidden py-2 pr-4 text-meta font-medium text-ink-subtle md:table-cell">
                    {{ t('item.tree.tags') }}
                </th>
                <th scope="col" class="hidden py-2 pr-4 text-meta font-medium text-ink-subtle md:table-cell">
                    {{ t('item.tree.documents') }}
                </th>
                <th scope="col" class="hidden py-2 pr-4 text-meta font-medium text-ink-subtle md:table-cell">
                    {{ t('item.tree.tasks') }}
                </th>
                <th scope="col" class="hidden py-2 pr-4 text-meta font-medium text-ink-subtle md:table-cell">
                    {{ t('item.tree.last_updated') }}
                </th>
            </tr>
        </thead>

        <tbody>
            <!--
                Containern som rotrad (§ 2): namn, ingen länk och ingenting i
                de andra kolumnerna. Fällknappen styr trädets rötter och bär
                samma etiketter som trädets övriga knappar.
            -->
            <tr class="border-b border-border bg-surface-muted">
                <td class="py-1 pr-4 text-body">
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            :aria-expanded="containerOpen ? 'true' : 'false'"
                            :aria-label="containerOpen
                                ? t('item.structure.collapse', { name: containerName })
                                : t('item.structure.expand', { name: containerName })"
                            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            @click="containerOpen = ! containerOpen"
                        >
                            <UiIcon
                                name="chevron-right"
                                :size="16"
                                class="h-4 w-4"
                                :class="containerOpen ? 'rotate-90' : ''"
                            />
                        </button>

                        <span class="inline-flex min-h-11 items-center px-2 font-semibold text-ink">
                            {{ containerName }}
                        </span>
                    </div>
                </td>
                <td class="hidden py-1 pr-4 md:table-cell"></td>
                <td class="hidden py-1 pr-4 md:table-cell"></td>
                <td class="hidden py-1 pr-4 md:table-cell"></td>
                <td class="hidden py-1 pr-4 md:table-cell"></td>
                <td class="hidden py-1 pr-4 md:table-cell"></td>
            </tr>

            <!--
                Trädets synliga rader, i serverns ordning. `row.key` är hela
                ledet, så samma item på två ställen är två rader — med samma
                siffror, för de hör till itemet och inte till förekomsten.
            -->
            <tr
                v-for="row in visibleRows"
                :key="row.key"
                class="border-b border-border hover:bg-surface-sunken"
            >
                <td class="py-1 pr-4 text-body">
                    <div class="flex items-center gap-1" :style="{ paddingLeft: `${row.depth * 20}px` }">
                        <!--
                            Fällknappen finns bara på en rad med barn — ett
                            löv har inget att fälla upp. Den står före ledet,
                            som i ItemStructureTree.
                        -->
                        <button
                            v-if="row.node.children.length > 0"
                            type="button"
                            :aria-expanded="isOpen(row.key) ? 'true' : 'false'"
                            :aria-label="isOpen(row.key)
                                ? t('item.structure.collapse', { name: row.node.name })
                                : t('item.structure.expand', { name: row.node.name })"
                            class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            @click="toggle(row.key)"
                        >
                            <UiIcon
                                name="chevron-right"
                                :size="16"
                                class="h-4 w-4"
                                :class="isOpen(row.key) ? 'rotate-90' : ''"
                            />
                        </button>

                        <!-- Håller namnet i linje med de rader som har en knapp. -->
                        <span v-else class="inline-block min-w-11 shrink-0" aria-hidden="true"></span>

                        <div class="flex min-w-0 flex-col">
                            <Link
                                :href="nodeHref(row)"
                                class="inline-flex min-h-11 items-center rounded-control px-2 text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                :class="isMatch(row.node)
                                    ? 'font-semibold text-ink'
                                    : 'text-ink-muted hover:bg-surface-sunken'"
                            >
                                {{ row.node.name }}
                            </Link>

                            <!-- Kategorin som undertext under `md:` (§ 8). -->
                            <span class="px-2 pb-1 text-xs text-ink-muted md:hidden">
                                {{ cells(row.node).category ?? '' }}
                            </span>
                        </div>
                    </div>
                </td>

                <td class="hidden py-1 pr-4 text-body text-ink-muted md:table-cell">
                    {{ cells(row.node).category ?? '' }}
                </td>

                <td class="hidden py-1 pr-4 md:table-cell">
                    <div class="flex flex-wrap items-center gap-2">
                        <ItemTagList v-if="shownTags(row.node).length > 0" :tags="shownTags(row.node)" />
                        <span v-if="extraTags(row.node) > 0" class="text-xs text-ink-muted">
                            +{{ extraTags(row.node) }}
                        </span>
                    </div>
                </td>

                <td class="hidden py-1 pr-4 text-body text-ink-muted md:table-cell">
                    {{ cells(row.node).documents }}
                </td>

                <td class="hidden py-1 pr-4 text-body text-ink-muted md:table-cell">
                    {{ cells(row.node).tasks }}
                </td>

                <td class="hidden py-1 pr-4 text-body text-ink-muted md:table-cell">
                    <time :datetime="cells(row.node).updated_at">
                        {{ eventDate(cells(row.node).updated_at).text }}
                    </time>
                </td>
            </tr>
        </tbody>
    </table>
</template>
