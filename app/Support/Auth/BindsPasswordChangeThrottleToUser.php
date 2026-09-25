<?php

namespace App\Support\Auth;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takgränsen för lösenordsbytet — issue 129, samma form som sitt syskon
 * App\Support\Auth\BindsMagicLinkCodeThrottleToPendingLogin.
 *
 * `throttle:login` (App\Providers\AppServiceProvider::configureLoginRateLimiting())
 * bygger sin kontonyckel av `$request->string('email')`. Lösenordsbytets kropp
 * bär ingen adress — användaren kommer ur sessionen — så rakt på den här rutten
 * hade nyckeln varit tom och blivit EN hink som hela installationen delade:
 * en enda användares gissningsförsök hade stängt av lösenordsbytet för alla
 * andra, och gränsen hade inte gått att släppa igenom.
 *
 * Det här middlewaret sätter `email` till den inloggade användarens adress
 * innan begränsaren läser fältet, och anropas i stället för `throttle:login`
 * på rutten. Det är samma begränsare som förut — samma namn, samma trösklar,
 * samma två nycklar, och samma rensning efter en lyckad inloggning
 * (App\Support\Auth\LoginRateLimiter::clear()) — men identiteten kommer ur
 * sessionen och aldrig ur kroppen.
 *
 * **Nyckeln är användarens e-postadress och inte hennes id**, för att
 * lösenordsbyten och inloggningar för samma konto ska dela på hinken. Att
 * gissa det nuvarande lösenordet i bytesformuläret är samma angrepp som att
 * gissa det vid inloggning och ska räknas mot samma gräns
 * ([[ADR-0011 Autentisering]]).
 *
 * **Anropet till `ThrottleRequests::handle()` är avsiktligt och har exakt
 * tre argument**, av samma skäl som i syskonklassen: att anropa den direkt
 * garanterar ordningen, för `email` måste vara satt innan begränsaren läser
 * det. `throttle:login` efter det här middlewaret hade gett samma sak — men
 * bara så länge ingen ändrar ruttstackens ordning.
 *
 * Rutten ligger bakom `auth` (routes/web.php), som kör före ruttens eget
 * middleware, så `$request->user()` finns när det här körs. Är den ändå inte
 * en App\Models\User lämnas requesten orörd; begränsarens nycklar blir då vad
 * de blir, och anropet avvisas ändå av `auth`.
 */
final class BindsPasswordChangeThrottleToUser
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $request->merge(['email' => $user->email]);
        }

        return $this->throttle->handle($request, $next, LoginRateLimiter::NAME);
    }
}
