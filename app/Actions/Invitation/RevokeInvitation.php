<?php

namespace App\Actions\Invitation;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drar tillbaka en inbjudan: `status = 'revoked'`, se issue 55b § Beslut 7.
 *
 * Kroppen är oförändrad sedan issue 10a och flyttades hit från
 * App\Http\Controllers\Api\ContainerInvitationController::destroy() av samma
 * skäl som App\Actions\Invitation\CreateInvitation — webben och `/api` ska
 * inte kunna formulera "går den att dra tillbaka" olika.
 *
 * Bara en `pending`-rad går att dra tillbaka. En utgången `pending`-rad går
 * däremot bra: kolumnen är fortfarande `pending` (issue 10a § Beslut 7) och
 * att städa bort en glömd inbjudan ur listan är precis vad avsändaren vill
 * kunna göra. Allt annat är `invitation.not_pending` (422) — en accepterad
 * inbjudan går inte att ångra härifrån, det gör man genom att återkalla
 * åtkomsten.
 *
 * Raden raderas aldrig (issue 10a § Beslut 13).
 *
 * **Ingen `Gate::authorize()`**, samma linje som CreateInvitation: anroparen
 * prövar behörighet med `manageAccess()`. `$container` bars tidigare i
 * signaturen bara för att de två ingångarna skulle läsa likadant — sedan
 * issue 111 bär den också loggradens `container_id`, `account_id` och
 * `meta.item`.
 */
class RevokeInvitation
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som drar tillbaka inbjudan; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     *
     * @throws ApiException 422 `invitation.not_pending`.
     */
    public function handle(Container $container, Invitation $invitation, User $actor): void
    {
        if ($invitation->status !== 'pending') {
            throw ApiException::make('invitation.not_pending', ['invitation' => $invitation->ulid], 422);
        }

        DB::transaction(function () use ($container, $invitation, $actor): void {
            $invitation->status = 'revoked';
            $invitation->save();

            // `invitation.revoked` i samma transaktion (issue 111). En rad
            // som inte gick att dra tillbaka kastar ovanför och lämnar ingen
            // loggrad. `meta` bär nivån och itemets ULID — **aldrig adressen**
            // (issue 40 § Beslut 10) och aldrig tokenet.
            $item = $invitation->item_id === null
                ? null
                : Item::withTrashed()->whereKey($invitation->item_id)->first();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_INVITATION_REVOKED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'invitation',
                subjectUlid: $invitation->ulid,
                meta: [
                    'item' => $item?->ulid,
                    'level' => $invitation->level,
                ],
            );
        });
    }
}
