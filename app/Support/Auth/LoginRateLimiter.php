<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bygger nycklarna för inloggningsbegränsaren och rensar dem vid en lyckad
 * inloggning — se issue 7 och uppföljningen "Rensa begränsaren vid lyckad
 * inloggning". Ett enda delat ställe, så att webbens och API:ets
 * inloggningskontroller (App\Http\Controllers\Auth\AuthenticatedSessionController,
 * App\Http\Controllers\Api\Auth\AuthenticatedTokenController) och
 * App\Providers\AppServiceProvider::configureLoginRateLimiting() garanterat
 * pekar på samma poster — annars rensas fel nyckel, eller ingen alls.
 *
 * `throttle:login`-middlewaret (Illuminate\Routing\Middleware\ThrottleRequests)
 * hashar nyckeln innan den lagras i cachen, för en NAMNGIVEN begränsare med
 * flera Limit-objekt: `md5($limiterName.$rawKey)`, se
 * ThrottleRequests::handleRequestUsingNamedLimiter() och dess
 * `self::$shouldHashKeys` (standard true, aldrig ändrad i den här appen).
 * `RateLimiter::clear()` måste alltså anropas med SAMMA hash — inte den råa
 * nyckeln som skickas till `Limit::by()` — annars träffar den ingenting.
 * self::cacheKey() återskapar exakt den beräkningen.
 */
final class LoginRateLimiter
{
    /**
     * Namnet på den registrerade RateLimiter::for()-begränsaren, delat
     * mellan AppServiceProvider och `throttle:login`-middlewaret i
     * routes/web.php och routes/api.php.
     */
    public const NAME = 'login';

    public static function emailKey(string $email): string
    {
        return 'login-email:'.mb_strtolower($email);
    }

    public static function ipKey(Request $request): string
    {
        return 'login-ip:'.$request->ip();
    }

    /**
     * Rensar både e-post- och IP-begränsningen för den här requesten efter
     * en lyckad inloggning — Laravels eget mönster (Fortifys
     * AttemptToAuthenticate gör exakt så), inte en egenbyggd begränsare.
     * Annars äter användarens egna lyckade inloggningar (flera enheter,
     * omlogg efter en utgången token) av samma budget som är till för att
     * stoppa gissningsförsök.
     */
    public static function clear(Request $request, string $email): void
    {
        RateLimiter::clear(self::cacheKey(self::emailKey($email)));
        RateLimiter::clear(self::cacheKey(self::ipKey($request)));
    }

    private static function cacheKey(string $rawKey): string
    {
        return md5(self::NAME.$rawKey);
    }
}
