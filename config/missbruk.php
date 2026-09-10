<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tröskelvärden och fönster för missbruksrapporten
    |--------------------------------------------------------------------------
    |
    | Alla tal App\Console\ReportsAbuseSignals använder. Varenda ett är en
    | PLATSHÅLLARE, inte en gräns: [[ADR-0017 Missbruksvektorer]] §
    | Konsekvenser säger "Trösklarna ovan är platshållare ... De ska revideras,
    | inte kodas in som konstanter", och rapporten finns just för att ge
    | underlaget att revidera dem med. Ingen av dem är en ny plangräns — de bor
    | här, inte i `plan.limits`, och ingen kontrollpunkt läser dem.
    |
    | `window_days` är fönstret för de tidsbundna talen (nya gratiskonton,
    | utskickade mejl, containers i kluster): en nattlig körning med rullande
    | sjudagarsfönster. `*_min` är trösklarna — ett värde under dem listas
    | inte. `listing_limit` kapar antalet rader per listning i loggen; en
    | rapport som skriver tvåtusen rader en natt är en rapport ingen läser.
    |
    | Överskrivbara på servern utan ny release; förvalen är de som gäller.
    |
    */

    // Fönstret för de tidsbundna talen.
    'window_days' => (int) env('ABUSE_REPORT_WINDOW_DAYS', 7),

    // "fler än fem" -> listas vid sex.
    'managed_container_min' => (int) env('ABUSE_REPORT_MANAGED_CONTAINER_MIN', 6),

    // stored_file som listas alls.
    'reference_count_min' => (int) env('ABUSE_REPORT_REFERENCE_COUNT_MIN', 10),

    // ... och över så här många konton.
    'distinct_account_min' => (int) env('ABUSE_REPORT_DISTINCT_ACCOUNT_MIN', 3),

    // registrerings-IP med minst två konton.
    'ip_account_min' => (int) env('ABUSE_REPORT_IP_ACCOUNT_MIN', 2),

    // containers per IP och dygn.
    'ip_container_min' => (int) env('ABUSE_REPORT_IP_CONTAINER_MIN', 3),

    // rader per listning i loggen.
    'listing_limit' => (int) env('ABUSE_REPORT_LISTING_LIMIT', 20),

];
