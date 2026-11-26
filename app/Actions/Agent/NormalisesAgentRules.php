<?php

namespace App\Actions\Agent;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Texten i ett agentregelfält, gemensam för SetUserAgentRules och
 * SetContainerAgentRules ([[ADR-0100 Agentregler]]): trimmad, tom sträng
 * blir NULL och mer än 2 000 tecken avvisas med `validation.max`.
 */
trait NormalisesAgentRules
{
    public const MAX_LENGTH = 2000;

    /**
     * @throws ValidationException `rules` över gränsen
     */
    private function normaliseRules(?string $rules): ?string
    {
        $rules = $rules === null ? '' : trim($rules);

        Validator::make(
            ['rules' => $rules],
            ['rules' => ['string', 'max:'.self::MAX_LENGTH]],
        )->validate();

        return $rules === '' ? null : $rules;
    }
}
