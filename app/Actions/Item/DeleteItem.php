<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar ett item och loggar det — på ett ställe, så webbens och
 * `/api`:s radering inte kan glida isär (issue 109, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Raderingen är MJUK: `App\Models\Item` använder SoftDeletes, så `delete()`
 * sätter `deleted_at` och ingenting annat ([[ADR-0008 Soft delete och
 * papperskorg]]). Ingen kaskad — itemets beroenden följer itemet — och
 * ingen fysisk gallring öppnas här.
 *
 * Raderingen och loggraden i EN transaktion: en rad som skrevs utanför
 * kunde överleva ett rollback och beskriva en radering som inte hände.
 *
 * Behörigheten prövas av anroparen: `delete` på itemet, en egen pinne på
 * stegen (issue 71 § Beslut 1) — en `write`-mottagare ändrar itemet men tar
 * inte bort det.
 */
class DeleteItem
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Item $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor): void {
            $item->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ITEM_DELETED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
            );
        });
    }
}
