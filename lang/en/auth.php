<?php

/*
 * Bara de nycklar ramverket saknar, se issue 52 § Beslut 7. FileLoader läser
 * både ramverkets lang/-katalog och appens och slår ihop dem med
 * array_replace_recursive där appens vinner — `failed`, `password` och
 * `throttle` kommer alltså från vendor och ska inte kopieras in här.
 * Lang/sv/auth.php är däremot komplett: ramverket har ingen svensk katalog.
 *
 * Nycklarna sätts av App\Http\Requests\Auth\LoginRequest och
 * App\Http\Controllers\Auth\TotpController och renderades före den här filen
 * som den råa nyckelsträngen `auth.totp_invalid` i ett formulärfel.
 */
return [
    'totp_required' => 'Enter the code from your authenticator app.',
    'totp_invalid' => 'That code is not valid. Try again.',
];
