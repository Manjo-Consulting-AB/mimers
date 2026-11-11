<?php

namespace App\Actions\Auth;

use App\Actions\Voucher\RedeemVoucher;
use App\Models\User;
use App\Notifications\RegistrationAttemptNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Registreringens flöde på ett ställe, för webben och API:et. Svaret ska vara
 * identiskt för en ny och en redan registrerad adress, så registreringen
 * loggar inte in och ger ingen token (issue 1057, [[ADR-0055 Inbjudningskoder
 * och stängd registrering]] § 3 och § 8, [[ADR-0011 Autentisering]]).
 *
 * Ny adress: användare och konto skapas, en ev. kod löses in och
 * verifieringsmejlet skickas. Befintlig adress: ingenting skrivs och koden
 * löses inte in; ägaren får RegistrationAttemptNotification, högst en gång
 * per adress och timme.
 */
class RegistersOrNotifies
{
    public function __construct(
        private readonly CreatesUserWithPersonalAccount $creator,
        private readonly AdmitRegistration $admitRegistration,
        private readonly RedeemVoucher $redeemVoucher,
    ) {}

    /**
     * @throws ValidationException från AdmitRegistration.
     */
    public function handle(string $name, string $email, string $password, ?string $voucherCode, ?string $invitationToken, ?string $ip): void
    {
        $existing = null;
        $user = null;

        try {
            $user = DB::transaction(function () use ($name, $email, $password, $voucherCode, $invitationToken, $ip, &$existing) {
                $voucher = $this->admitRegistration->handle(
                    $email,
                    $voucherCode !== null && $voucherCode !== '' ? $voucherCode : null,
                    $invitationToken !== null && $invitationToken !== '' ? $invitationToken : null,
                );

                $existing = User::query()->where('email', $email)->first();

                if ($existing !== null) {
                    return null;
                }

                $created = $this->creator->handle($name, $email, $password, $ip);

                if ($voucher !== null) {
                    $this->redeemVoucher->handle($voucher, $created->accounts()->firstOrFail(), $created);
                }

                return $created;
            });
        } catch (UniqueConstraintViolationException) {
            // Två samtidiga registreringar av samma adress: förloraren behandlas
            // som "finns redan".
            $existing = User::query()->where('email', $email)->first();
            $user = null;
        }

        if ($user !== null) {
            $user->sendEmailVerificationNotification();

            return;
        }

        $this->handleExisting($existing, $email, $password);
    }

    private function handleExisting(?User $existing, string $email, string $password): void
    {
        // Utan det här svarar grenen märkbart snabbare än den som skapar ett
        // konto, och tidsskillnaden röjer det svaret döljer.
        Hash::make($password);

        if ($existing === null) {
            return;
        }

        RateLimiter::attempt(
            'register-notice:'.sha1(mb_strtolower($email)),
            1,
            fn () => $existing->notify(new RegistrationAttemptNotification),
            3600,
        );
    }
}
