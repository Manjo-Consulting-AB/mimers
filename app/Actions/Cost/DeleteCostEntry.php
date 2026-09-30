<?php

namespace App\Actions\Cost;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en kostnadsrad och loggar `cost_entry.deleted` — på ett ställe,
 * så webbens och `/api`:s radering inte kan glida isär (issue 168,
 * [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Raderingen är MJUK (SoftDeletes): `deleted_at` sätts och raden ligger kvar.
 * Kostnadsraderna hamnar INTE i papperskorgen — den listar fyra typer och
 * behåller fyra. Raderas itemet följer raderna med till papperskorgen genom
 * SoftDeletes på itemet och återställs med det, utan att den här actionen
 * behöver veta att tabellen finns.
 *
 * Raderingen och loggraden i EN transaktion: en loggrad som skrevs utanför
 * kunde överleva ett rollback och beskriva en radering som inte hände.
 *
 * `meta` bär beloppet och valutan, aldrig beskrivningen eller leverantören —
 * de är användarens fritext.
 *
 * Behörigheten prövas av anroparen: itemets `delete`, en egen pinne — en
 * `write`-mottagare ändrar en kostnadsrad men tar inte bort den.
 */
class DeleteCostEntry
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Item $item, CostEntry $cost, User $actor): void
    {
        DB::transaction(function () use ($cost, $item, $actor): void {
            $meta = ['amount' => $cost->amount, 'currency' => $cost->currency];

            $cost->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_COST_ENTRY_DELETED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                subjectType: 'cost_entry',
                subjectUlid: $cost->ulid,
                meta: $meta,
            );
        });
    }
}
