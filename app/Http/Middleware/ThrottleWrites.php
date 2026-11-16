<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ett tak på hur många skrivningar en inloggad användare gör per minut, i
 * webben och i API:et gemensamt. Se [[ADR-0081 All lagrad data räknas mot
 * kvoten]] beslut 7 och [[ADR-0017 Missbruksvektorer]].
 *
 * Gäller bara inloggade anrop med en skrivande metod (allt utom GET, HEAD
 * och OPTIONS). En rutt som redan har en egen begränsare (`throttle:...`:
 * uppladdning, inloggning, registrering, Mailgun) räknas inte, så att inget
 * anrop dubbelräknas. Det avgörs genom att läsa ruttens middleware, inte
 * genom en lista med URI:er.
 *
 * Svaret byggs här och kastas inte som ThrottleRequestsException: den
 * renderingen i bootstrap/app.php svarar alltid `auth.too_many_attempts`
 * och lägger webbfelet på `email`.
 */
class ThrottleWrites
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READ_METHODS, true) || $this->hasOwnLimiter($request)) {
            return $next($request);
        }

        // 'sanctum' som reserv: gruppmiddleware körs före auth:sanctum, och
        // standardvakten är då sessionsvakten, som inte ser en token.
        $user = $request->user() ?? $request->user('sanctum');

        if ($user === null) {
            return $next($request);
        }

        $key = 'writes:'.$user->getKey();
        $max = (int) config('missbruk.write_rate_limit_per_minute', 120);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return $this->tooManyWrites($request, $user->ulid, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }

    private function hasOwnLimiter(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                return true;
            }
        }

        return false;
    }

    private function tooManyWrites(Request $request, string $ulid, int $retryAfter): Response
    {
        // Ulid, metod och ruttnamn — inga parametrar, inget innehåll, ingen IP.
        Log::warning('rate_limit.writes', [
            'user' => $ulid,
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
        ]);

        if ($request->is('api/*')) {
            return ApiError::response('rate_limit.writes_exceeded', [
                'retry_after_seconds' => $retryAfter,
            ], 429)->withHeaders(['Retry-After' => $retryAfter]);
        }

        return back()
            ->withInput($request->except(['current_password', 'password', 'password_confirmation']))
            ->withErrors(['rate_limit' => __('ui.error.rate_limit.writes_exceeded', ['seconds' => $retryAfter])]);
    }
}
