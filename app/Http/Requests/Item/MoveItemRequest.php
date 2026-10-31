<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST .../items/{item}/move, se [[ADR-0069 Flytta ett item inom containern]]
 * § 5 regel 1 och § 9. Kroppen är `{to_parent, from_parent?}`.
 *
 * `to_parent` är obligatoriskt men får vara `null` (toppnivån): `present`
 * kräver nyckeln, `nullable` släpper värdet. `from_parent` får saknas eller
 * vara `null`. Båda är ULID:er som måste vara levande items i DEN container
 * rutten bär — en ULID från en annan container eller ur papperskorgen är ett
 * valideringsfel på fältet, aldrig en 404. `whereNull('deleted_at')`
 * kringgår SoftDeletes globala scope, som `Rule::exists` inte känner till.
 *
 * Behörigheten avgörs i kontrollern, inte här.
 */
class MoveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_parent' => ['present', 'nullable', 'string', $this->livingItemInContainer()],
            'from_parent' => ['nullable', 'string', $this->livingItemInContainer()],
        ];
    }

    private function livingItemInContainer(): mixed
    {
        return Rule::exists('item', 'ulid')->where(
            fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
        );
    }
}
