<?php

namespace App\Http\Requests\Cost;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/containers/{container}/items/{item}/costs, se issue 45a § Beslut
 * 5 och 11. Kroppen är `{"incurred_on", "amount", "currency", "description",
 * "supplier"?}`.
 *
 * `amount` är en STRÄNG i huvudenhet och valideras bara som `present|string`
 * här — själva formen (komma eller punkt, decimalgräns, BIGINT-gräns) tolkas
 * av App\Support\Cost\MinorUnits i kontrollern och kastas som
 * `cost.amount_invalid`/`cost.amount_decimals`, inte som validation.failed
 * (§ Beslut 5). `present` i stället för `required`: en tom sträng ska nå
 * MinorUnits och bli `cost.amount_invalid` (Klart när), medan ett helt
 * saknat fält är ett formfel. En klient som skickar `amount` som JSON-tal
 * (1200.50 i stället för "1200.50") faller på `validation.string`.
 *
 * `item_id`, `container_id` och `created_by_*` accepteras ALDRIG här — de
 * sätts av kontrollern (§ Beslut 2 och 3). `currency` normaliseras till
 * versaler och `supplier` trimmas i prepareForValidation() (§ Beslut 11); en
 * leverantör som bara består av blanktecken blir `null`, inte en tom sträng.
 */
class StoreCostEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 45a § Beslut 8.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['currency']) && is_string($data['currency'])) {
            $this->merge(['currency' => mb_strtoupper($data['currency'])]);
        }

        if (isset($data['supplier']) && is_string($data['supplier'])) {
            $supplier = trim($data['supplier']);
            $this->merge(['supplier' => $supplier === '' ? null : $supplier]);
        }

        // ConvertEmptyStringsToNull gör "" till null innan valideringen. En
        // tom sträng ska nå MinorUnits och bli cost.amount_invalid, inte
        // falla på validation.string — så null återställs till "" här. Ett
        // HELT saknat amount (nyckeln finns inte) rörs inte och faller på
        // validation.present.
        if (array_key_exists('amount', $data) && $data['amount'] === null) {
            $this->merge(['amount' => '']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'incurred_on' => ['required', 'date'],
            'amount' => ['present', 'string'],
            'currency' => ['required', 'string', 'alpha', 'size:3'],
            'description' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
