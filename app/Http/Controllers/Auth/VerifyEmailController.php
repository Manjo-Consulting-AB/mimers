<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Verifieringslänken skickas som mejl, se `App\Models\User` (implementerar
 * `MustVerifyEmail`, tillagt i den här issuen — se issue #17 § Att se upp
 * med) och krävs innan en användare kan ta emot delning, se
 * [[ADR-0011 Autentisering]] § Konsekvenser och [[ADR-0003 Åtkomstmodell]].
 *
 * `EmailVerificationRequest` (ramverkets standardklass) verifierar den
 * signerade URL:en (`id`/`hash`) mot den inloggade användaren och markerar
 * — via `fulfill()` — e-posten som verifierad samt avfyrar `Verified`.
 * Idempotent: `fulfill()` gör ingenting om e-posten redan är verifierad.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('welcome'));
    }
}
