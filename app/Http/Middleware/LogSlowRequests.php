<?php

namespace App\Http\Middleware;

use App\Support\Monitoring\QueryTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue 390 · Långsamma förfrågningar, [[ADR-0079 Övervakning]] § 3.
 *
 * Mäter tiden från start till svar och skriver en rad till loggkanalen
 * `performance` när den överstiger `monitoring.slow_request_ms`. Raden bär
 * ruttnamn (eller `unnamed`), metod, status, tid, antal databasfrågor och
 * total frågetid. Aldrig URL, parametrar, användare eller IP.
 */
class LogSlowRequests
{
    public function __construct(private readonly QueryTracker $queries) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $this->queries->reset();

        $response = $next($request);

        $ms = (microtime(true) - $start) * 1000;

        if ($ms > (int) config('monitoring.slow_request_ms')) {
            Log::channel('performance')->info('request', [
                'type' => 'request',
                'route' => $request->route()?->getName() ?? 'unnamed',
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
                'ms' => (int) round($ms),
                'queries' => $this->queries->count,
                'query_ms' => (int) round($this->queries->totalMs),
            ]);
        }

        return $response;
    }
}
