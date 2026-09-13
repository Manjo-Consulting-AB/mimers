<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
    /**
     * GET /email/verify (`verification.notice`) — sidan för den som är
     * inloggad men ännu inte har verifierat sin adress, se issue 53a
     * § Beslut 7.
     *
     * Samma text och samma knapp som bannern i layouten; båda renderar
     * resources/js/components/VerifyEmailNotice.vue, så formuleringen står
     * på ett ställe. Sidan behövs för den dag en rutt sätter
     * `verified`-middleware och ramverket skickar hit — ingen rutt gör det
     * i den här issuen (se routes/web.php).
     */
    public function create(): Response
    {
        return Inertia::render('Auth/VerifyEmail');
    }

    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        // Issue 53a § Beslut 2: den som just verifierat sin adress har
        // ärenden på /dashboard — det är där bannern försvann ifrån.
        return redirect()->intended(route('dashboard'));
    }
}
