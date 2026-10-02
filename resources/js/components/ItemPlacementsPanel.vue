<script setup>
import { Link } from '@inertiajs/vue3';
import UiBadge from './UiBadge.vue';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Itemets förekomster i strukturen, högerkolumnen i trepanelslayouten — se
 * resources/js/pages/Containers/Items/Show.vue, issue 95 och 633 ·
 * [[ADR-0041 Itemets vy]] § Beslut, [[ADR-0050 Desktopdesignen]] och
 * `docs/Design/struktur - item.jpeg`, panelen under kartan.
 *
 * **Panelen är itemets vägar upp och ingenting annat.** Ett item som hänger
 * under två föräldrar har två platser i strukturen, och `paths` är dem: varje
 * väg som sina led `{ulid, name}`, i serverns ordning, med exakt en märkt
 * `current` (App\Actions\Item\ResolveItemPaths). Panelen vandrar inte i grafen,
 * sorterar inte om listan och räknar ingenting själv — den ritar proppen den
 * får, som ItemMapPanel och ItemStructurePanel.
 *
 * **Raden följer mockupen:** vägen som en brödsmula med › mellan leden, och
 * radens ÅTGÄRD till höger om den. Den aktuella raden bär ordet *Current* som
 * SYNLIG text bredvid `aria-current` — markeringen får aldrig vara en färg
 * allena — och de andra raderna en länk *Go to* mot `pathHref(occurrence.nodes)`.
 * Länken är radens enda klickbara yta och bär därför träffytan (issue 68a
 * § Beslut 3). Orden kommer ur `lang/en/ui.php` (`item.show.placements`,
 * `item.show.placement_current` och `item.show.placement_go`), och ordet är
 * *placement* och inte *occurrence* — se nycklarnas kommentar där.
 *
 * **Panelen ställer ingen egen fråga.** `pathHref` är vyns egen funktion
 * (Show.vue), skickad som propp: samma uppslag som brödsmulan gör, så raden och
 * brödsmulan inte kan peka på olika förekomster. En panel som byggde sin egen
 * adress vore den andra regeln [[ADR-0041 Itemets vy]] byggde en gemensam rot
 * för att slippa.
 *
 * **Villkoret bor här.** Panelen ritas bara när itemet har MER än en plats: ett
 * item på en plats har ingenting att visa — raden hade upprepat brödsmulan
 * ordagrant och tillagt en rad utan innehåll. Anroparen ritar panelen två
 * gånger (högerkolumnen över `md:` och mittenkolumnen under) och behöver därför
 * inte själv komma ihåg villkoret på två ställen.
 *
 * **Ramen är `UiCard`** (issue 99), som varje annan panel på ytan, med antalet
 * som `UiBadge` bredvid rubriken. `aria-labelledby` pekar på rubrikens `<span>`
 * och inte på kortets `<h2>`: badge-talet står utanför spannet och läses därför
 * inte in i listans namn.
 *
 * **Panelen vet inte vilken skärm den står på.** Det är `Show.vue` som väljer
 * var — samma yta i högerkolumnen över `md:` och i mittenkolumnen under, precis
 * som kartan (ItemMapPanel.vue).
 */
defineProps({
    /*
     * Itemets förekomster i strukturen, ur App\Actions\Item\ResolveItemPaths
     * (issue 95): alla vägar från en rot ned till itemet, varje väg som sina
     * led `{ulid, name}`, i serverns ordning — och exakt en av dem märkt
     * `current`. Tom för ett item utan väg; då ritas ingenting.
     */
    paths: { type: Array, required: true },
    /*
     * Adressen till en förekomst — vyns `pathHref` (Show.vue): ledets SISTA
     * item med hela vägen i `?path=`. Skickad som propp och inte byggd här, så
     * att raden och brödsmulan pekar på samma förekomst.
     */
    pathHref: { type: Function, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <UiCard v-if="paths.length > 1">
        <template #heading>
            <span id="item-placements-heading">{{ t('item.show.placements') }}</span>

            <UiBadge class="ml-2">{{ paths.length }}</UiBadge>
        </template>

        <ul aria-labelledby="item-placements-heading" class="space-y-1 text-sm">
            <li
                v-for="(occurrence, index) in paths"
                :key="index"
                :aria-current="occurrence.current ? 'true' : null"
                class="flex min-h-11 flex-wrap items-center gap-2"
            >
                <span class="flex flex-wrap items-center gap-1">
                    <template v-for="(node, step) in occurrence.nodes" :key="`${node.ulid}-${step}`">
                        <span>{{ node.name }}</span>
                        <span v-if="step < occurrence.nodes.length - 1" aria-hidden="true">›</span>
                    </template>
                </span>

                <UiBadge v-if="occurrence.current">{{ t('item.show.placement_current') }}</UiBadge>

                <Link
                    v-else
                    :href="pathHref(occurrence.nodes)"
                    class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                >
                    {{ t('item.show.placement_go') }}
                </Link>
            </li>
        </ul>
    </UiCard>
</template>
