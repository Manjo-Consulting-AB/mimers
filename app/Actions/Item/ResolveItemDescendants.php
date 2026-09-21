<?php

namespace App\Actions\Item;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * Itemet och allt som hänger under det — "itemet och alla dess ättlingar",
 * den formulering [[ADR-0040 Underträdets summor]] ger både statusen (issue
 * 92) och kostnadsnedbrytningen (issue 91). En Action i stället för direkt i
 * kontrollern, se [[ADR-0024 Tunna controllers och actions]]; förlagan är
 * App\Actions\Category\ResolveCategoryDescendants, som löser samma problem
 * för kategoriträdet.
 *
 * Ättling betyder transitivt nedåt längs `item_link`-kanter där `relation`
 * är `parent`, exakt den riktning [[ADR-0028 Åtkomst på itemnivå]] regel 3
 * redan går. En `related`-kant bär ingenting ([[ADR-0035 Relationen mellan
 * objekt]]) och filtreras bort i FRÅGAN — en vandring som följde den hade
 * dragit in ett syskon och allt som hänger under syskonet.
 *
 * Klassen är MEDVETET inte en utvidgning av App\Actions\Access\ResolveItemScope,
 * och åtkomstvandringen rörs inte. Den hämtar kanter bara i de containers
 * som faktiskt har en itemgrant och hoppar över steget helt när ingen har
 * det — riktigt för behörigheten, fel för en summering som behöver kanterna
 * varje gång. Att vidga den vore att lägga ett presentationsbehov i
 * behörighetskoden. Ingen behörighetslogik bor här: actionen svarar på vad
 * som hänger under ett item, aldrig på vem som får se det.
 *
 * Slutningen görs i PHP på EN fråga, inte som en rekursiv CTE — samma teknik
 * och samma skäl som ResolveCategoryDescendants, LinkItems och
 * ResolveItemScope: `WITH RECURSIVE` finns inte i sqlite på det sätt
 * testsviten behöver.
 *
 * FRÅGEKOSTNADEN är konstant: EN fråga för hela containerns kanter, oavsett
 * hur många startpunkter som efterfrågas och oavsett trädets djup och bredd.
 * Ingen fråga per nivå, ingen per item — statusen räknas för varje rad i
 * itemlistan, och en vandring per rad vore precis den N+1 som hela
 * åtkomstlösningen byggdes för att undvika (issue 92).
 */
class ResolveItemDescendants
{
    /**
     * Itemet och alla dess ättlingar, i den ordningen.
     *
     * Ett tunt anrop till forItems() — det finns bara en slutning, samma
     * form som ResolveItemScope::handle().
     *
     * Ett mjukraderat item räknas inte, och ingenting hänger under det: dess
     * kanter faller bort i frågan. Startpunkten prövas på modellen, vilket
     * kostar noll frågor.
     *
     * @return list<int> itemets eget id först, därefter varje ättlings
     */
    public function handle(Item $item): array
    {
        if ($item->trashed()) {
            return [];
        }

        return $this->forItems($item->container_id, [$item->id])[$item->id];
    }

    /**
     * Ättlingarna för flera startpunkter i SAMMA container, på EN fråga.
     * Den form issue 92 behöver: kanterna hämtas en gång per lista och
     * slutningen sker i minnet, i stället för en vandring per rad.
     *
     * Varje begärd startpunkt får en nyckel i svaret, även en utan ättlingar
     * — den blir sitt eget id och ingenting mer. En anropare kan därför alltid
     * indexera svaret utan att först ha prövat om itemet finns med.
     *
     * Startpunkterna förutsätts levande. Den som hämtat dem genom
     * SoftDeletes-scopet — listningen och varje route binding — har dem
     * redan filtrerade, och att pröva dem här hade kostat en fråga till för
     * ett svar anroparen redan har. Ett mjukraderat item INUTI trädet
     * däremot bryter kedjan, se loadChildrenByParent().
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<int>> startitem_id → itemet och dess ättlingar
     */
    public function forItems(int $containerId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $childrenByParent = $this->loadChildrenByParent($containerId);

        $descendants = [];

        foreach ($itemIds as $itemId) {
            $descendants[$itemId] = $this->closeOverDescendants($itemId, $childrenByParent);
        }

        return $descendants;
    }

    /**
     * Slutningen: från startpunkten nedåt längs barnkanterna, en gång per
     * item. `$seen` är både resultatet och skyddet mot en cykel — LinkItems
     * förhindrar cykler vid skrivning (`item_link.cycle`), men en migrering,
     * en import eller ett fel i kontrollen själv kan lägga raden där ändå,
     * och en vandring som snurrar för alltid är en hängd request och en död
     * kö. Samma försvar, samma skäl, som
     * ResolveItemScope::closeOverDescendants().
     *
     * Ättlingmängden är en MÄNGD: ett item som nås längs två vägar räknas en
     * gång, se [[ADR-0040 Underträdets summor]] § Rättelse 2026-09-18.
     *
     * @param  array<int, list<int>>  $childrenByParent
     * @return list<int> startpunkten först, därefter varje ättling
     */
    private function closeOverDescendants(int $itemId, array $childrenByParent): array
    {
        $ids = [$itemId];
        $seen = [$itemId => true];
        $pending = [$itemId];

        while ($pending !== []) {
            $current = array_pop($pending);

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $ids[] = $childId;
                $pending[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Containerns kanter i EN fråga, som en uppslagstabell i minnet:
     * förälder → barn. Samma form och samma skäl som
     * ResolveItemScope::loadChildrenByParent(), med tre skillnader:
     *
     * 1. Containern är alltid given — kanterna hämtas varje gång, aldrig
     *    "bara om någon har en grant". Det är hela skälet till att klassen
     *    finns vid sidan av åtkomstvandringen.
     * 2. Båda ändarna prövas mot `deleted_at`. Ett mjukraderat item räknas
     *    inte och BRYTER KEDJAN: kanten till ett raderat barn faller bort,
     *    och därmed också kanten från det — ett barnbarn under ett raderat
     *    barn faller bort med det, se [[ADR-0040 Underträdets summor]]
     *    § Konsekvenser.
     * 3. `child`-rader tolkas som sin motsats i stället för att ignoreras.
     *    Relationen lagras kanoniskt — `parent` skrivs, `child` härleds
     *    ([[ADR-0035 Relationen mellan objekt]]) — så en `child`-rad kan
     *    bara ha kommit förbi LinkItems. En sådan rad säger att `from` är
     *    BARN till `to`, och att läsa den som en förälderkant hade dragit
     *    vandringen uppåt.
     *
     * Båda ändarna begränsas dessutom till containern. En `parent`-kant har
     * alltid båda sina ändar i samma container (invarianten hålls av
     * LinkItems), så villkoret är bara defensivt — men en rad som skrivits
     * förbi invarianten ska inte kunna dra in ett item från en annan
     * container i en summa.
     *
     * @return array<int, list<int>> förälder_id → list<barn_id>
     */
    private function loadChildrenByParent(int $containerId): array
    {
        // DB::table och inte ItemLink::query(): raden är en projektion över
        // två tabeller, och SoftDeletes-scopet sitter på Item — en join
        // förbi modellen filtrerar ingenting av sig själv.
        $rows = DB::table('item_link')
            ->join('item as parent', 'parent.id', '=', 'item_link.from_item_id')
            ->join('item as child', 'child.id', '=', 'item_link.to_item_id')
            ->where('parent.container_id', $containerId)
            ->where('child.container_id', $containerId)
            ->whereNull('parent.deleted_at')
            ->whereNull('child.deleted_at')
            ->whereIn('item_link.relation', ['parent', 'child'])
            ->get(['item_link.from_item_id', 'item_link.to_item_id', 'item_link.relation']);

        $childrenByParent = [];

        foreach ($rows as $row) {
            [$parentId, $childId] = $row->relation === 'parent'
                ? [$row->from_item_id, $row->to_item_id]
                : [$row->to_item_id, $row->from_item_id];

            $childrenByParent[$parentId][] = $childId;
        }

        return $childrenByParent;
    }
}
