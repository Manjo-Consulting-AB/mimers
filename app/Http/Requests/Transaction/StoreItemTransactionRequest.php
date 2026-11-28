<?php

namespace App\Http\Requests\Transaction;

use App\Rules\IsoCurrency;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/containers/{container}/items/{item}/costs, se issue 45a § Beslut
 * 5 och 11. Kroppen är `{"incurred_on", "amount", "currency"?, "description",
 * "supplier"?}`.
 *
 * `amount` är en STRÄNG i huvudenhet och valideras bara som `present|string`
 * här — själva formen (komma eller punkt, decimalgräns, BIGINT-gräns) tolkas
 * av App\Support\Transaction\MinorUnits i kontrollern och kastas som
 * `cost.amount_invalid`/`cost.amount_decimals`, inte som validation.failed
 * (§ Beslut 5). `present` i stället för `required`: en tom sträng ska nå
 * MinorUnits och bli `cost.amount_invalid` (Klart när), medan ett helt
 * saknat fält är ett formfel. En klient som skickar `amount` som JSON-tal
 * (1200.50 i stället för "1200.50") faller på `validation.string`.
 *
 * **`currency` är VALFRI i kroppen sedan issue 85 · [[ADR-0037 Valutans
 * arv]], men förblir OBLIGATORISK i datan** — till skillnad från `supplier`,
 * som är valfri i båda. Kolumnen är oförändrat `NOT NULL` ([[ADR-0016
 * Kostnadsregistrering]]): det är kontrollern som fyller tomrummet med
 * containerns `effectiveCurrency()`, vilket är precis det formuläret gör när
 * det visar containerns valuta som förval. Ett värde som SKICKAS vinner
 * alltid och sparas ordagrant (versalnormaliserat) — arvet är ett förslag,
 * aldrig ett tvång. Ett tomt värde och en saknad nyckel betyder samma sak;
 * App\Rules\IsoCurrency gäller så fort ett värde är där, så bara en aktiv
 * ISO 4217-kod kan lagras ([[ADR-0078 Valutor ur ISO 4217]] § 1).
 *
 * `item_id`, `container_id` och `created_by_*` accepteras ALDRIG här — de
 * sätts av kontrollern (§ Beslut 2 och 3). `currency` normaliseras till
 * versaler och `supplier` trimmas i prepareForValidation() (§ Beslut 11); en
 * leverantör som bara består av blanktecken blir `null`, inte en tom sträng.
 */
class StoreItemTransactionRequest extends FormRequest
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

        if (isset($data['counterparty']) && is_string($data['counterparty'])) {
            $counterparty = trim($data['counterparty']);
            $this->merge(['counterparty' => $counterparty === '' ? null : $counterparty]);
        }

        // Aliaset under avvecklingstiden (ADR-0102, ADR-0087 § Riktning 15):
        // kommer bara `supplier` läses det som `counterparty`.
        if (! array_key_exists('counterparty', $data) && array_key_exists('supplier', $this->all())) {
            $this->merge(['counterparty' => $this->input('supplier')]);
        }

        if (isset($data['quantity']) && is_string($data['quantity'])) {
            $this->merge(['quantity' => trim($data['quantity'])]);
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
            // `nullable` och inte `sometimes|required`: ett tomt värde och en
            // saknad nyckel betyder samma sak — "föreslå containerns" — och
            // valet ligger i kontrollern. Listan prövas så fort ett värde är
            // där, så en rad kan aldrig lagras med en kod utanför ISO 4217
            // ([[ADR-0078 Valutor ur ISO 4217]] § 1).
            'currency' => ['nullable', 'string', new IsoCurrency],
            'description' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', 'in:expense,income'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999999', 'decimal:0,6'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            // Returadressen (issue 369 · M56). Formen prövas inte här: den
            // avgörs av TaskCreateController::safeReturn() i kontrollern, och
            // en adress som inte godkänns faller tyst tillbaka på itemets flik.
            'return' => ['nullable', 'string'],
        ];
    }
}
