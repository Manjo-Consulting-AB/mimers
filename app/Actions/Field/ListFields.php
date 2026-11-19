<?php

namespace App\Actions\Field;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use Illuminate\Support\Collection;

/**
 * Fälten i ett omfång: containerns (`$item === null`) eller ett items egna,
 * i `position`-ordning med `id` som tiebreak, se [[ADR-0089 Egna fält på
 * items]] § Riktning 9 och issue 417.
 *
 * Ordningen och filtret bor i relationerna — App\Models\Container::fields()
 * respektive App\Models\Item::ownFields() — så att listningen, routebindningen
 * och gränsräkningen ser samma rader. Fält i papperskorgen är inte med.
 *
 * Itemets relation sätts i minnet på varje rad så att
 * App\Http\Resources\ItemFieldResource kan svara med itemets ULID utan en
 * fråga per fält.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class ListFields
{
    /**
     * @return Collection<int, ItemField>
     */
    public function handle(Container $container, ?Item $item): Collection
    {
        $fields = ($item === null ? $container->fields() : $item->ownFields())->get();

        if ($item !== null) {
            foreach ($fields as $field) {
                $field->setRelation('item', $item);
            }
        }

        return $fields;
    }
}
