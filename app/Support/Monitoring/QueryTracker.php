<?php

namespace App\Support\Monitoring;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

/**
 * Issue 390 · Räknar databasfrågorna under en förfrågan och skriver de
 * långsamma till loggkanalen `performance`, [[ADR-0079 Övervakning]] § 3.
 *
 * Raden bär SQL-texten UTAN bindningar — bindningarna är det som kan innehålla
 * personuppgifter — tiden i ms och ruttnamnet om förfrågan har ett. Ingen
 * användare, ingen IP och ingen URL.
 *
 * Registreras som `scoped` i AppServiceProvider, så att räknarna nollas mellan
 * förfrågningar och kö-jobb. Lyssnaren gör ingenting i testmiljön om inte
 * `monitoring.track_queries_in_tests` är sant.
 */
class QueryTracker
{
    public int $count = 0;

    public float $totalMs = 0.0;

    public function reset(): void
    {
        $this->count = 0;
        $this->totalMs = 0.0;
    }

    public function record(QueryExecuted $query): void
    {
        if (app()->runningUnitTests() && ! config('monitoring.track_queries_in_tests')) {
            return;
        }

        $this->count++;
        $this->totalMs += $query->time;

        if ($query->time <= (int) config('monitoring.slow_query_ms')) {
            return;
        }

        $route = app()->bound('request') ? request()->route() : null;

        // Övervakningen får aldrig fälla det den övervakar.
        try {
            Log::channel('performance')->info('query', [
                'type' => 'query',
                'sql' => mb_substr($query->sql, 0, 2000),
                'ms' => round($query->time, 1),
                'route' => is_object($route) ? $route->getName() : null,
            ]);
        } catch (\Throwable) {
            // Loggen går inte att skriva; förfrågan ska ändå gå igenom.
        }
    }
}
