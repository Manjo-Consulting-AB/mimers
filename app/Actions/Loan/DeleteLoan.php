<?php

namespace App\Actions\Loan;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en utlåningsrad och loggar `loan.deleted` — på ett ställe, så
 * webbens och `/api`:s radering inte kan glida isär (issue 110, [[ADR-0043
 * Tre loggar]] § Händelseloggen).
 *
 * **Det här är inte en återlämning** (issue 67a § Beslut 7): raden tas bort
 * för att den registrerades fel. Att prylen kommit tillbaka registreras med
 * App\Actions\Loan\UpdateLoan och loggas som `loan.returned`. Ett borttaget
 * lån hamnar INTE i papperskorgen — den listar fyra typer och behåller fyra
 * (issue 76 § Beslut 3).
 *
 * Raderingen och loggraden i EN transaktion: en rad som skrevs utanför kunde
 * överleva ett rollback och beskriva en radering som inte hände.
 *
 * Behörigheten prövas av anroparen: itemets `delete`, en egen pinne (issue 67a
 * § Beslut 6) — en `write`-mottagare ändrar ett lån men tar inte bort det.
 *
 * Ingen `meta`: raden bär ingen data om utlåningen som får föras vidare.
 * Låntagarens namn och e-postadress är fritext och följer aldrig med (issue 76
 * § Beslut 8).
 */
class DeleteLoan
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Loan $loan, User $actor): void
    {
        $item = $loan->item;
        $container = $item->container;

        DB::transaction(function () use ($loan, $actor, $item, $container): void {
            $loan->delete();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_LOAN_DELETED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'loan',
                subjectUlid: $loan->ulid,
            );
        });
    }
}
