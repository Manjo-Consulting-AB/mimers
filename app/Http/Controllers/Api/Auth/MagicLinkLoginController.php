<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeMagicLinkRequest;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Auth\MagicLinkExpiredException;
use App\Support\Auth\MagicLinkInvalidException;
use App\Support\Auth\TotpInvalidException;
use App\Support\Auth\TotpRequiredException;
use App\Support\Auth\TwoFactorChallenge;
use Illuminate\Http\JsonResponse;

/**
 * API:ets "lös in en magic link" — utfärdar en personal access token, se
 * issue #18 § Att se upp med och App\Http\Controllers\Api\Auth\AuthenticatedTokenController
 * (samma mönster för lösenord). Tänkt för en klient som redan har både
 * e-postadressen och token ur länken (t.ex. extraherat ur en deep link) och
 * vill växla in dem mot en bearer-token direkt, i stället för att följa
 * länken i en webbläsare — webbens motsvarighet
 * (App\Http\Controllers\Auth\MagicLinkLoginController) är den rutt själva
 * mejllänken pekar mot.
 *
 * Felkoderna är de som är fastställda i issue #18 § Beslut som redan är
 * fattade punkt 5: `auth.magic_link_invalid` och `auth.magic_link_expired`,
 * i stället för det generella `validation.failed` — motsvarar hur
 * AuthenticatedTokenController översätter en fångad ValidationException
 * till `auth.invalid_credentials`.
 *
 * **Issue 80 · "En magic link går förbi bekräftad tvåfaktor": koden skickas
 * med, och token brinner inte i onödan** (§ Beslut 3). Ett konto med
 * bekräftad tvåfaktor (App\Support\Auth\TwoFactorChallenge) får ingen token
 * utan en giltig engångskod — eller en oförbrukad återställningskod — precis
 * som i lösenordsinloggningen.
 *
 * **Ordningen är `resolve()` → kod → `consume()`**, och den är avsiktlig:
 *
 * - Hade token förbrukats först hade ett anrop utan `code` bränt länken, och
 *   klienten hade tvingats begära en ny i stället för att skicka om samma
 *   token med koden.
 * - Hade kontot slagits upp på e-postadressen i stället för på token hade
 *   svaret avslöjat för vem som helst som känner till en adress om kontot
 *   har tvåfaktor påslagen — utan att inneha länken. `resolve()` prövar
 *   tokenets alla villkor utan att skriva, så en fråga om tvåfaktorn ställs
 *   bara till den som redan har en giltig länk.
 *
 * `consume()` sist är fortfarande den som gör token engångs: den villkorade
 * UPDATE:n i App\Support\Auth\MagicLinkBroker är hela skyddet mot två
 * samtidiga inlösen, och `resolve()` ersätter den inte.
 *
 * Svaren är `auth.totp_required` (koden saknas) respektive
 * `auth.totp_invalid` (fel kod, förbrukad tidslucka, eller en fel/förbrukad
 * återställningskod) — samma koder och samma form som
 * AuthenticatedTokenController använder, se issue 80 § Beslut 5 och
 * AGENTS.md § Felformat i API:et.
 */
class MagicLinkLoginController extends Controller
{
    public function store(ConsumeMagicLinkRequest $request): JsonResponse
    {
        // Steg ett: pröva token utan att förbruka det — se klassens docblock.
        try {
            $user = $request->resolve();
        } catch (MagicLinkInvalidException) {
            throw ApiException::make('auth.magic_link_invalid');
        } catch (MagicLinkExpiredException) {
            throw ApiException::make('auth.magic_link_expired');
        }

        if (TwoFactorChallenge::isRequired($user)) {
            try {
                TwoFactorChallenge::verify($user, $request->code());
            } catch (TotpRequiredException) {
                throw ApiException::make('auth.totp_required');
            } catch (TotpInvalidException) {
                throw ApiException::make('auth.totp_invalid');
            }
        }

        // Steg två: förbruka token. Kastar bara om ett samtidigt anrop hann
        // före mellan resolve() och hit, eller om token hann gå ut.
        try {
            $user = $request->consume();
        } catch (MagicLinkInvalidException) {
            throw ApiException::make('auth.magic_link_invalid');
        } catch (MagicLinkExpiredException) {
            throw ApiException::make('auth.magic_link_expired');
        }

        // Som vid en lyckad lösenordsinloggning: användarens egna lyckade
        // inloggningar ska inte äta av den budget som finns för att stoppa
        // gissningsförsök, se App\Support\Auth\LoginRateLimiter::clear().
        LoginRateLimiter::clear($request, $user->email);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token]);
    }
}
