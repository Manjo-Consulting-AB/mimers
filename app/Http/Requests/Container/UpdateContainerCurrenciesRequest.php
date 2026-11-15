<?php

namespace App\Http\Requests\Container;

use App\Support\Cost\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kroppen till `PUT /containers/{container}/currencies` och
 * `PUT /api/containers/{container}/currencies` — issue 1254 ·
 * [[ADR-0078 Valutor ur ISO 4217]] § 4.
 *
 * Formen är `{ "currencies": ["EUR", "SEK"] }`. **En tom lista är ett giltigt
 * svar** och betyder alla valutor; `present` krävs därför i stället för
 * `required` — ett helt saknat fält är ett formfel, medan `[]` är svaret
 * "ingen markering".
 *
 * Varje kod prövas mot App\Support\Cost\Currencies::codes(), samma lista som
 * App\Rules\IsoCurrency läser (ADR-0078 § 1). Dubbletter nekas med `distinct`
 * i stället för att tystas bort: ett urval där samma valuta står två gånger
 * är en kropp som inte beskriver något, och databasens UNIQUE
 * `(container_id, currency)` är den sista linjen — inte den första.
 *
 * Koden normaliseras till versaler i prepareForValidation(), samma regel som
 * UpdateContainerRequest gör för containerns standardvaluta.
 *
 * Behörigheten prövas av `ContainerPolicy::update` i kontrollern, aldrig här.
 */
class UpdateContainerCurrenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $currencies = $this->input('currencies');

        if (! is_array($currencies)) {
            return;
        }

        $this->merge([
            'currencies' => array_map(
                static fn ($code): mixed => is_string($code) ? mb_strtoupper(trim($code)) : $code,
                $currencies,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currencies' => ['present', 'array'],
            'currencies.*' => ['string', 'distinct', Rule::in(Currencies::codes())],
        ];
    }
}
