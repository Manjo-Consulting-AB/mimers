<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Security\RecordSecurityEvent;
use App\Http\Controllers\Controller;
use App\Models\SecurityLog;
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
    public function store(Request $request, RecordSecurityEvent $recordSecurityEvent): RedirectResponse
    {
        try {
            $codes = RecoveryCodeBroker::generate($request->user());
        } catch (TotpNotConfirmedException) {
            abort(422);
        }

        // Issue 113: nya återställningskoder. Kodsatsen finns inte i raden —
        // den finns i svaret och i hashad form i databasen, och en logg som
        // bar den vore en tredje kopia av något som bara får finnas två
        // ställen. Att koden skrivs ut är värt att veta: en kapad session gör
        // det för att låsa ute den riktiga ägaren.
        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_RECOVERY_CODES,
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return back()->with('recovery_codes', $codes);
    }
}
