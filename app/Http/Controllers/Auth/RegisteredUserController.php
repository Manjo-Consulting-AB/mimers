<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens registrering — sessionsguard med CSRF, se issue 4 och
 * [[ADR-0011 Autentisering]]. Delar RegisterRequest och
 * CreatesUserWithPersonalAccount med API:ets motsvarighet, se
 * App\Http\Controllers\Api\Auth\RegisteredUserController.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly CreatesUserWithPersonalAccount $creator,
    ) {}

    /**
     * GET /register — registreringsformuläret, se issue 53a § Beslut 8.
     *
     * Tre fält, och inget fjärde: RegisterRequest validerar `name`, `email`
     * och `password` och har ingen `password_confirmation`. Ett
     * bekräftelsefält i vyn skulle se ut att göra något utan att göra
     * något — vill någon ha ett är det en ändring i den delade
     * FormRequesten, alltså en fråga i PR:en och inte ett beslut i en vy.
     *
     * Lösenordskravet visas som text ur `lang/` och räknas inte ut i
     * JavaScript: `Password::defaults()` kan ändras utan att vyn får veta
     * det, och två formuleringar av samma regel glider isär.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $this->creator->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
        );

        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // Issue 53a § Beslut 2: den nyregistrerade är inloggad och landar på
        // /dashboard. Verifieringsbannern där är det som påminner om mejlet
        // — registreringen kräver ingen verifiering, se routes/api.php.
        return redirect()->intended(route('dashboard'));
    }
}
