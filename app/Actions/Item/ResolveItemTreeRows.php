<?php

namespace App\Actions\Item;

use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Support\Item\ItemTree;
use App\Support\Item\ItemTreeNode;
use Illuminate\Support\Facades\DB;

/**
 * Kolumnerna till trädtabellen — item-ULID → `{category, tags, documents,
 * tasks, updated_at}` för exakt de items som står i trädet, se
 * [[ADR-0058 Trädet som tabell]] § 1, 3 och 4 och issue 292.
 *
 * **Räkningen görs på trädet och aldrig på containern.** Trädet kommer ur
 * App\Actions\Item\ResolveItemTree och är redan omfångsfiltrerat
 * ([[ADR-0028 Åtkomst på itemnivå]]), så upplösningen kan aldrig räkna ett
 * item användaren inte når. Den bredvidliggande klassen rörs inte: struktur-
 * panelen och föräldraväljaren anropar den utan att betala för de här
 * kolumnerna.
 *
 * **Ett item med två föräldrar får EN rad.** Noderna är förekomster och inte
 * identiteter, men siffrorna hör till itemet: kartan nycklas därför på ULID,
 * och samma item på två ställen visar samma tal på båda (ADR-0058 § 3).
 *
 * **Bilagorna och uppgifterna är itemets egna**, aldrig underträdets
 * (ADR-0058 § 1): en summa som räknar både föräldern och barnen räknar samma
 * dokument på flera rader. En mjukraderad bilaga faller bort genom
 * SoftDeletes' globala scope, och ett mjukraderat schema med sina förekomster
 * likaså — `deleted_at` prövas uttryckligen i joinen nedan.
 *
 * **Frågekostnaden är konstant**: itemen, deras kategorier och taggar, en
 * grupperad räkning för bilagorna och en för förekomsterna — fem frågor
 * oavsett hur många items trädet bär. Räknarna sker i databasen och aldrig
 * som en vandring per rad, samma skäl som i ResolveItemTree.
 */
class ResolveItemTreeRows
{
    /**
     * @return array<string, array{
     *     category: string|null,
     *     tags: list<array{ulid: string, name: string, color: string|null}>,
     *     documents: int,
     *     tasks: int,
     *     updated_at: string,
     * }>
     */
    public function handle(Container $container, ItemTree $tree): array
    {
        $ulids = $this->treeUlids($tree->roots());

        if ($ulids === []) {
            return [];
        }

        // Bara ULID:na ur trädet prövas, och de är redan inom omfånget:
        // containervillkoret är defensivt, som kanterna i ResolveItemTree.
        $items = Item::query()
            ->where('container_id', $container->id)
            ->whereIn('ulid', $ulids)
            ->with(['category', 'tags'])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'ulid', 'name', 'category_id', 'updated_at']);

        if ($items->isEmpty()) {
            return [];
        }

        $itemIds = array_map('intval', $items->modelKeys());

        $documents = $this->documentsByItem($itemIds);
        $tasks = $this->tasksByItem($itemIds);

        $rows = [];

        foreach ($items as $item) {
            $rows[$item->ulid] = [
                'category' => $item->category?->name,
                'tags' => $this->tags($item),
                'documents' => (int) ($documents[$item->id] ?? 0),
                'tasks' => (int) ($tasks[$item->id] ?? 0),
                'updated_at' => $item->updated_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Itemets egna levande bilagor, item_id → antal — EN fråga.
     *
     * SoftDeletes' globala scope gäller på modellen, så en mjukraderad bilaga
     * räknas inte medan raden ligger kvar för papperskorgen.
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    private function documentsByItem(array $itemIds): array
    {
        return Attachment::query()
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->selectRaw('item_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'item_id')
            ->map(fn ($antal): int => (int) $antal)
            ->all();
    }

    /**
     * Aktiva förekomster på itemets egna levande scheman, item_id → antal —
     * EN fråga.
     *
     * Frågan är en rå join och inte `ScheduleOccurrence::active()`, av samma
     * skäl som kanterna i ResolveItemTree hämtas med `DB::table`: raden är en
     * projektion över två tabeller, och `schedule.deleted_at` måste prövas
     * uttryckligen. `schedule_occurrence` har ingen `deleted_at` — raderingen
     * av ett schema är mjuk, och förekomsterna följer med (issue 22 § Beslut
     * 2).
     *
     * `is_active` prövas INTE: [[ADR-0058 Trädet som tabell]] § 1 räknar de
     * aktiva förekomsterna, och ett pausat schema behåller sin öppna förekomst
     * (22a § Beslut 3).
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    private function tasksByItem(array $itemIds): array
    {
        return DB::table('schedule_occurrence')
            ->join('schedule', 'schedule.id', '=', 'schedule_occurrence.schedule_id')
            ->whereIn('schedule.item_id', $itemIds)
            ->whereNull('schedule.deleted_at')
            ->whereIn('schedule_occurrence.status', ScheduleOccurrence::ACTIVE_STATUSES)
            ->groupBy('schedule.item_id')
            ->selectRaw('schedule.item_id as item_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'item_id')
            ->map(fn ($antal): int => (int) $antal)
            ->all();
    }

    /**
     * Itemets taggar som `{ulid, name, color}`, sorterade på namnet.
     *
     * Formen är TagResources tre fält och ingenting mer — trädtabellen ritar
     * högst två taggar och `+N` (ADR-0058 § 1), inte tidsstämplarna.
     * Sorteringen sker här och inte i vyn, samma regel som itemlistan
     * (issue 57a § Beslut 8).
     *
     * @return list<array{ulid: string, name: string, color: string|null}>
     */
    private function tags(Item $item): array
    {
        return $item->tags
            ->sortBy('name')
            ->map(fn (Tag $tag): array => [
                'ulid' => $tag->ulid,
                'name' => $tag->name,
                'color' => $tag->color,
            ])
            ->values()
            ->all();
    }

    /**
     * ULID:na i trädet, utan dubbletter och i ritad ordning — samma vandring
     * som structureNodes() i ItemController gör, men bara nycklarna.
     *
     * @param  list<ItemTreeNode>  $nodes
     * @return list<string>
     */
    private function treeUlids(array $nodes): array
    {
        $ulids = [];

        $this->collectUlids($nodes, $ulids);

        return array_keys($ulids);
    }

    /**
     * @param  list<ItemTreeNode>  $nodes
     * @param  array<string, true>  $ulids
     */
    private function collectUlids(array $nodes, array &$ulids): void
    {
        foreach ($nodes as $node) {
            $ulids[$node->ulid()] = true;

            $this->collectUlids($node->children(), $ulids);
        }
    }
}
