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

    /*
    |--------------------------------------------------------------------------
    | Leveransloopen
    |--------------------------------------------------------------------------
    |
    | Minutjobbets begränsningar, se App\Console\DeliversNotifications och
    | issue 34a § Beslut 6. `batch_size` är ett tak på hur många leveranser en
    | körning skickar — loopen delar minuten med fem andra nattliga jobb, och
    | en långsam mottagare blockerar dem alla ([[ADR-0010 Notisarkitektur]] §
    | Konsekvenser). 200 mejl i minuten är 288 000 per dygn — långt över vad
    | produkten kommer att skicka, och ändå ett tak. `max_attempts` är hur
    | många gånger en rad försöks innan den ges upp och markeras `failed`.
    |
    */

    'delivery' => [

        'batch_size' => (int) env('NOTIFICATION_DELIVERY_BATCH_SIZE', 200),

        'max_attempts' => (int) env('NOTIFICATION_DELIVERY_MAX_ATTEMPTS', 5),

    ],

];
