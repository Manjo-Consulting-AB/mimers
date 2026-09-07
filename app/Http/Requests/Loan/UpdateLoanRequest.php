<?php

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/containers/{container}/items/{item}/loans/{loan}, se issue 76 §
 * Beslut 9. Varje dokumenterat fält är valfritt (`sometimes`); bara skickade
 * fält ändras.
 *
 * Reglerna gäller det SAMMANSLAGNA tillståndet efter ändringen, inte bara de
 * fält som skickades med. Därför lägger validationData() radens nuvarande
 * värden under klientens — ett PATCH som bara ändrar `borrower_name` på ett
 * stängt lån ska inte tvingas skicka om `lent_at`, och en förlängning av
 * `lent_at` som gör att ett befintligt `due_at` hamnar före ska avvisas mot
 * den NYA `lent_at`.
 *
 * `item_id` accepteras aldrig, inte heller här — se StoreLoanRequest.
 */
class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 76 § Beslut 6.
        return true;
    }

    /**
     * Den data valideringen ser: radens nuvarande värden sammanslagna med
     * klientens. Datumen läses genom attributen (inte getAttributes) så de
     * serialiseras som "2026-09-07" — databasens råa format (sqlite kan ge
     * "2026-09-07 00:00:00") skulle annars göra `after_or_equal`-jämförelsen
     * skör mot klientens rena datumsträngar.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $loan = $this->route('loan');

        $current = $loan instanceof Loan
            ? [
                'borrower_name' => $loan->borrower_name,
                'borrower_email' => $loan->borrower_email,
                'lent_at' => $loan->lent_at->toDateString(),
                'due_at' => $loan->due_at?->toDateString(),
                'returned_at' => $loan->returned_at?->toDateString(),
                'note' => $loan->note,
            ]
            : [];

        return array_merge($current, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'borrower_name' => ['sometimes', 'string', 'max:255'],
            'borrower_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'lent_at' => ['sometimes', 'required', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:lent_at'],
            'returned_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:lent_at'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
