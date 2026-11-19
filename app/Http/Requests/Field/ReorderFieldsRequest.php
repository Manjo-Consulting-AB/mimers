<?php

namespace App\Http\Requests\Field;

use App\Models\Container;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/fields/order och
 * PATCH /api/containers/{container}/items/{item}/fields/order, se issue 417
 * och [[ADR-0089 Egna fält på items]] § Riktning 9. Kroppen är
 * `{"fields": ["<ulid>", …]}` i önskad ordning.
 *
 * Valideringen bevisar att varje ULID finns i RÄTT omfång — containerns
 * fält (`item_id` NULL) eller itemets egna — och inte ligger i
 * papperskorgen. En ULID från ett annat item, en annan container eller en
 * mjukraderad rad är 422 `validation.failed`, samma gränsdragning som
 * StoreCategoryRequest gör för `parent` (issue 11 § Beslut 10).
 *
 * Listan får vara kortare än omfånget; App\Actions\Field\ReorderFields lägger
 * de onämnda fälten efter. En tom lista är tillåten och lämnar ordningen
 * orörd.
 *
 * Behörigheten prövas av kontrollern.
 */
class ReorderFieldsRequest extends FormRequest
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
        $container = $this->route('container');
        assert($container instanceof Container);

        $item = $this->route('item');
        $itemId = $item instanceof Item ? $item->id : null;

        return [
            'fields' => ['present', 'array'],
            'fields.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('item_field', 'ulid')->where(
                    fn ($query) => $query
                        ->where('container_id', $container->id)
                        ->whereNull('deleted_at')
                        ->when(
                            $itemId === null,
                            fn ($query) => $query->whereNull('item_id'),
                            fn ($query) => $query->where('item_id', $itemId),
                        )
                ),
            ],
        ];
    }
}
