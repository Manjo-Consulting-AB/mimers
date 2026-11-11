<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\RegistersOrNotifies;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;

/**
 * API:ets registrering — personal access token, se issue 4 och
 * [[ADR-0011 Autentisering]] § "Personal access tokens för B2B-
 * integrationer och framtida mobilappar." Delar RegisterRequest,
 * CreatesUserWithPersonalAccount, AdmitRegistration och RedeemVoucher med
 * webbens motsvarighet, se App\Http\Controllers\Auth\RegisteredUserController.
 *
 * Issue 264 (#790) · den stängda registreringen på API:et. Vilka som släpps
 * in avgörs av App\Actions\Auth\AdmitRegistration — samma action som webben
 * använder (#789) — och koden löses in med App\Actions\Voucher\RedeemVoucher.
 * Se [[ADR-0055 Inbjudningskoder och stängd registrering]] § 2, § 3 och § 8.
 */
class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly RegistersOrNotifies $registers,
    ) {}

    /**
     * Registreringen ger ingen token och svaret är detsamma för en ny och en
     * redan registrerad adress: 202 `verification_pending` (issue 1057,
     * ADR-0055 § 3 och § 8). Flödet står i App\Actions\Auth\RegistersOrNotifies.
     * API:et har ingen session och bär inbjudningstokenet i `invitation_token`.
     * Felen följer höljet i AGENTS.md § Felformat i API:et. Den nya användaren
     * loggar in med POST /api/login.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $voucherCode = $request->input('voucher_code');
        $invitationToken = $request->input('invitation_token');

        $this->registers->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            is_string($voucherCode) ? $voucherCode : null,
            is_string($invitationToken) ? $invitationToken : null,
            $request->ip(),
        );

        return response()->json(['status' => 'verification_pending'], 202);
    }
}
