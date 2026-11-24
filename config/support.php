<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supportadressen
    |--------------------------------------------------------------------------
    |
    | Vart kontaktformulärets meddelande går, se M89 ·
    | [[ADR-0096 Kontaktformuläret]] § 3. Brevlådan ägs av inleed, som redan
    | är vårt biträde, och personalen svarar därifrån — formuläret lagrar
    | ingenting i databasen och skickar inget kvitto till avsändaren.
    |
    | Adressen går att peka om per miljö, så att staging kan använda en egen
    | brevlåda utan att koden ändras.
    |
    */

    'address' => env('SUPPORT_ADDRESS', 'support@mimers.app'),

];
