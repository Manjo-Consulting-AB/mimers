<?php

namespace App\Rules;

use App\Models\Voucher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Godkänner ett fältvärde när det är en kod som går att lösa in — se
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § 8.
 *
 * Regeln prövar inte om koden SLÄPPER IN en registrering; det är
 * `grants_registration`, läget `konton.registration` och
 * App\Actions\Auth\AdmitRegistration. Den svarar på en enda fråga: finns
 * koden och går den att lösa in?
 *
 * Klassnamnet är felkoden: App\Support\Api\ValidationErrorMapper::ruleCode()
 * gör `RedeemableVoucher` till `redeemable_voucher`, så fältet får
 * `validation.redeemable_voucher` i API-höljet utan en enda rad
 * mappningstabell. Döp inte om klassen utan att veta det.
 *
 * Meddelandet är detsamma för alla fall — okänd, återkallad, utgången och
 * förbrukad kod — så svaret inte skiljer dem åt (ADR-0055 § 8). Nyckeln
 * ligger i `lang/en/validation.php`, som fyller på Laravels egen fil i
 * stället för att ersätta den.
 */
class RedeemableVoucher implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Voucher::findByCode($value)?->isRedeemable() !== true) {
            $fail('validation.redeemable_voucher')->translate();
        }
    }
}
