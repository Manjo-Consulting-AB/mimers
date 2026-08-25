<?php

namespace App\Http\Requests\Container;

use App\Models\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}, se issue 8 § Beslut 9: PATCH tar bara
 * emot `name` och `kind`, båda valfria (`sometimes`). Varken `account`
 * eller `account_id` finns i reglerna nedan — ett klientskickat sådant
 * fält är alltså inte med i `validated()` och ändrar aldrig ägaren. Att
 * flytta en container mellan konton är ägarbyte, issue 39.
 */
class UpdateContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'string', Rule::in(Container::KINDS)],
        ];
    }
}
