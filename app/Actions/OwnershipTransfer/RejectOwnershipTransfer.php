<?php

namespace App\Actions\OwnershipTransfer;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Avböjer en överlåtelse: `status` blir `rejected` och
 * `ownership_transfer.rejected` skrivs i EN transaktion — se
 * [[Konton och åtkomst]] § ownership_transfer, Beslut 7, och
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]] i issue 111:
 * App\Http\Controllers\OwnershipTransferController::reject() och
 * App\Http\Controllers\Api\OwnershipTransferController::reject() bar var sin
 * avskrift av samma villkorade UPDATE och samma loggrad.
 *
 * **Vem som får avböja prövas av anroparen.** Att raden är mottagarens — hans
 * konto eller hans verifierade adress (Beslut 15) — avgörs av respektive ytas
 * urval, som svarar olika på en rad som inte är hans: 404 i båda, men ur olika
 * mekanismer. Den logiska prövningen bor därför kvar i kontrollerna.
 *
 * Namnet följer `status`-kolumnen, som `schedule_occurrence.skipped` gör
 * (issue 110): kolumnen heter `rejected`, och issue 116 skriver meningen.
 */
class RejectOwnershipTransfer
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Villkoret sitter i UPDATE-satsen och inte i ett `if` före ett `save()` —
     * samma engångsspärr som App\Actions\Invitation\RejectInvitation använder.
     *
     * Avslag är slutgiltigt och raden står kvar (Beslut 7). `user_id` är
     * MOTTAGAREN — den som avböjde — och `meta` är tom, för avsändarens adress
     * får aldrig skrivas (issue 40 § Beslut 10).
     *
     * @param  User  $actor  Mottagaren som avböjer; blir `user_id` på
     *                       loggraden.
     *
     * @throws ApiException 422 `transfer.not_pending` när raden inte längre är
     *                      `pending`. Ingen loggrad skrivs då — transaktionen
     *                      rullas tillbaka.
     */
    public function handle(User $actor, OwnershipTransfer $transfer): void
    {
        // Containern slås upp före transaktionen: den rörs inte av flippen, och
        // loggraden behöver både den och dess ägarkonto — samma uppslag som
        // anroparen redan gjort för sitt urval, nu ur radens egen relation.
        $container = $transfer->container;

        DB::transaction(function () use ($actor, $container, $transfer): void {
            $rejected = OwnershipTransfer::query()
                ->whereKey($transfer->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            if ($rejected !== 1) {
                throw ApiException::make('transfer.not_pending', ['transfer' => $transfer->ulid], 422);
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_OWNERSHIP_TRANSFER_REJECTED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'ownership_transfer',
                subjectUlid: $transfer->ulid,
            );
        });
    }
}
