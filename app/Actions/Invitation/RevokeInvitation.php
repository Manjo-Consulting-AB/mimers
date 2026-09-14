<?php

namespace App\Actions\Invitation;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Invitation;

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
 * prövar behörighet med `manageAccess()`. `$container` används inte i kroppen
 * — den finns i signaturen för att anroparens `scopeBindings()` redan bundit
 * inbjudan till den, och för att de två ingångarna ska läsa likadant.
 */
class RevokeInvitation
{
    /**
     * @throws ApiException 422 `invitation.not_pending`.
     */
    public function handle(Container $container, Invitation $invitation): void
    {
        if ($invitation->status !== 'pending') {
            throw ApiException::make('invitation.not_pending', ['invitation' => $invitation->ulid], 422);
        }

        $invitation->status = 'revoked';
        $invitation->save();
    }
}
