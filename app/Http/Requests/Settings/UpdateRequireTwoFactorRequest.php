<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kryssrutan för tvåfaktorkravet — kontonivån (`account` ULID i kroppen) och
 * containernivån delar formen. Behörigheten prövas i actionen.
 */
class UpdateRequireTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account' => ['sometimes', 'string'],
            'require_two_factor' => ['required', 'boolean'],
        ];
    }
}
