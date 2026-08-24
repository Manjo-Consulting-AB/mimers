<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeMagicLinkRequest;
use App\Support\Auth\MagicLinkExpiredException;
use App\Support\Auth\MagicLinkInvalidException;
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
 */
class MagicLinkLoginController extends Controller
{
    public function store(ConsumeMagicLinkRequest $request): JsonResponse
    {
        try {
            $user = $request->consume();
        } catch (MagicLinkInvalidException) {
            throw ApiException::make('auth.magic_link_invalid');
        } catch (MagicLinkExpiredException) {
            throw ApiException::make('auth.magic_link_expired');
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token]);
    }
}
