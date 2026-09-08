<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dead man's switch-ytan
    |--------------------------------------------------------------------------
    |
    | Den delade hemlighet som skyddar GET /drift/heartbeat, se
    | App\Http\Controllers\HeartbeatController, issue 43 § Beslut 6 och
    | deploy/drift/vakt.sh. Vakten på utvecklings-VPS:en skickar den i
    | X-Drift-Token; kontrollern jämför med hash_equals och avvisar med 404 —
    | aldrig 401 — när headern saknas, är fel, eller när det här värdet är tomt
    | eller osatt. Ett tomt env() får aldrig göra ytan öppen: samma fälla som
    | config/notiser.php § Mailgun-webhooken beskriver för en tom HMAC-nyckel
    | (Beslut 6). Inget förval, ingen genererad hemlighet — DRIFT_TOKEN sätts i
    | produktionens shared/.env (issue 44), och koden ska bete sig rätt utan
    | den satt.
    |
    */

    'token' => env('DRIFT_TOKEN'),

];
