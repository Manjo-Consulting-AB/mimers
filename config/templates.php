<?php

/*
 * Gränserna för mallar, se [[ADR-0089 Egna fält på items]] § Riktning 9 och
 * [[Items och organisation]] § container_template.
 *
 * Gränsen prövas i applikationen (App\Actions\Template\SaveContainerTemplate),
 * aldrig i schemat, och är densamma för alla planer: det som kostar är
 * lagringen, och den räknas redan mot kvoten
 * ([[ADR-0081 All lagrad data räknas mot kvoten]]).
 *
 * Den 51:a mallen ger 422 `template.limit_reached` med gränsen i `data`.
 * Bara mallar som inte ligger i papperskorgen räknas.
 */
return [
    // Mallar per konto.
    'max_per_account' => 50,
];
