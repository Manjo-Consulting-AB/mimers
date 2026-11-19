<?php

namespace App\Actions\Field;

use App\Models\Container;
use App\Models\ItemField;
use App\Models\User;
use App\Support\Usage\MetadataLedger;
use Illuminate\Support\Facades\DB;

/**
 * Ändrar rubriken och/eller typen på en fältdefinition, se [[ADR-0089 Egna
 * fält på items]] § Riktning 3 och issue 417.
 *
 * **Typen är låst när fältet har värden.** App\Actions\Field\FieldRules
 * prövar det mot `item_field_value` och kastar `field.type_locked`; utan
 * värden går typen att byta.
 *
 * **Rubriken prövas mot de fält som syns på itemet**, med fältet självt
 * undantaget — det får behålla sitt eget namn. Se FieldRules.
 *
 * Positionen rörs inte här; den ändras med App\Actions\Field\ReorderFields.
 * Skrivningen går genom MetadataLedger, som för CreateField: rubriken är
 * fritext och räknas i `metadata_bytes`, och en växande rubrik kvotprövas.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class UpdateField
{
    /**
     * @param  array{label?: string, type?: string}  $attributes  Redan
     *                                                            validerade av UpdateFieldRequest.
     */
    public function handle(Container $container, ItemField $field, User $actor, array $attributes): ItemField
    {
        $label = array_key_exists('label', $attributes) ? trim($attributes['label']) : null;
        $type = $attributes['type'] ?? null;

        DB::transaction(function () use ($container, $field, $label, $type): void {
            // Läsningen sker inuti transaktionen: ett värde som skrivs
            // samtidigt som typbytet får inte slinka igenom mellan
            // prövningen och skrivningen.
            FieldRules::assertTypeChangeAllowed($field, $type);

            if ($label !== null) {
                FieldRules::assertLabelAvailable($container, $field->item, $label, $field->getKey());
                $field->label = $label;
            }

            if ($type !== null) {
                $field->type = $type;
            }

            MetadataLedger::save($field);
        });

        return $field;
    }
}
