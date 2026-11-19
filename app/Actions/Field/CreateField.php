<?php

namespace App\Actions\Field;

use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use App\Support\Usage\MetadataLedger;
use App\Support\Usage\Payer;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en fältdefinition — på containern eller på ett enskilt item — se
 * [[ADR-0089 Egna fält på items]] § Riktning 1–4 och issue 417.
 *
 * `$item === null` betyder containerns fält (`item_id` NULL); annars ett
 * items egna. Gränsen och rubrikkrocken kommer ur
 * App\Actions\Field\FieldRules, så att skapandet och återställningen ställer
 * samma fråga.
 *
 * `position` sätts till nästa lediga i omfånget när den utelämnas. Servern
 * skriver aldrig om en angiven position här — ordningen ändras med
 * App\Actions\Field\ReorderFields.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen:
 * containerns fält mot `ContainerPolicy::update()`, itemets egna mot
 * `ItemPolicy::update()` ([[ADR-0028 Åtkomst på itemnivå]]), samma linje som
 * App\Actions\Category\CreateCategory.
 *
 * Raden skrivs genom App\Support\Usage\MetadataLedger, så att
 * `metadata_bytes` bokförs och kvoten prövas i SAMMA transaktion som raden
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2 och 3).
 */
class CreateField
{
    public function handle(
        Container $container,
        ?Item $item,
        string $label,
        string $type,
        ?int $position,
        User $actor,
    ): ItemField {
        $label = trim($label);

        FieldRules::assertWithinLimit($container, $item);
        FieldRules::assertLabelAvailable($container, $item, $label);

        $field = new ItemField([
            'label' => $label,
            'type' => $type,
            'position' => $position ?? $this->nextPosition($container, $item),
        ]);
        $field->container_id = $container->id;
        $field->item_id = $item?->id;
        $field->billed_account_id = Payer::idFor($actor, $container);

        DB::transaction(function () use ($field): void {
            MetadataLedger::save($field);
        });

        if ($item !== null) {
            $field->setRelation('item', $item);
        }

        return $field;
    }

    /**
     * `max(position)` bland fälten i omfånget plus ett, eller 1 om det är
     * det första. Ett fält i papperskorgen är inte med — det syns ingenstans
     * och upptar ingen plats.
     */
    private function nextPosition(Container $container, ?Item $item): int
    {
        $max = FieldRules::fieldsInScope($container, $item)->max('position');

        return $max === null ? 1 : (int) $max + 1;
    }
}
