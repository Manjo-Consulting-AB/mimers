<?php

/*
 * Gränserna för egna fält, se [[ADR-0089 Egna fält på items]] § Riktning 4
 * och [[Items och organisation]] § item_field. Alla gränser prövas i
 * applikationen (App\Actions\Field\FieldRules), aldrig i schemat, och är
 * desamma för alla planer: det som kostar är lagringen, och den räknas redan
 * mot kvoten ([[ADR-0081 All lagrad data räknas mot kvoten]]).
 *
 * En skrivning över en gräns ger 422 `field.limit_reached` med gränsen och
 * omfånget i `data`. Bara fält som inte ligger i papperskorgen räknas.
 */
return [
    // Fält per container.
    'max_per_container' => 25,

    // Egna fält per item, utöver containerns.
    'max_per_item' => 10,

    // Ett textvärdes längd. Låset för `item_field_value.value_text` i 418.
    'text_max_length' => 255,
];
