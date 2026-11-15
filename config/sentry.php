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

    // Webbläsarens Sentry (issue 388), delad som propen `sentry` av
    // HandleInertiaRequests. Ett eget projekt med en egen DSN, som är
    // offentlig. Nyckeln bor under `tracing` med flit: SDK:n kastar på varje
    // okänd toppnivånyckel men släpper `tracing` (LARAVEL_SPECIFIC_OPTIONS),
    // så en egen toppnivånyckel hade fällt serverns klient.
    'tracing' => [
        'js_dsn' => env('SENTRY_JS_DSN') ?: null,
    ],

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

    /*
     * Issue 389 · Hjärtslag från de schemalagda jobben, ADR-0079 § 2. Nyckeln
     * är postens `->name(...)` i routes/console.php, värdet Sentrys slug.
     * routes/console.php lägger `sentryMonitor(slug, schema)` på varje post
     * som står här; schemat är postens eget cron-uttryck. Utan DSN är
     * `sentryMonitor` verkningslös.
     *
     * Gratisnivån har ett begränsat antal bevakare. Räcker kvoten inte: stryk
     * underifrån, alltså den sista raden först. De fem första är viktigast
     * (nedgraderingen, papperskorgen, lagrade filer, notisleveransen,
     * livscykeln). Vaktens eget hjärtslag, `watches-delivery-queues`, ligger
     * utanför listan och står i routes/console.php. Listan ligger under
     * `crons` av samma skäl som `tracing` ovan: SDK:n släpper den nyckeln.
     */
    'crons' => [
        'monitors' => [
            'enforce-downgrades' => 'enforces-downgrades',
            'deliver-notifications' => 'delivers-notifications',
            'purge-expired-trash' => 'purges-expired-trash',
            'purge-expired-stored-files' => 'purges-expired-stored-files',
            'advance-account-lifecycle' => 'advances-account-lifecycle',
            'aggregate-usage-metrics' => 'aggregates-usage-metrics',
            'generate-task-notifications' => 'generates-task-notifications',
            'send-weekly-digest' => 'sends-weekly-digest',
            'delete-dormant-accounts' => 'deletes-dormant-accounts',
            'reconcile-usage-counters' => 'reconciles-usage-counters',
            'prune-logs' => 'prunes-logs',
        ],
    ],

];
