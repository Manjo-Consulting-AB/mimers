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

    /*
    |--------------------------------------------------------------------------
    | Webhook-leveransen
    |--------------------------------------------------------------------------
    |
    | Begränsningarna för App\Console\DeliversWebhooks, se issue 37b § Beslut
    | 8. `timeout_seconds` är snäv med flit: allt arbete sker i den enda
    | process minutcronen startar, och en långsam leverans blockerar de övriga
    | under samma minut ([[ADR-0010 Notisarkitektur]] § Konsekvenser) — hellre
    | ett omförsök nästa minut än att vänta ut en död mottagare.
    | `max_attempts` är hur många gånger en rad anropas innan den ges upp och
    | markeras `failed`; backoffen är 2^(försök−1) minuter, takad vid 60.
    | `deactivate_after_failures` är hur många slutgiltigt misslyckade
    | leveranser i följd en endpoint tål innan den inaktiveras automatiskt
    | (is_active = false) — räknaren nollställs vid varje lyckad leverans och
    | vid manuell återaktivering (37a § Beslut 3).
    |
    */

    'webhook' => [

        'timeout_seconds' => (int) env('WEBHOOK_TIMEOUT_SECONDS', 5),

        'batch_size' => (int) env('WEBHOOK_BATCH_SIZE', 100),

        'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 6),

        'deactivate_after_failures' => (int) env('WEBHOOK_DEACTIVATE_AFTER_FAILURES', 20),

    ],

    /*
    |--------------------------------------------------------------------------
    | Veckosammanfattningen
    |--------------------------------------------------------------------------
    |
    | Begränsningarna för App\Console\SendsWeeklyDigest, se issue 35 § Beslut
    | 6. `max_items` är ett tak på hur många poster mejlet listar, av samma
    | skäl som `batch_size` i 34a: ett konto med fyrahundra förfallande
    | uppgifter ska inte generera ett mejl som mottagarens klient vägrar
    | visa. De poster som inte ryms listas inte — men bokförs ändå som
    | skickade, så de dyker inte upp i nästa veckas sammanfattning (35 §
    | "Att se upp med").
    |
    */

    'digest' => [

        'max_items' => (int) env('NOTIFICATION_DIGEST_MAX_ITEMS', 50),

    ],

    /*
    |--------------------------------------------------------------------------
    | Kvotvarningar
    |--------------------------------------------------------------------------
    |
    | Trösklarna för App\Console\GeneratesQuotaWarnings, se issue 34b §
    | Beslut 7. Bara den HÖGSTA tröskel som slagits i skickas per körning:
    | ett konto på 105 % av lagringsgränsen får en varning med `percent` 100,
    | inte två. Talen är procent av `usage_counter.storage_bytes` mot planens
    | `storage_bytes`-gräns.
    |
    */

    'quota' => [

        'warning_thresholds' => [80, 100],

    ],

    /*
    |--------------------------------------------------------------------------
    | ICS-kalenderfeed
    |--------------------------------------------------------------------------
    |
    | Takten för feedrutten GET /kalender/{token}.ics, se
    | App\Providers\AppServiceProvider::configureCalendarFeedRateLimiting()
    | och issue 36b § Beslut 7. 60 per minut är långt över vad en
    | kalenderklient behöver (Google Calendar hämtar med egen takt), och
    | ändå ett tak mot den som gissar token i loop. Nyckeln är tokenet,
    | inte IP:n — kalenderklienter delar utgående IP i mobilnät och bakom
    | brandväggar, och en spärr per IP skulle stänga av alla i samma nät.
    |
    */

    'calendar' => [

        'rate_limit_per_minute' => (int) env('NOTIFICATION_CALENDAR_RATE_LIMIT_PER_MINUTE', 60),

    ],

];
