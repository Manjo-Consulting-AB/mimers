<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TotpCodeRequest;
use App\Support\Auth\TotpAlreadyConfirmedException;
use App\Support\Auth\TotpBroker;
use App\Support\Auth\TotpInvalidException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Webbens TOTP-aktivering och -avstängning — sessionsguarden (`auth`-
 * middleware, routes/web.php), se issue #19. Delar App\Support\Auth\TotpBroker
 * med API-motsvarigheten, App\Http\Controllers\Api\Auth\TotpController —
 * samma mönster som App\Http\Controllers\Auth\MagicLinkLoginController och
 * dess API-motsvarighet.
 *
 * "Fel kod"-fallet (TotpInvalidException) binds till fältet `code` som en
 * vanlig ValidationException, precis som
 * App\Http\Requests\Auth\LoginRequest::authenticate() binder fel
 * inloggningsuppgifter till `email` — Inertia renderar den som ett
 * vanligt formulärfel utan API-höljet (AGENTS.md § Felformat i API:et
 * gäller `/api`, inte webben). "Redan bekräftad"
 * (TotpAlreadyConfirmedException) hör inte till något fält — `store()`
 * tar ingen indata alls — och ger i stället en ren 422 utan formulärfel.
 */
class TotpController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        try {
            $uri = TotpBroker::generate($request->user());
        } catch (TotpAlreadyConfirmedException) {
            abort(422);
        }

        return back()->with('totp_uri', $uri);
    }

    public function confirm(TotpCodeRequest $request): RedirectResponse
    {
        try {
            TotpBroker::confirm($request->user(), $request->string('code')->toString());
        } catch (TotpAlreadyConfirmedException) {
            abort(422);
        } catch (TotpInvalidException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_invalid'),
            ]);
        }

        return back()->with('status', 'totp-confirmed');
    }

    public function destroy(TotpCodeRequest $request): RedirectResponse
    {
        try {
            TotpBroker::disable($request->user(), $request->string('code')->toString());
        } catch (TotpInvalidException) {
            throw ValidationException::withMessages([
                'code' => __('auth.totp_invalid'),
            ]);
        }

        return back()->with('status', 'totp-disabled');
    }
}
