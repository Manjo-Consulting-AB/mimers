<?php

namespace App\Http\Controllers\Api;

use App\Actions\Invitation\AcceptInvitation;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\InvitationTokenRequest;
use App\Http\Resources\ContainerResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * Mottagarsidan av inbjudningar — acceptera och avvisa, issue 10b.
 * Avsändarytan (skapa, lista, dra tillbaka) är
 * App\Http\Controllers\Api\ContainerInvitationController, 10a.
 *
 * Två rutter, tokenet i KROPPEN och inte i sökvägen (§ Beslut 1), båda i
 * `auth:sanctum`-gruppen i routes/api.php.
 *
 * INGEN policy anropas här, och det är inte ett förbiseende: den som bär
 * ett giltigt token för sin egen adress ÄR behörig, och mottagaren har per
 * definition ingen relation till containern ännu — se § Beslut 12. Det
 * uppslaget och de kontroller de två rutterna delar bor i
 * App\Http\Requests\Invitation\InvitationTokenRequest::invitation();
 * accepts egna regler i App\Actions\Invitation\AcceptInvitation. Kvar här
 * är skalet [[ADR-0024 Tunna controllers och actions]] beskriver.
 *
 * Klassen är inte invokable trots två närbesläktade handlingar — ADR-0024
 * reserverar den formen för när det verkligen finns exakt EN handling.
 */
class InvitationResponseController extends Controller
{
    /**
     * POST /api/invitations/accept — 200 med containern.
     *
     * Klienten har just fått åtkomst till en container den inte kände
     * till; att svara med den är det enda användbara (§ Beslut 10).
     * `loadMissing('account')` innan resursen renderas, samma skäl som
     * ContainerController::show() — ContainerResource läser
     * `$this->account->ulid` och ska aldrig behöva en oplanerad
     * lazy-load-fråga.
     */
    public function accept(InvitationTokenRequest $request, AcceptInvitation $action): ContainerResource
    {
        /** @var User $user */
        $user = $request->user();

        $container = $action->handle($request->invitation(), $user);

        $container->loadMissing('account');

        return new ContainerResource($container);
    }

    /**
     * POST /api/invitations/reject — 204, ingen kropp.
     *
     * Kräver INTE verifierad e-post (§ Beslut 7): att tacka nej ger ingen
     * behörighet, och att tvinga fram en verifiering för att bli av med
     * ett mejl vore fel väg. Autentisering och adressmatchning krävs
     * fortfarande — de prövas av InvitationTokenRequest::invitation().
     *
     * Ingen Action: skrivningen är statusflippen och ingenting mer — ingen
     * access skapas, inget annat rörs — så tröskeln i ADR-0024 är inte
     * nådd. Villkoret `status = pending` sitter ändå i UPDATE-satsen och
     * inte i ett `if` före ett `save()`, samma engångsspärr som accept.
     */
    public function reject(InvitationTokenRequest $request): Response
    {
        $invitation = $request->invitation();

        $rejected = Invitation::query()
            ->whereKey($invitation->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        if ($rejected !== 1) {
            throw ApiException::make('invitation.not_pending', [], 422);
        }

        return response()->noContent();
    }
}
