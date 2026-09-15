<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uppladdningstak
    |--------------------------------------------------------------------------
    |
    | Tekniskt tak på filstorleken, inte en plangräns — samma tal som
    | upload_max_filesize i public/.htaccess, se [[Pipeline]] §
    | Uppladdningsgränser och issue 16a § Beslut 9. Ett tak som ligger över
    | PHP:s eget vore en gräns som aldrig slår i, och ett under en gräns som
    | går att förklara. Plangränserna (max_file_bytes, storage_bytes) är
    | issue 27 och sätts ovanpå den här kontrollen, inte i stället för den.
    |
    */

    'max_upload_bytes' => (int) env('FILES_MAX_UPLOAD_BYTES', 64 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Uppladdningstakt
    |--------------------------------------------------------------------------
    |
    | Teknisk spärr på anropsfrekvensen för uppladdningsrutten, inte en
    | plangräns — den första rutten som skriver obegränsat med byte ska inte
    | kunna loopas av en autentiserad användare utan att något slår i, se
    | kodgranskningsfynd 5. Kvoten är issue 27.
    |
    */

    'upload_rate_limit_per_minute' => (int) env('FILES_UPLOAD_RATE_LIMIT_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Intern omdirigering
    |--------------------------------------------------------------------------
    |
    | True i staging och produktion (sätts i shared/.env): nedladdningsrouten
    | svarar med X-LiteSpeed-Location och LiteSpeed levererar bytena med
    | sendfile(), se [[ADR-0019 Filleverans]]. False lokalt och i testsviten:
    | appen strömmar filen själv. Båda grenarna sätter samma headers — en
    | miljöskillnad ska aldrig bli en säkerhetsskillnad (issue 19a § Beslut 6).
    |
    */

    'internal_redirect' => (bool) env('FILES_INTERNAL_REDIRECT', false),

    /*
    |--------------------------------------------------------------------------
    | Filoriginet
    |--------------------------------------------------------------------------
    |
    | Bas-URL:en till den egna originen användarfiler levereras från, t.ex.
    | https://files.mimers.app. Sätts i shared/.env av handpåläggningen som
    | lägger symlänken på plats, se [[Pipeline]] § Engångsuppsättning och
    | [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
    |
    | Osatt = leveransen ligger kvar på appdomänen precis som förut:
    | `files.deliver` registreras inte, `files.download` levererar bytena
    | själv och allt är `attachment` — uppföljningen 2026-08-31 gäller då
    | ordagrant. Ett värde vars värdnamn är appens eget räknas inte som en
    | egen origin; se App\Support\Files\FileOrigin.
    |
    */

    'url' => env('FILES_URL'),

    /*
    |--------------------------------------------------------------------------
    | Livstiden för en signerad leveranslänk
    |--------------------------------------------------------------------------
    |
    | Hur länge länken som `files.download` svarar med är giltig. Signaturen
    | är den enda grinden på filoriginet, och länken är bärarbaserad under sin
    | livstid — den som har den kan hämta filen, precis som med en presignerad
    | S3-URL ([[ADR-0019 Filleverans]] § Uppföljning 2026-09-15). Femton
    | minuter är långt nog för att en stor PDF ska hinna laddas och en
    | bläddring i ett bildgalleri ska hinna ske, och kort nog för att en länk
    | som hamnar i en logg eller ett Referer-huvud ska vara död när någon
    | hittar den.
    |
    */

    'signed_url_ttl_minutes' => (int) env('FILES_SIGNED_URL_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Tillåt-listan för inline
    |--------------------------------------------------------------------------
    |
    | De MIME-typer som får levereras `Content-Disposition: inline` på
    | filoriginet. Allt annat är `attachment` (issue 61a § Beslut 5), och
    | dispositionen bestäms alltid av `stored_file.mime_type` — aldrig av
    | URL:en.
    |
    | `image/svg+xml` står med flit inte här: en SVG är ett dokument som kan
    | bära skript, och den hör till den origin som finns för att sådant inte
    | ska kunna köra i appens domän. PDF:en är med för att 61b ska kunna visa
    | den i en ruta, och CSP:ns frame-ancestors pekar på appen.
    |
    */

    'inline_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Papperskorgens retention
    |--------------------------------------------------------------------------
    |
    | Så länge mjukraderat innehåll ligger kvar innan gallringen (20b) tar
    | bort det. Samma tal som fördröjningen innan filbytes raderas fysiskt,
    | se [[ADR-0008 Soft delete och papperskorg]] § Retentionstiden i MVP —
    | två tal att hålla isär blir ett tal som är fel. `expires_at` härleds ur
    | `deleted_at` plus det här talet och lagras aldrig i en kolumn (issue
    | 20a § Beslut 2).
    |
    */

    'trash_retention_days' => (int) env('TRASH_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Exportens retention
    |--------------------------------------------------------------------------
    |
    | Så länge en färdig export ligger kvar på disken innan gallringen (41b)
    | tar bort den, se [[Backlog]] M6 § 41 § Beslut 7. `expires_at` sätts av
    | jobbet när artefakten är klar och härleds aldrig på annat håll. Sju
    | dagar: en export är en påse man hämtar, inte ett arkiv man förvarar.
    |
    */

    'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),

];
