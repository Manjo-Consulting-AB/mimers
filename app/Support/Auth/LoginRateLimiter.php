<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bygger nycklarna för inloggningsbegränsaren och ger tillbaka budgeten vid en
 * lyckad inloggning — e-postnyckeln rensas, IP-nyckeln räknas ned med ett.
 * Se issue 7 och uppföljningen "Rensa begränsaren vid lyckad inloggning". Ett enda delat ställe, så att webbens och API:ets
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
     * Efter en lyckad inloggning: e-postbegränsningen rensas helt, men
     * IP-budgeten ges bara tillbaka med ETT — den lyckade inloggningens eget
     * försök. En egen lyckad inloggning (flera enheter, omlogg efter en
     * utgången token) ska inte äta av budgeten som är till för att stoppa
     * gissningar. Men den ska inte heller tvätta bort andras misslyckade
     * försök från samma adress: rensades IP-nyckeln kunde den som har ett
     * eget konto gissa nio lösenord mot andra adresser, logga in som sig
     * själv och börja om, så att taket på tio per minut aldrig slog i.
     *
     * Räknaren går aldrig under noll. Webbens mejllänk (GET) passerar ingen
     * begränsare, så en nedräkning där utan föregående försök hade gett
     * ett tillgodohavande som ingen betalat för.
     */
    public static function clear(Request $request, string $email): void
    {
        RateLimiter::clear(self::cacheKey(self::emailKey($email)));

        $ipKey = self::cacheKey(self::ipKey($request));

        if (RateLimiter::attempts($ipKey) > 0) {
            RateLimiter::decrement($ipKey, 60);
        }
    }

    private static function cacheKey(string $rawKey): string
    {
        return md5(self::NAME.$rawKey);
    }
}
