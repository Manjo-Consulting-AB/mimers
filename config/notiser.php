<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Postmark-webhooken
    |--------------------------------------------------------------------------
    |
    | Hemligheten som Postmark autentiserar sig med när webhooken anropas,
    | se [[Notiser]] § email_suppression och issue 33b. Postmark skickar
    | HTTP Basic mot den här hemligheten (Beslut 2) — användarnamnet och
    | lösenordet läggs direkt i webhook-URL:en hos Postmark. Saknas något av
    | fälten avvisar rutten ALLT, oavsett vad som skickas: ett tomt env()
    | som jämförs mot ett tomt inskickat lösenord vore en öppen rutt i varje
    | miljö där någon glömt sätta variabeln (Beslut 2).
    |
    */

    'postmark' => [

        'webhook_user' => env('POSTMARK_WEBHOOK_USER'),

        'webhook_password' => env('POSTMARK_WEBHOOK_PASSWORD'),

        /*
        |--------------------------------------------------------------------------
        | Postmark-webhookens takt
        |--------------------------------------------------------------------------
        |
        | Spärr på anropsfrekvensen för webhookrutten, se
        | App\Providers\AppServiceProvider::configurePostmarkWebhookRateLimiting().
        | Taket ligger högt med flit (300/minut per IP): Postmark skickar i
        | skurar efter ett utskick, och en spärr som slår i mot vår egen
        | leverantör tappar studsar (Beslut 6). Gränsen finns för att en
        | okänd avsändare inte ska kunna hamra rutten med gissade lösenord i
        | obegränsad takt.
        |
        */

        'webhook_rate_limit_per_minute' => (int) env('POSTMARK_WEBHOOK_RATE_LIMIT_PER_MINUTE', 300),

    ],

];
