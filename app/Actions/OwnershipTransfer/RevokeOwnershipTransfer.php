<?php

namespace App\Actions\OwnershipTransfer;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\OwnershipTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drar tillbaka en överlåtelse: `status` blir `revoked` och
 * `ownership_transfer.revoked` skrivs i EN transaktion — se
 * [[Konton och åtkomst]] § ownership_transfer, Beslut 4, och
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]] i issue 111:
 * App\Http\Controllers\OwnershipTransferController::destroy() och
 * App\Http\Controllers\Api\OwnershipTransferController::destroy() bar var sin
 * avskrift av samma villkorade UPDATE och samma loggrad.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — båda
 * ytorna grindar med `transfer` — och `{transfer}` binds inom containern av
 * `scopeBindings()`, så en ULID ur en annan container blir 404 innan den här
 * metoden körs.
 *
 * **Bara en `pending`-rad kan dras tillbaka** (Beslut 16). En utgången rad går
 * däremot att dra tillbaka: kolumnen står fortfarande på `pending` (utgången
 * härleds ur `created_at`), och att städa bort en glömd begäran ur listan är
 * precis vad avsändaren vill kunna göra.
 */
class RevokeOwnershipTransfer
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Villkoret sitter i UPDATE-satsen och inte i ett `if` före ett `save()` —
     * samma engångsspärr som App\Actions\Invitation\AcceptInvitation använder.
     *
     * `meta` är tom: vem som återkallade står i `user_id`, och mottagarens
     * adress får aldrig skrivas (issue 40 § Beslut 10).
     *
     * @param  User  $actor  Den som drar tillbaka. Behörigheten är redan prövad
     *                       av anroparen; hen blir `user_id` på loggraden.
     *
     * @throws ApiException 422 `transfer.not_pending` när raden inte längre är
     *                      `pending`. Ingen loggrad skrivs då — transaktionen
     *                      rullas tillbaka.
     */
    public function handle(User $actor, Container $container, OwnershipTransfer $transfer): void
    {
        DB::transaction(function () use ($actor, $container, $transfer): void {
            $revoked = OwnershipTransfer::query()
                ->whereKey($transfer->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'revoked']);

            if ($revoked !== 1) {
                throw ApiException::make('transfer.not_pending', ['transfer' => $transfer->ulid], 422);
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_OWNERSHIP_TRANSFER_REVOKED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'ownership_transfer',
                subjectUlid: $transfer->ulid,
            );
        });
    }
}
