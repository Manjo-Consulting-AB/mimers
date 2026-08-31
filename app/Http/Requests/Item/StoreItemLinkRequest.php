<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/items/{item}/links, se issue 14 § Beslut
 * 7. Kroppen är `{item, relation}` — `item` är MOTPARTENS ULID (inte det
 * item rutten gäller), `relation` är vad det item rutten gäller ÄR för
 * motparten (`parent` | `child` | `sibling`).
 *
 * `item`-ULID:et måste finnas i DEN container rutten redan bär, och får
 * inte vara mjukraderat — en ULID som finns men hör till en annan container
 * är ett VALIDERINGSFEL (422 `validation.failed`), inte en 404 och inte ett
 * behörighetsfel, samma gränsdragning som issue 11 § Beslut 10 och 13a §
 * Beslut 7. `whereNull('deleted_at')` kringgår SoftDeletes globala scope,
 * som `Rule::exists` inte känner till.
 *
 * Att `item` inte är det item rutten gäller (item_link.self) och att
 * containern stämmer (item_link.cross_container) prövas i
 * App\Actions\Item\LinkItems, inte här — de är domänregler, inte fältfel.
 */
class StoreItemLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 14 § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item' => [
                'required',
                'string',
                Rule::exists('item', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'relation' => ['required', 'string', Rule::in(['parent', 'child', 'sibling'])],
        ];
    }
}
