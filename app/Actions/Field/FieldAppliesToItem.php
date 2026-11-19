<?php

namespace App\Actions\Field;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ett fältvärde får bara skrivas i ett fält som hör till itemet: containerns
 * fält eller itemets egna, aldrig ett annat items eget fält och aldrig ett
 * fält i en annan container ([[ADR-0089 Egna fält på items]] § Riktning 9 och
 * issue 418).
 *
 * Regeln prövar mot den mängd ULID:er som
 * App\Actions\Field\ItemFieldInput har löst upp EN gång för hela requesten —
 * den frågar alltså inte databasen per element.
 *
 * **Felkoden är `field.not_applicable`, inte `validation.*`.** Koden är
 * domänens, som `field.limit_reached` och `field.type_locked`, och
 * mappningen från regelklassen bor i App\Support\Api\ValidationErrorMapper
 * — API-kontraktet, inte valideringen. Webben får samma kod som
 * meddelandenyckel.
 */
final class FieldAppliesToItem implements ValidationRule
{
    /**
     * @param  list<string>  $applicableUlids  De fält som hör till itemet,
     *                                         redan uppslagna.
     */
    public function __construct(private readonly array $applicableUlids) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, $this->applicableUlids, true)) {
            $fail('field.not_applicable');
        }
    }
}
