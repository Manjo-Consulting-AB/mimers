<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\RecoveryCodeBroker;
use App\Support\Auth\TotpNotConfirmedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Webbens (om)generering av TOTP-återställningskoder — sessionsguarden
 * (`auth`-middleware, routes/web.php), se issue 6c. Delar
 * App\Support\Auth\RecoveryCodeBroker med API-motsvarigheten,
 * App\Http\Controllers\Api\Auth\RecoveryCodeController — samma mönster som
 * App\Http\Controllers\Auth\TotpController och dess API-motsvarighet.
 *
 * "Kontot har ingen bekräftad TOTP" (TotpNotConfirmedException) hör inte
 * till något fält — `store()` tar ingen indata alls — och ger i stället en
 * ren 422 utan formulärfel, samma mönster som
 * App\Http\Controllers\Auth\TotpController::store() använder för
 * TotpAlreadyConfirmedException.
 */
class RecoveryCodeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        try {
            $codes = RecoveryCodeBroker::generate($request->user());
        } catch (TotpNotConfirmedException) {
            abort(422);
        }

        return back()->with('recovery_codes', $codes);
    }
}
