<?php

namespace App\Http\Middleware;

use App\Actions\Usage\CountFeatureUse;
use App\Support\Usage\UsagePlan;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Issue 391 · Sidvisningar, [[ADR-0079 Övervakning]] § 4. Räknar upp en rad per
 * lyckad GET som renderar en Inertia-sida, med ruttnamn och plan (eller
 * `guest`) — aldrig användare, konto eller IP. Körs i `terminate`, efter att
 * svaret skickats, så att den aldrig gör sidan långsammare. Ett fel loggas och
 * sväljs. Partiella omladdningar, JSON, omdirigeringar och ormärkta rutter
 * räknas inte.
 */
class CountRouteView
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->isCountable($request, $response)) {
                return;
            }

            CountFeatureUse::increment(
                CountFeatureUse::SOURCE_ROUTE,
                (string) $request->route()->getName(),
                UsagePlan::code($request->user()),
            );
        } catch (Throwable $e) {
            Log::warning('Uppräkningen av sidvisning misslyckades.', ['exception' => $e::class]);
        }
    }

    private function isCountable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $name = $request->route()?->getName();

        if ($name === null || $name === '') {
            return false;
        }

        if ($request->headers->has('X-Inertia-Partial-Component') || $request->headers->has('X-Inertia-Partial-Data')) {
            return false;
        }

        // En Inertia-besökning bär X-Inertia; ett första sidladdning är HTML
        // från vyn `app` med `page`-data.
        if ($response->headers->has('X-Inertia')) {
            return true;
        }

        $original = $response->original ?? null;

        return $original instanceof View && array_key_exists('page', $original->getData());
    }
}
