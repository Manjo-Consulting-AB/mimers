<?php

namespace App\Actions\Invitation;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Avböjer en inbjudan: `status = 'rejected'`, och `invitation.rejected` i
 * `audit_log` i EN transaktion — se issue 10b § Beslut 7 och
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut ur App\Http\Controllers\InvitationResponseController::reject() och
 * App\Http\Controllers\Api\InvitationResponseController::reject() i issue 111.
 * Båda kontrollernas docblock sade "Ingen Action: skrivningen är statusflippen
 * och ingenting mer" — och det stämde: tröskeln i [[ADR-0024 Tunna
 * controllers och actions]] var inte nådd. Sedan raden ska skrivas i
 * handlingens transaktion är den nådd, och två avskrifter av raden hade varit
 * två sanningar om vad ett avböjande ÄR.
 *
 * Anropas efter
 * App\Http\Requests\Invitation\InvitationTokenRequest::invitation(), som
 * redan bevisat att tokenet hör till den inloggade adressen, att containern
 * finns och att inbjudan är `pending` och inte utgången — och att adressen
 * INTE behöver vara verifierad (§ Beslut 7: att tacka nej ger ingen behörighet).
 *
 * **Engångsspärren sitter i UPDATE-satsen**, aldrig i ett `if` före ett
 * `save()` — samma villkorade skrivning som App\Actions\Invitation\
 * AcceptInvitation använder, så två samtidiga svar på samma inbjudan aldrig
 * båda kan lyckas.
 *
 * **Ingen `Gate::authorize()`.** Den som svarar på en inbjudan är mottagaren,
 * och rätten till raden bevisas av tokenet — det finns ingen container att
 * pröva en grind mot.
 */
class RejectInvitation
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den inloggade mottagaren; blir `user_id` på
     *                       loggraden.
     *
     * @throws ApiException 422 `invitation.not_pending`.
     */
    public function handle(Invitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $rejected = Invitation::query()
                ->whereKey($invitation->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            if ($rejected !== 1) {
                throw ApiException::make('invitation.not_pending', [], 422);
            }

            $container = $invitation->container;

            // `withTrashed()`: en itemsinbjudan kan ha mjukraderats mellan
            // utskicket och svaret, och raden ska då bära sitt item och inte
            // `null` — samma skäl som App\Actions\Access\RevokeContainerAccess
            // använder det. `meta` bär nivån och itemets ULID, **aldrig
            // adressen** (issue 40 § Beslut 10).
            $item = $invitation->item_id === null
                ? null
                : Item::withTrashed()->whereKey($invitation->item_id)->first();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_INVITATION_REJECTED,
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
