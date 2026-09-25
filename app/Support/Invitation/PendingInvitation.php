<?php

namespace App\Support\Invitation;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * En väntande inbjudan — den ENDA formuleringen av "är den här inbjudan
 * användarens, obesvarad och inte utgången", se issue 131 och
 * [[M20 Kontot]] § 131.
 *
 * Klassen bröts ut ur App\Http\Requests\Invitation\InvitationTokenRequest
 * när webben fick en andra väg till samma svar: en inloggad, verifierad
 * mottagare når sina inbjudningar på identitet i stället för på tokenet ur
 * mejlet. **Kontrollerna är tokenvägens och formuleras inte om** — status,
 * utgång och den skiftlägesokänsliga adressjämförelsen står här och ingen
 * annanstans, så de två vägarna aldrig kan komma att pröva olika saker.
 * App\Actions\Invitation\AcceptInvitation och ::RejectInvitation anropas
 * oförändrade av båda.
 *
 * **De två metoderna svarar på samma fråga från varsitt håll.** `assert()`
 * prövar en rad som redan är uppslagen: tokenvägen hittar den på
 * `token_hash`, och svaret på en rad som inte är användarens är
 * `invitation.email_mismatch` (403) — den som bär ett giltigt token har
 * bevisat att hon har mejlet, och fel adress är då ett besked och inte en
 * hemlighet. `forUser()` slår i stället upp användarens EGNA väntande
 * inbjudningar; den vägen har inget token alls, och en rad som inte pekar på
 * henne är därför OSYNLIG (404, se
 * App\Http\Controllers\InvitationResponseController § acceptPending) —
 * samma svar som ägarbytets inkorg ger, av samma skäl: ett gissat `ulid` ska
 * inte avslöja att inbjudan finns.
 *
 * **Varför en verifierad adress räcker utan token** ([[ADR-0003
 * Åtkomstmodell]], [[ADR-0011 Autentisering]]): tokenet bevisar att
 * mottagaren når brevlådan. En verifierad adress som är lika med inbjudans
 * bevisar samma sak. Därför kräver `forUser()` verifieringen, och en
 * overifierad användare ser ingen lista.
 *
 * **Ingen `#[Scope]` på App\Models\Invitation:** `app/Models/**` ligger
 * utanför issue 131:s omfång, och den befintliga
 * `Invitation::scopeOutstanding()` är redan den enda formuleringen av
 * "pending och inte utgången" (issue 48 § Beslut 5). Den används här och
 * skrivs inte om.
 *
 * En injicerbar stödklass utan konstruktorberoenden, samma form som
 * App\Support\Frontend\ApiErrorTranslator.
 */
class PendingInvitation
{
    /**
     * Kontrollerna accept och avvisande delar, i tokenvägens ordning (issue
     * 10b § Beslut 5): containern lever, raden är `pending`, tiden har inte
     * gått ut, och adressen är användarens.
     *
     * Den sista kontrollen i kedjan — verifierad e-post — görs INTE här: den
     * gäller bara accept (§ Beslut 7) och bor i
     * App\Actions\Invitation\AcceptInvitation (§ Beslut 6), precis som förut.
     *
     * @param  ?User  $user  Den inloggade, eller `null` för en gäst — en gäst
     *                       har ingen adress att jämföra med och faller alltid
     *                       ut som `email_mismatch`, vilket är vad
     *                       InvitationResponseController::invalid() bygger
     *                       gästförhandsvisningen på.
     *
     * @throws ApiException 404 `resource.not_found` (mjukraderad container),
     *                      422 `invitation.not_pending`, 422
     *                      `invitation.expired`, 403
     *                      `invitation.email_mismatch`.
     */
    public function assert(Invitation $invitation, ?User $user): Invitation
    {
        // App\Models\Container använder SoftDeletes, så relationen ger
        // `null` för en raderad rad — kontrollen är uttrycklig, annars blir
        // det en TypeError längre fram i stället för en 404.
        $container = $invitation->container;

        if (! $container instanceof Container) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        if ($invitation->status !== 'pending') {
            throw ApiException::make('invitation.not_pending', [], 422);
        }

        // isExpired() är SANNINGEN om utgång och bor på modellen sedan 10a
        // — ingen andra tidsjämförelse skrivs här.
        if ($invitation->isExpired()) {
            throw ApiException::make('invitation.expired', [], 422);
        }

        // Gemener på BÅDA sidor: 10a normaliserar vid lagring, men
        // `User::email` är inte garanterat normaliserad — utan det kan en
        // användare aldrig acceptera sin egen inbjudan.
        if (! $user instanceof User || mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw ApiException::make('invitation.email_mismatch', [], 403);
        }

        $invitation->setRelation('container', $container);

        return $invitation;
    }

    /**
     * Användarens väntande inbjudningar — listan på `/invitations` när inget
     * token ligger i sessionen, och ingenting annat.
     *
     * Frågan går på det befintliga indexet `(email, status)`: `email` är
     * jämförelsen, `status` är `pending`, och `expires_at` silas på samma rad.
     *
     * **`LOWER()` på båda sidor**, som `assert()` ovan: 10a normaliserar
     * adressen vid lagring, men en rad behöver inte ha gått genom den vägen,
     * och en adress som skiljer sig i versaler är samma adress. Jämförelsen
     * står på två ställen med flit — SQL:en avgör vad som är SYNLIGT,
     * `assert()` vad som är TILLÅTET — och den som ändrar den ena ska ändra
     * den andra.
     *
     * **`whereHas('container')`:** containern kan vara mjukraderad sedan
     * inbjudan skickades, och en inbjudan till en container i papperskorgen
     * ska varken synas eller kunna besvaras — samma villkor som
     * App\Http\Controllers\OwnershipTransferController::inboxQuery() ställer.
     * Utan det hade raden blivit en trasig länk.
     *
     * **Anroparen har redan prövat verifieringen.** Kravet på verifierad
     * adress gäller listan ([[ADR-0003 Åtkomstmodell]]) och bor i
     * InvitationResponseController::waiting() och
     * App\Http\Middleware\HandleInertiaRequests, som båda svarar med en tom
     * lista för en overifierad användare. Frågan nedan är
     * adressjämförelsen och ingenting mer; den är ingen grind.
     *
     * @param  User  $user  Den inloggade mottagaren.
     * @return Builder<Invitation>
     */
    public function forUser(User $user): Builder
    {
        return Invitation::query()
            ->outstanding()
            ->whereHas('container')
            ->whereRaw('LOWER(invitation.email) = ?', [mb_strtolower($user->email)]);
    }
}
