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

];
