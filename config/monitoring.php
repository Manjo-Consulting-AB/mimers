<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prestandaloggen
    |--------------------------------------------------------------------------
    |
    | Issue 390 · [[ADR-0079 Övervakning]] § 3. Förfrågningar över
    | `slow_request_ms` och databasfrågor över `slow_query_ms` skrivs till
    | loggkanalen `performance`, se App\Http\Middleware\LogSlowRequests och
    | AppServiceProvider::configurePerformanceLogging().
    |
    | `track_queries_in_tests` är false: frågelyssnaren räknar ingenting i
    | testmiljön om inte ett prov uttryckligen ber om det.
    |
    | `ops_email` är driftadressen som får veckosammanställningen
    | (App\Console\SummarizesPerformance). Utan adress skickas inget.
    |
    */

    'slow_request_ms' => (int) env('MONITORING_SLOW_REQUEST_MS', 1000),

    'slow_query_ms' => (int) env('MONITORING_SLOW_QUERY_MS', 200),

    'track_queries_in_tests' => false,

    'ops_email' => env('OPS_EMAIL'),

];
