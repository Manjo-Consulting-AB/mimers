<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use App\Models\Concerns\RecordsAgentDeletion;
use Database\Factories\ItemLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En relation mellan två items, se [[Items och organisation]] § item_link och
 * issue 14. Riktningen är kanonisk (§ Beslut 4): `relation` beskriver vad
 * `from_item_id` ÄR för `to_item_id`, `parent` skrivs alltid och `child`
 * härleds vid läsning, `related` normaliseras till lägst `id` först.
 * Läsningen vänder på relationen per item via relationSeenFromItem().
 *
 * `ulid` pekar ut raden för papperskorgen (ADR-0097, issue 503) men ingen
 * rutt binder mot den — ett par av items har högst en relation, så
 * motpartens ULID identifierar länken i rutterna, och därför inget
 * `#[RouteKey]`. Alla kolumner är
 * medvetet UTESLUTNA ur `#[Fillable]` — de sätts explicit av
 * App\Actions\Item\LinkItems, aldrig via massildelning, samma resonemang
 * som App\Models\ContainerAccess.
 *
 * Raderingen är MJUK ([[ADR-0097 Borttagna relationer i papperskorgen]],
 * som ändrar § Beslut 10): `UnlinkItems` sätter `deleted_at` och märker raden
 * med agenten via RecordsAgentDeletion. SoftDeletes globala scope döljer
 * raden för all Eloquent-läsning; de rena `DB::table('item_link')`-frågorna
 * filtrerar `item_link.deleted_at` själva. En mjukraderad ände på ITEMET
 * döljer fortfarande länken vid läsning, och raden kommer tillbaka om itemet
 * återupplivas.
 *
 * Det som ska bort på riktigt — en flytt (MoveItem), gallringen av ett item
 * (PurgeContent) och ett par som länkas på nytt (LinkItems, MoveItem) —
 * använder `forceDelete()`. UNIQUE `(from_item_id, to_item_id, relation)`
 * gäller även mjukraderade rader.
 */
#[Fillable([])]
class ItemLink extends Model
{
    /** @use HasFactory<ItemLinkFactory> */
    use HasFactory, HasUlid, RecordsAgentDeletion, SoftDeletes;

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
