<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/containers/{container}/items/{item}/loans, se issue 76 § Beslut
 * 9. Kroppen är `{"borrower_name", "borrower_email"?, "lent_at", "due_at"?,
 * "returned_at"?, "note"?}`.
 *
 * `item_id` accepteras ALDRIG här — det sätts av kontrollern från rutten.
 * `borrower_email` är en kontaktuppgift i vyn, aldrig en mottagaradress
 * (§ Beslut 8). `due_at` och `returned_at` får inte ligga före `lent_at`.
 */
class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 76 § Beslut 6.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'borrower_name' => ['required', 'string', 'max:255'],
            'borrower_email' => ['nullable', 'email', 'max:255'],
            'lent_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:lent_at'],
            'returned_at' => ['nullable', 'date', 'after_or_equal:lent_at'],
            'note' => ['nullable', 'string'],
        ];
    }
}
