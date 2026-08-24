<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use Illuminate\Http\Response;

/**
 * API:ets "begär en magic link" — se issue 5. Svarar identiskt oavsett om
 * adressen finns, se issue #18 § Beslut som redan är fattade punkt 6 —
 * samma resonemang som webbens motsvarighet,
 * App\Http\Controllers\Auth\MagicLinkRequestController.
 *
 * 202 utan kropp, samma mönster som
 * App\Http\Controllers\Auth\EmailVerificationNotificationController:s
 * API-gren för "mejl skickat, ingen mer information".
 */
class MagicLinkRequestController extends Controller
{
    public function store(RequestMagicLinkRequest $request): Response
    {
        $request->issue();

        return response()->noContent(202);
    }
}
