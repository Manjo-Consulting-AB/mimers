<?php

namespace App\Http\Requests\Invitation;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kroppen för POST /api/invitations/accept och /api/invitations/reject:
 * `{token}` och ingenting annat, se issue 10b § Beslut 1. Tokenet ligger i
 * kroppen och inte i sökvägen av samma skäl som
 * `POST /api/login/magic-link/consume` gör det — ett token i en URL hamnar
 * i åtkomstloggar, i `Referer` och i webbläsarhistoriken.
 *
 * `invitation()` gör tokenuppslaget och de kontroller de två rutterna
 * DELAR, i ordningen i § Beslut 5. Att låta en delad FormRequest bära
 * översättningen från rå indata till ett domänobjekt är samma mönster som
 * App\Http\Requests\Auth\ConsumeMagicLinkRequest::consume(), som delas
 * mellan webbens och API:ets inlösenrutter — kontrollern förblir det tunna
 * skal [[ADR-0024 Tunna controllers och actions]] beskriver, och de två
 * svarsvägarna kan aldrig komma att pröva olika saker.
 *
 * Den sista kontrollen i § Beslut 5 — verifierad e-post — görs INTE här:
 * den gäller bara accept (§ Beslut 7) och bor i
 * App\Actions\Invitation\AcceptInvitation (§ Beslut 6).
 *
 * Klartexttokenet lämnar aldrig den här klassen: det hashas för uppslaget
 * och läggs aldrig i en `ApiException`s `data`.
 */
class InvitationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ingen policy prövas — den som bär ett giltigt token för sin egen
        // adress ÄR behörig, och mottagaren har per definition ingen
        // relation till containern ännu. Se issue 10b § Beslut 12:
        // ingen metod läggs i App\Policies\ContainerPolicy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * Slår upp inbjudan på tokenets hash och prövar de kontroller accept
     * och avvisande delar. Containern är laddad på den returnerade raden.
     *
     * Uppslaget sker ENBART på `token_hash`; varje övrig kontroll är ett
     * eget steg, aldrig ett villkor i frågan — se förlagan
     * App\Support\Auth\MagicLinkBroker § Beslut 2. Ett token utfärdat för
     * alice@… kan alltså aldrig besvaras av bob@…, och de olika
     * felutfallen går att skilja åt: `expired` skiljs från `not_pending`
     * för att klienten ska kunna säga "be om en ny" i stället för "den är
     * redan besvarad".
     *
     * @throws ApiException 404 `resource.not_found` (okänt token, eller
     *                      mjukraderad container), 422
     *                      `invitation.not_pending`, 422
     *                      `invitation.expired`, 403
     *                      `invitation.email_mismatch`.
     */
    public function invitation(): Invitation
    {
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $this->string('token')->toString()))
            ->first();

        if (! $invitation instanceof Invitation) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        // App\Models\Container använder SoftDeletes, så relationen ger
        // `null` för en raderad rad — kontrollen är uttrycklig, annars
        // blir det en TypeError längre fram i stället för en 404.
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

        $user = $this->user();

        // Gemener på BÅDA sidor: 10a normaliserar vid lagring, men
        // `User::email` är inte garanterat normaliserad — utan det kan en
        // användare aldrig acceptera sin egen inbjudan.
        if (! $user instanceof User || mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
            throw ApiException::make('invitation.email_mismatch', [], 403);
        }

        $invitation->setRelation('container', $container);

        return $invitation;
    }
}
