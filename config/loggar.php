<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Loggarnas gallringsfrister
    |--------------------------------------------------------------------------
    |
    | Hur länge loggarnas rader behålls innan App\Console\PrunesLogs tar bort
    | dem, se [[ADR-0043 Tre loggar]] § Beslut. Händelseloggen sparas så länge
    | det den handlar om finns, plus tolv månader — och säkerhetsloggen i tolv
    | månader. Talen bor här i stället för som konstanter i konsolklassen,
    | samma skäl som config/konton.php § Gallringsfrist för registrerings-IP:t:
    | en frist är en uppgift om verksamheten, inte om koden.
    |
    | De två talen är skilda åt trots att de är lika: händelseloggens frist
    | räknas från containerns `container.purged` eller kontots
    | `account.deleted`, säkerhetsloggens från radens eget `created_at`. Ändras
    | den ena är det inte givet att den andra följer med.
    |
    | Gränsen räknas i kalendermånader — koden använder Carbon::subMonths(),
    | aldrig subDays(), samma regel som kontolivscykeln i config/konton.php.
    | Tolv månader är ett år, inte 365 dagar.
    |
    */

    'audit_retention_months' => (int) env('LOG_AUDIT_RETENTION_MONTHS', 12),

    'security_retention_months' => (int) env('LOG_SECURITY_RETENTION_MONTHS', 12),

];
