<?php

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/templates — spara en containers kategorier och fält som en mall,
 * se issue 421 § 3 och 5.
 *
 * Kroppen är `{"container", "name"}`: `container` är en container-ULID och
 * namnet är mallens. Containern slås INTE upp här; `exists`-regeln bevisar
 * att ULID:en finns och lever, och App\Http\Controllers\Api\
 * ContainerTemplateController prövar `view` i den — ett valideringsfel för en
 * okänd container, ett behörighetsfel för en container användaren inte når,
 * samma gränsdragning som StoreContainerRequest gör för `account`.
 *
 * Medlemskapet i containerns konto prövas i kontrollern, inte här: det är en
 * behörighetsfråga (issue 421 § 4), inte ett format på indata.
 */
class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Behörigheten prövas av kontrollern — se klassens docblock.
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
            'container' => [
                'required',
                'string',
                Rule::exists('container', 'ulid')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                ),
            ],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'include_agent_rules' => ['sometimes', 'boolean'],
        ];
    }
}
