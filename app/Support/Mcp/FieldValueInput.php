<?php

namespace App\Support\Mcp;

use App\Actions\Field\ItemFieldInput;
use App\Models\Container;
use App\Models\Item;
use Laravel\Mcp\Request;

/**
 * Normaliserar `fields` i MCP-anropen `items_create` och `items_update` på
 * samma sätt som `/api`:ets FormRequests gör, se [[ADR-0089 Egna fält på
 * items]] § Riktning 8 och 9 och issue 423.
 *
 * **En tom sträng är "töm fältet".** API:et gör om `''` till `null` före
 * valideringen, så ett tomt värde i ett tal- eller datumfält passerar
 * `nullable` i stället för att falla på typregeln. MCP måste svara samma sak,
 * annars ger samma kropp olika fel på de två ytorna.
 *
 * **Ett flyttal i ett talfält blir en decimalsträng utan exponent**, så att
 * omfångsregeln prövar samma siffror som `DECIMAL(20,6)` tar emot
 * (App\Actions\Field\ItemFieldInput::normalizeNumbers()).
 *
 * Reglerna byggs av samma klass som API:et använder, så felkoderna
 * (`validation.numeric`, `field.not_applicable`, …) är identiska.
 */
final class FieldValueInput
{
    /**
     * Skriver tillbaka den normaliserade listan på requesten — `validate()`
     * läser `all()` — och svarar med den, eller `null` när `fields` saknas.
     *
     * @return list<array{field: string, value?: mixed}>|null
     */
    public static function normalize(Request $request, Container $container, ?Item $item): ?array
    {
        $fields = $request->get('fields');

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $index => $entry) {
            if (is_array($entry) && ($entry['value'] ?? null) === '') {
                $fields[$index]['value'] = null;
            }
        }

        $fields = ItemFieldInput::normalizeNumbers($container, $item, $fields);
        $request->merge(['fields' => $fields]);

        return $fields;
    }
}
