<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Valutornas decimalgräns
    |--------------------------------------------------------------------------
    |
    | Antalet minsta enheter per huvudenhet för de valutor där ISO 4217
    | avviker från standarden 2 (se [[ADR-0016 Kostnadsregistrering]] och
    | issue 45a § Beslut 6). En kostnad skrivs in i huvudenhet ("1200,50")
    | och lagras som heltal i minsta enhet (120050); hur många decimaler
    | valutan tillåter avgör både utfyllnaden och gränsen för avvisning.
    |
    | Det är en UNDANTAGSlista, inte en tillåtslista: en valutakod som inte
    | står här får `default_minor_units`. En ofullständig lista ger fel
    | decimalgräns för just en exotisk valuta — att i stället avvisa valutan
    | helt skulle ge ingen kostnadsregistrering alls (issue 45a § Beslut 6).
    |
    */

    'default_minor_units' => 2,

    'minor_units' => [
        // Noll decimaler
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Tre decimaler
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
    ],

];
