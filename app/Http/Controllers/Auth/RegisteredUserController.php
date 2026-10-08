<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AdmitRegistration;
use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Voucher\RedeemVoucher;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InvitationResponseController;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        private readonly CreatesUserWithPersonalAccount $creator,
        private readonly AdmitRegistration $admitRegistration,
        private readonly RedeemVoucher $redeemVoucher,
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
     * Släpps hon in, och med vilken plan? (ADR-0055 § 2, § 3 och § 8.)
     *
     * **Transaktionen öppnas här och omsluter både inlösen och
     * kontoskapandet.** CreatesUserWithPersonalAccount öppnar en egen
     * DB::transaction, men den blir en nästlad sparpunkt i den här — och
     * dess form lämnas orörd, för API:et (#790) delar den och behöver den
     * för sin egen väg. Utan den yttre ramen kunde ett konto bli till utan
     * att koden förbrukades, alltså ett konto som kommit in gratis.
     *
     * Koden prövas av RegisterRequest (RedeemableVoucher) INNAN hit, så en
     * påhittad eller förbrukad kod har redan nekats. Kvar till
     * AdmitRegistration är frågan regeln med flit inte svarar på: släpper
     * koden in i det här läget, och finns en inbjudan i stället?
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $email = $request->string('email')->toString();
        $voucherCode = $request->input('voucher_code');
        $invitationToken = $request->session()->get(InvitationResponseController::SESSION_KEY);

        $user = DB::transaction(function () use ($request, $email, $voucherCode, $invitationToken) {
            $voucher = $this->admitRegistration->handle(
                $email,
                is_string($voucherCode) && $voucherCode !== '' ? $voucherCode : null,
                is_string($invitationToken) && $invitationToken !== '' ? $invitationToken : null,
            );

            $user = $this->creator->handle(
                $request->string('name')->toString(),
                $email,
                $request->string('password')->toString(),
                $request->ip(),
            );

            if ($voucher !== null) {
                $this->redeemVoucher->handle($voucher, $user->accounts()->firstOrFail(), $user);
            }

            return $user;
        });

        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // Issue 53a § Beslut 2: den nyregistrerade är inloggad och landar på
        // /dashboard. Verifieringsbannern där är det som påminner om mejlet
        // — registreringen kräver ingen verifiering, se routes/api.php.
        return redirect()->intended(route('dashboard'));
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
