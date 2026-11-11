<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegistersOrNotifies;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InvitationResponseController;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens registrering — sessionsguard med CSRF, se issue 4 och
 * [[ADR-0011 Autentisering]]. Delar RegisterRequest och
 * CreatesUserWithPersonalAccount med API:ets motsvarighet, se
 * App\Http\Controllers\Api\Auth\RegisteredUserController.
 *
 * Issue 263 (#789) · den stängda registreringen. Vilka som släpps in
 * avgörs av App\Actions\Auth\AdmitRegistration — samma action som API:et
 * använder (#790) — och koden löses in med App\Actions\Voucher\
 * RedeemVoucher. Se [[ADR-0055 Inbjudningskoder och stängd registrering]]
 * § 2, § 3 och § 8.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly RegistersOrNotifies $registers,
    ) {}

    /**
     * GET /register — registreringsformuläret, se issue 53a § Beslut 8.
     *
     * Fälten är `name`, `email`, `voucher_code` och `password`, och inget
     * femte: RegisterRequest har ingen `password_confirmation`. Ett
     * bekräftelsefält i vyn skulle se ut att göra något utan att göra
     * något — vill någon ha ett är det en ändring i den delade
     * FormRequesten, alltså en fråga i PR:en och inte ett beslut i en vy.
     *
     * Lösenordskravet visas som text ur `lang/` och räknas inte ut i
     * JavaScript: `Password::defaults()` kan ändras utan att vyn får veta
     * det, och två formuleringar av samma regel glider isär.
     *
     * `registration` (issue 263 § Beslut 2) bär läget och om sessionen
     * redan har en utestående inbjudan. Vyn formulerar sig olika i de tre
     * fallen — kod krävs, kod frivillig, ingen kod behövs — och får därför
     * veta vilket som gäller i stället för att gissa ur ett tomt fält.
     * Proppen är ett råd till vyn, inte en grind: grinden är
     * AdmitRegistration i store() nedan, som prövar samma sak en gång till.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'registration' => [
                'mode' => config('konton.registration'),
                'invitation' => $this->hasPendingInvitation($request),
            ],
        ]);
    }

    /**
     * Registreringen loggar inte in, och svaret är detsamma för en ny och en
     * redan registrerad adress (issue 1057, ADR-0055 § 3 och § 8). Flödet
     * står i App\Actions\Auth\RegistersOrNotifies. Inbjudningstokenet läses
     * ur sessionen och lämnas kvar där. Den nya användaren loggar in med sitt
     * lösenord på /login.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $voucherCode = $request->input('voucher_code');
        $invitationToken = $request->session()->get(InvitationResponseController::SESSION_KEY);

        $this->registers->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            is_string($voucherCode) ? $voucherCode : null,
            is_string($invitationToken) ? $invitationToken : null,
            $request->ip(),
        );

        return redirect()->route('login.create')->with('status', 'registration-pending');
    }

    /**
     * Bär sessionen ett utestående inbjudningstoken? Samma läsning som
     * InvitationResponseController::show() gör — samma nyckel, samma
     * tomma-sträng-kontroll — så sidan och inlämningen aldrig svarar olika
     * på samma session.
     *
     * Här prövas bara att ett token FINNS, inte att inbjudan är giltig:
     * det senare kräver ett uppslag mot databasen och görs i
     * AdmitRegistration. Vyn behöver bara veta om den ska be om en kod.
     */
    private function hasPendingInvitation(Request $request): bool
    {
        $token = $request->session()->get(InvitationResponseController::SESSION_KEY);

        return is_string($token) && $token !== '';
    }
}
