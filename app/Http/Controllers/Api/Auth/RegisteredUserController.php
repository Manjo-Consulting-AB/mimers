<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\AdmitRegistration;
use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Voucher\RedeemVoucher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        private readonly CreatesUserWithPersonalAccount $creator,
        private readonly AdmitRegistration $admitRegistration,
        private readonly RedeemVoucher $redeemVoucher,
    ) {}

    /**
     * Släpps hon in, och med vilken plan? (ADR-0055 § 2, § 3 och § 8.)
     *
     * **Transaktionen öppnas här och omsluter både inlösen och
     * kontoskapandet**, som i webbens kontroll. CreatesUserWithPersonalAccount
     * öppnar en egen DB::transaction, men den blir en nästlad sparpunkt i den
     * här. Utan den yttre ramen kunde ett konto bli till utan att koden
     * förbrukades, alltså ett konto som kommit in gratis.
     *
     * Koden prövas av RegisterRequest (RedeemableVoucher) INNAN hit, så en
     * påhittad eller förbrukad kod har redan nekats. Kvar till
     * AdmitRegistration är frågan regeln med flit inte svarar på: släpper
     * koden in i det här läget, och finns en inbjudan i stället? API:et har
     * ingen session och bär inbjudningstokenet i fältet `invitation_token`
     * (ADR-0055 § 3) — webben läser sitt ur sessionen.
     *
     * Svaret är oförändrat: 201 med `token`. Felen följer ADR-0055 § 8 och
     * höljet i AGENTS.md § Felformat i API:et — 422 `validation.failed` med
     * `validation.required` eller `validation.redeemable_voucher` på
     * `voucher_code`, utan eget hölje och utan `message`.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $voucherCode = $request->input('voucher_code');
        $invitationToken = $request->input('invitation_token');

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

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token], 201);
    }
}
