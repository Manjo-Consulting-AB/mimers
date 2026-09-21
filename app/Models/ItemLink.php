<?php

namespace App\Models;

use Database\Factories\ItemLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * En relation mellan två items, se [[Items och organisation]] § item_link och
 * issue 14. Riktningen är kanonisk (§ Beslut 4): `relation` beskriver vad
 * `from_item_id` ÄR för `to_item_id`, `parent` skrivs alltid och `child`
 * härleds vid läsning, `related` normaliseras till lägst `id` först.
 * Läsningen vänder på relationen per item via relationSeenFromItem().
 *
 * Ingen ULID (§ Beslut 1) och därför inget `#[RouteKey]` — ett par av items
 * har högst en relation, så motpartens ULID identifierar länken, och det
 * finns ingen rutt som identifierar en enskild rad. Alla kolumner är
 * medvetet UTESLUTNA ur `#[Fillable]` — de sätts explicit av
 * App\Actions\Item\LinkItems, aldrig via massildelning, samma resonemang
 * som App\Models\ContainerAccess.
 *
 * Ingen `deleted_at` (§ Beslut 10): raderingen är hård, och en mjukraderad
 * ände döljer bara länken vid läsning genom SoftDeletes globala scope på
 * itemet — raden ligger kvar och kommer tillbaka om itemet återupplivas.
 */
#[Fillable([])]
class ItemLink extends Model
{
    /** @use HasFactory<ItemLinkFactory> */
    use HasFactory;

    /**
     * Tabellen heter `item_link`, inte Eloquents standardplural `item_links`.
     */
    protected $table = 'item_link';

    /**
     * Vad MOTPARTEN är för itemet med `$itemId`, sedd från det itemet — issue
     * 14 § Beslut 8. Länken ligger kanoniskt (`relation` = `parent` eller
     * `related`, `child` skrivs aldrig), så för en `parent`-rad är svaret
     * `child` om `$itemId` är föräldrasidan och `parent` annars.
     *
     * Delas av App\Actions\Item\LinkItems (data.relation i
     * `item_link.pair_exists`) och App\Http\Controllers\Api\ItemLinkController
     * (listningen och 201-svaret) så samma härledning bara finns på ett
     * ställe — "samma sanning får aldrig finnas i två exemplar".
     */
    public function relationSeenFromItem(int $itemId): string
    {
        if ($this->relation === 'related') {
            return 'related';
        }

        return $this->from_item_id === $itemId ? 'child' : 'parent';
    }
}
