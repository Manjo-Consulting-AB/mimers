<?php

namespace App\Rules;

use App\Support\Transaction\Currencies;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Godkänner ett fältvärde när det är en aktiv ISO 4217-kod — se
 * [[ADR-0078 Valutor ur ISO 4217]] § 1 och issue 1253.
 *
 * Listan bor i App\Support\Transaction\Currencies och ingen annanstans. Regeln prövar
 * medlemskap, aldrig formen: `alpha|size:3` hade släppt igenom `ABC`, `DEM`
 * och `XAU`, och det är precis de tre ADR:n stänger ute. Historiska koder och
 * fondkoder ingår inte i listan.
 *
 * Versaliseringen står kvar i requestens prepareForValidation() — regeln
 * versaliserar också, så en anropare som hoppar över den ändå får samma svar.
 *
 * Meddelandet ligger i `lang/en/validation.php`, samma väg som
 * App\Rules\RedeemableVoucher. **API-koden är `currency.invalid`, inte
 * `validation.iso_currency`** (ADR-0078 § 1): den mappningen står i
 * App\Support\Api\ValidationErrorMapper, för den är en del av API-kontraktet
 * och inte av regeln.
 */
class IsoCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array(mb_strtoupper($value), Currencies::codes(), true)) {
            $fail('validation.iso_currency')->translate();
        }
    }
}
