<?php

use App\Http\Middleware\ScrubSentryEvent;

/*
 * Issue 387 · Felrapportering från servern till Sentrys EU-region, se
 * [[ADR-0079 Övervakning]] § 1. Bara de nycklar vi har ett beslut om står
 * här; resten får SDK:ns förval.
 *
 * Utan DSN är Sentry avstängt: lokalt, i testerna och i CI.
 */

return [

    'dsn' => env('SENTRY_LARAVEL_DSN') ?: null,

    // SENTRY_ENVIRONMENT sätts per miljö av utrullningen; APP_ENV är reserv.
    'environment' => env('SENTRY_ENVIRONMENT') ?: env('APP_ENV', 'production'),

    // `release` sätts i before_send ur config('app.version') (filen VERSION
    // från bygget). Config-filer läses före app.php och kan inte slå upp den.

    // Personuppgifter: ingen IP-adress, inga kakor, inga request-kroppar.
    'send_default_pii' => false,

    // before_send som strängcallable, inte closure: config:cache kan inte
    // serialisera en closure. Ser även till att release och pseudonym sätts.
    'before_send' => [ScrubSentryEvent::class, 'beforeSend'],

    // Ingen prestandaspårning (ADR-0079 § 3).
    'traces_sample_rate' => 0,
    'profiles_sample_rate' => 0,

    'breadcrumbs' => [
        // Bindningarna kan innehålla personuppgifter, så inga SQL-brödsmulor.
        'sql_queries' => false,
        'sql_bindings' => false,
        'logs' => true,
    ],

    // Loggbrödsmulor på warning och uppåt.
    'logs_channel_level' => 'warning',

];
