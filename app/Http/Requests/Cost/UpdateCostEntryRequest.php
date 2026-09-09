<?php

namespace App\Http\Requests\Cost;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/containers/{container}/items/{item}/costs/{cost}, se issue 45a §
 * Beslut 12. Varje dokumenterat fält är valfritt; bara skickade fält ändras.
 *
 * `amount` och `currency` tolkas ALLTID som ett par: skickas det ena krävs
 * det andra (`required_with` åt båda hållen). Skälet är att decimalgränsen
 * hänger på valutan — en PATCH som byter EUR till JPY utan att skicka
 * beloppet skulle göra en giltig rad ogiltig i tysthet. Därför har de två
 * fälten INGET `sometimes`: `required_with` är en implicit regel som måste få
 * slå även när fältet är frånvarande, och `sometimes` skulle stänga av den.
 * De övriga fälten är `sometimes` som vanligt.
 *
 * `amount` är en sträng i huvudenhet och formas av MinorUnits i kontrollern
 * (§ Beslut 4 och 5). `currency` normaliseras till versaler och `supplier`
 * trimmas i prepareForValidation() (§ Beslut 11).
 */
class UpdateCostEntryRequest extends FormRequest
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

        // ConvertEmptyStringsToNull gör "" till null innan valideringen — se
        // StoreCostEntryRequest. En tom sträng ska nå MinorUnits, inte falla
        // på validation.string.
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
            'incurred_on' => ['sometimes', 'date'],
            'amount' => ['required_with:currency', 'string'],
            'currency' => ['required_with:amount', 'string', 'alpha', 'size:3'],
            'description' => ['sometimes', 'string', 'max:255'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
