<?php

namespace App\Support\Api;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bygger `error.data.fields` för `validation.failed`-höljet på `/api`, se
 * AGENTS.md § Felformat i API:et och issue 7 § Beslut som redan är fattade
 * punkt 3. Varje fält kan ha flera fel, och varje fel får en egen kod
 * (`validation.<regel>`) plus regelparametrarna klienten behöver för att
 * formulera meddelandet — `Validator::failed()` ger regelnamn och
 * parametrar per fält och attribut; `Validator::errors()` ger bara
 * färdiga, redan hopsatta meddelandesträngar, som API:et aldrig skickar.
 */
final class ValidationErrorMapper
{
    /**
     * @return array<string, list<array{code: string, data: array<string, mixed>|\stdClass}>>
     */
    public static function fields(ValidationException $exception): array
    {
        $failedRules = $exception->validator->failed();

        $fields = [];

        foreach ($exception->errors() as $field => $messages) {
            $rules = $failedRules[$field] ?? [];

            if ($rules === []) {
                // T.ex. ValidationException::withMessages() utan en riktig
                // regeluppsättning bakom sig — Validator::failed() har då
                // inget för fältet trots att errors() har ett meddelande.
                // Förekommer inte för FormRequest-validering i den här
                // appen (se App\Http\Requests\Auth\LoginRequest::authenticate(),
                // som fångas separat i App\Http\Controllers\Api\Auth\AuthenticatedTokenController
                // innan den når hit), men täcks defensivt ändå.
                $fields[$field] = [['code' => 'validation.invalid', 'data' => ApiError::asJsonObject([])]];

                continue;
            }

            $fields[$field] = [];

            foreach ($rules as $rule => $parameters) {
                $fields[$field][] = [
                    'code' => 'validation.'.self::ruleCode($rule),
                    'data' => ApiError::asJsonObject(self::ruleData($rule, $parameters)),
                ];
            }
        }

        return $fields;
    }

    private static function ruleCode(string $rule): string
    {
        // Regelobjekt (t.ex. Illuminate\Validation\Rules\Password) dyker upp
        // som sitt fullt kvalificerade klassnamn i failed(); strängregler
        // ("min", "required") dyker upp som de är skrivna, versalt inledda.
        $name = str_contains($rule, '\\') ? class_basename($rule) : $rule;

        return Str::snake($name);
    }

    /**
     * Bara regler där parametern faktiskt är något klienten behöver för att
     * formulera meddelandet får den i `data` — se ADR-0013 § Konsekvenser.
     * Övriga regler (required, email, unique, ...) har tom `data`.
     *
     * @param  array<int, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function ruleData(string $rule, array $parameters): array
    {
        return match (self::ruleCode($rule)) {
            'min' => ['min' => (int) ($parameters[0] ?? 0)],
            'max' => ['max' => (int) ($parameters[0] ?? 0)],
            'size' => ['size' => (int) ($parameters[0] ?? 0)],
            'between' => ['min' => (int) ($parameters[0] ?? 0), 'max' => (int) ($parameters[1] ?? 0)],
            default => [],
        };
    }
}
