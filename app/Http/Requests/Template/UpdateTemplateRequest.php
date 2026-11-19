<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/templates/{template} — döp om en mall, se issue 421 § 3.
 *
 * Bara namnet går att ändra. Innehållet är en kopia som containern redan har
 * fått, och att redigera mallen i efterhand är en annan yta än den här
 * ([[ADR-0089 Egna fält på items]] § Riktning 6).
 *
 * Mallen i rutten är redan prövad mot användarens konton av routebindningen i
 * routes/api.php — en mall utanför kontot blir 404 innan den här requesten
 * körs (issue 421 § 4).
 */
class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörigheten bor i routebindningen, se klassens docblock.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
