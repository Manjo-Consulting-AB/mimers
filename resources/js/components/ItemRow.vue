<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemIcon from './ItemIcon.vue';
import UiIcon from './UiIcon.vue';

/*
 * En rad i en itemlista, se issue 285 · [[M32 Formspråket]] och
 * docs/Design/container.jpeg.
 *
 * **Raden ritades två gånger innan den blev en komponent.** Itemlistan bar
 * markupen i `pages/Containers/Items/Index.vue`, och översiktens itempanel
 * ritade en egen, tunnare rad. Nu ritar båda den här filen: miniatyr eller
 * `item`-ikonen i en neutral ruta, namnet, beskrivningen och
 * `chevron-right`. Två formuleringar av samma rad hade glidit isär precis
 * som två formuleringar av samma fråga gör ([[ADR-0024 Tunna controllers och
 * actions]]).
 *
 * **Statusen står inte i raden** (issue 212 · [[ADR-0050 Desktopdesignen]]).
 * Den hör till kartan, som ritar den per nod (`ContainerMapNode.vue`), och
 * raden bär därför varken `statuses` eller ett eget ord om tillstånd.
 *
 * **Rutan visar EN sak** (issue 310 · [[ADR-0062 Kartans luft, listans ruta
 * och den löpande sökningen]] § 6). Raden ritar en enda ruta på 48 px
 * (`h-12 w-12`) och väljer det första som gäller:
 *
 *   1. en VALD omslagsbild med miniatyr,
 *   2. itemets IKON,
 *   3. den AUTOMATISKA omslagsbilden med miniatyr, alltså den äldsta bilden,
 *   4. den grå rutan med den neutrala `item`-ikonen.
 *
 * Ett val slår en gissning: den automatiska bilden är systemets gissning,
 * ikonen och den valda bilden är användarens val. Ikonen står därför före den
 * automatiska bilden — ett foto som råkar vara äldst ska inte skymma en vald
 * ikon — men en vald bild vinner över ikonen.
 *
 * **Omslaget är itemets EGET** (issue 183 · [[ADR-0050 Desktopdesignen]]):
 * `cover` är `{ulid, hasThumb, chosen}` eller null, ur
 * App\Actions\Item\ResolveItemCover::thumbnails(). `hasThumb` säger att
 * `thumb`-derivatet finns, och först då ritas en `<img>` mot
 * `/files/{ulid}?variant=thumb` ([[ADR-0019 Filleverans]]); annars hade
 * varianten svarat 404 och bilden varit trasig (issue 61b § Beslut 1).
 * `chosen` skiljer den bild användaren valt från den automatiska. En bild utan
 * `thumb` räknas som ingen bild i båda fallen. Containerns eget omslag läses
 * aldrig här — ett item utan bild ärver inte containerns foto.
 *
 * **Ikonen är `ItemIcon` med `tile`, i storleken 24** (issue 303 ·
 * [[ADR-0060 Itemets ikon]] § 6 och 9): `item.icon` och `item.icon_color` som
 * `ItemResource` bär sedan issue 301.
 *
 * **Den grå rutan är `neutral`** ([[ADR-0057 Ikoner och typsnitt]] § 3): ett
 * item utan bild och ikon har ingen domän som ger en annan ton, och `neutral`
 * är regeln för allt annat. Ikonen är `item` — samma roll som containerns
 * itemyta använder — och `UiIcon` äger storlek, linjebredd, färg och
 * `aria-hidden` (issue 282).
 *
 * **Hela raden är länken** (issue 212). Träffytan är `min-h-11` — 44 px ur
 * issue 68a § Beslut 3 — och bor på länken, som GenomgangTest mäter.
 *
 * Raden är en `<li>` och hör i en `<ul>`, precis som `UiListRow`.
 */
const props = defineProps({
    /* Ett item ur `ItemResource`: `{ ulid, name, description, … }`. */
    item: { type: Object, required: true },
    /*
     * Itemets omslag, `{ulid, hasThumb, chosen}` eller null — uppslaget är
     * detsamma för alla rader (App\Actions\Item\ResolveItemCover::thumbnails()).
     */
    cover: { type: Object, default: null },
    /*
     * Containerns ULID: adressen till itemet behöver båda, och containern är
     * given på båda ytorna som ritar raden.
     */
    containerUlid: { type: String, required: true },
});

const itemUrl = computed(
    () => `/containers/${props.containerUlid}/items/${props.item.ulid}`,
);
</script>

<template>
    <li>
        <Link
            :href="itemUrl"
            class="flex min-h-11 items-center gap-4 py-3 outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <!--
                DEN valda bilden går först: ett uttryckligt val slår ikonen.
            -->
            <img
                v-if="props.cover?.hasThumb && props.cover.chosen"
                :src="`/files/${props.cover.ulid}?variant=thumb`"
                alt=""
                class="h-12 w-12 shrink-0 rounded border border-slate-200 object-cover"
            >

            <!--
                Ikonen står före den AUTOMATISKA bilden — en vald ikon skyms
                inte av ett foto som råkar vara äldst (ADR-0062 § 6).
            -->
            <ItemIcon
                v-else-if="props.item.icon"
                :name="props.item.icon"
                :color="props.item.icon_color"
                :size="24"
                tile
                class="h-12 w-12 shrink-0"
            />

            <!--
                Den automatiska bilden, när ikonen saknas. Utan derivat ritas
                den neutrala rutan i stället för en `<img>` mot en variant som
                inte finns — samma val som RecentImagesPanel gör (issue 61b
                § Beslut 1).
            -->
            <img
                v-else-if="props.cover?.hasThumb"
                :src="`/files/${props.cover.ulid}?variant=thumb`"
                alt=""
                class="h-12 w-12 shrink-0 rounded border border-slate-200 object-cover"
            />

            <UiIcon
                v-else
                name="item"
                :size="24"
                tile="neutral"
                class="h-12 w-12 shrink-0"
            />

            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-ink">{{ props.item.name }}</span>
                <span v-if="props.item.description" class="block truncate text-sm text-ink-muted">{{ props.item.description }}</span>
            </span>

            <UiIcon name="chevron-right" :size="16" class="shrink-0 text-ink-subtle" />
        </Link>
    </li>
</template>
