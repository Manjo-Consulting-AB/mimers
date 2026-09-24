<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort relationen mellan två items och loggar den — paret på ett ställe,
 * så webben och `/api` inte kan glida isär (issue 109).
 *
 * Raderingen är HÅRD (issue 14 § Beslut 10): båda itemen finns kvar, det som
 * går förlorat är kopplingen. `ItemLink` saknar `deleted_at` och `ulid`, så
 * raden beskrivs i `meta` i stället för av ett subjekt: parets båda ULID:er
 * och den lagrade relationen.
 *
 * Uppslaget är detsamma som kontrollerna gjorde var för sig: ett par har
 * högst en relation (issue 14 § Beslut 5), så frågan prövar båda hållen och
 * ger högst en rad. Returvärdet säger om något raderades — anroparen svarar
 * 404 när paret saknade en relation, och det svaret är anroparens, inte
 * actionens.
 *
 * Actionen äger transaktionen: raderingen och loggraden ska falla ihop. En
 * logg som skrevs utanför kunde överleva ett rollback och beskriva en
 * borttagen koppling som finns kvar.
 *
 * Behörigheten prövas av anroparen, precis som i App\Actions\Item\LinkItems —
 * båda ändarna kräver `update` (issue 71 § Beslut 4).
 */
class UnlinkItems
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som löser upp relationen; hen blir `user_id`
     *                       på loggraden. Behörigheten är redan prövad.
     * @return bool Sant när en rad fanns och togs bort.
     */
    public function handle(Item $item, Item $other, User $actor): bool
    {
        return DB::transaction(function () use ($item, $other, $actor): bool {
            $link = ItemLink::query()
                ->where(fn ($query) => $query->where('from_item_id', $item->id)->where('to_item_id', $other->id))
                ->orWhere(fn ($query) => $query->where('from_item_id', $other->id)->where('to_item_id', $item->id))
                ->first();

            if ($link === null) {
                return false;
            }

            $meta = [
                'from' => $link->from_item_id === $item->id ? $item->ulid : $other->ulid,
                'to' => $link->to_item_id === $item->id ? $item->ulid : $other->ulid,
                'relation' => $link->relation,
            ];

            $link->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ITEM_LINK_DELETED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                meta: $meta,
            );

            return true;
        });
    }
}
