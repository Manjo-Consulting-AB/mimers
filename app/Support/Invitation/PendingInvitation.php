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
 * **Metoderna svarar på samma fråga från varsitt håll.** `assert()` prövar en
 * rad som redan är uppslagen: tokenvägen hittar den på `token_hash`, och
 * svaret på en rad som inte är användarens är `invitation.email_mismatch`
 * (403) — den som bär ett giltigt token har bevisat att hon har mejlet, och
 * fel adress är då ett besked och inte en hemlighet. `forUser()` slår i
 * stället upp användarens EGNA väntande inbjudningar, och `findForUser()` är
 * samma uppslag på en ULID; den vägen har inget token alls, och en rad som
 * inte pekar på henne är därför OSYNLIG (404, se
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
     * token ligger i sessionen, klockans rader och siffra, och uppslaget
     * accept- och avvisa-vägarna på `ulid` gör (se `findForUser()`).
     *
     * Frågan går på det befintliga indexet `(email, status)`: `email` är
     * jämförelsen, `status` är `pending`, och `expires_at` silas på samma rad.
     *
     * **Värdet lowercasas, kolumnen lämnas orörd**, samma form som
     * App\Http\Controllers\OwnershipTransferController::recipientQuery():
     * `invitation.email` ligger på `utf8mb4_unicode_ci` och är redan
     * skiftlägesokänsligt, så det räcker att `mb_strtolower()`a det jämförda
     * värdet. Att i stället lägga `LOWER()` runt den INDEXERADE kolumnen —
     * ofarligt i `assert()`, som jämför två strängar i PHP på en redan
     * uppslagen rad — hindrar MariaDB från att använda `(email, status)` och
     * tvingar fram en tabellscan, och den här frågan ställs på varje
     * sidladdning (HandleInertiaRequests::pendingInvitationCount()).
     * Jämförelsen står på två ställen med flit — SQL:en avgör vad som är
     * SYNLIGT, `assert()` vad som är TILLÅTET — och den som ändrar den ena
     * ska ändra den andra.
     *
     * **`whereHas('container')`:** containern kan vara mjukraderad sedan
     * inbjudan skickades, och en inbjudan till en container i papperskorgen
     * ska varken synas eller kunna besvaras — samma villkor som
     * App\Http\Controllers\OwnershipTransferController::inboxQuery() ställer.
     * Utan det hade `$invitation->container` varit `null` och listan fallit på
     * `->name` — en 500:a i stället för en rad färre.
     *
     * **Verifieringsgrinden bor här.** En overifierad adress är inte bevisat
     * användarens, och utan token finns inget annat bevis ([[ADR-0003
     * Åtkomstmodell]], [[ADR-0011 Autentisering]]). Därför ger frågan inga
     * rader när `hasVerifiedEmail()` är falsk — den returnerar en fråga som
     * inte kan matcha någon rad, så en tom mängd är det enda svar den kan ge.
     * Anroparens egen kontroll — InvitationResponseController::waiting()
     * för tillståndet `unverified`, App\Http\Middleware\HandleInertiaRequests::
     * verifiedUser() för att spara frågan — är nu en genväg och inte själva
     * grinden: samma svar hade kommit ändå. Samma form som
     * App\Http\Controllers\OwnershipTransferController::recipientQuery(), där
     * verifieringen avgör om adressgrenen alls läggs till.
     *
     * @param  User  $user  Den inloggade mottagaren.
     * @return Builder<Invitation>
     */
    public function forUser(User $user): Builder
    {
        if (! $user->hasVerifiedEmail()) {
            return Invitation::query()->whereRaw('1 = 0');
        }

        return Invitation::query()
            ->outstanding()
            ->whereHas('container')
            ->where('email', mb_strtolower($user->email));
    }

    /**
     * Användarens väntande inbjudan med den här ULID:n, eller `404`.
     *
     * Uppslaget går genom `forUser()` och ärver därmed verifieringsgrinden:
     * en overifierad användare får inga rader och därmed samma svar som en rad
     * som inte är hennes — 404, aldrig 403. Det är samma resonemang som
     * `forUser()` vilar på: den som inte kan se raden i listan ska inte heller
     * kunna svara på den, och ett gissat `ulid` ska inte avslöja att den finns.
     *
     * Kontrollerna i `assert()` prövas fortfarande efteråt av anroparen, precis
     * som på tokenvägen: den här metoden avgör vad som är SYNLIGT, `assert()`
     * vad som är TILLÅTET.
     *
     * @throws ApiException 404 `resource.not_found`
     */
    public function findForUser(User $user, string $ulid): Invitation
    {
        $invitation = $this->forUser($user)->where('ulid', $ulid)->first();

        if (! $invitation instanceof Invitation) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        return $invitation;
    }
}
