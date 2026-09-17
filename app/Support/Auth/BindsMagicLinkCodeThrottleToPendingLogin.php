<?php

namespace App\Support\Auth;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takgränsen för steg två i webbens magic link-inloggning — issue 80
 * § Beslut 2, och kodgranskningsfyndet om att gränsen gick att kringgå.
 *
 * `throttle:login` (App\Providers\AppServiceProvider::configureLoginRateLimiting())
 * bygger sin kontonyckel av `$request->string('email')`. Rakt på den här
 * rutten hade det varit ett klientstyrt fält: den som har ett
 * väntetillstånd för offrets konto men saknar tvåfaktorsenheten kunde
 * skicka ett nytt påhittat `email` i varje försök och få en ny, orörd hink
 * på fem i minuten varje gång — det kontospecifika skyddet hade varit borta
 * och bara den generella IP-gränsen kvar.
 *
 * Det här middlewaret sätter `email` till det konto väntetillståndet pekar
 * ut innan begränsaren läser fältet, och anropas i stället för
 * `throttle:login` på rutten. Det är samma begränsare som förut — samma
 * namn, samma trösklar, samma två nycklar, och samma rensning efter en
 * lyckad inloggning (App\Support\Auth\LoginRateLimiter::clear()) — men
 * identiteten kommer ur sessionen och aldrig ur kroppen.
 *
 * **Anropet till `ThrottleRequests::handle()` är avsiktligt och har exakt
 * tre argument.** Det är samma väg in i ramverket som `throttle:<namn>` i en
 * ruttstack tar (se `func_num_args()`-villkoret i metoden), men att anropa
 * den direkt garanterar ordningen: det här middlewaret får inte hamna efter
 * begränsaren, för då läses fältet innan det är satt och nyckeln blir tom
 * för alla. `throttle:login` efter det här middlewaredelen hade gett samma
 * sak — men bara så länge ingen ändrar ruttstackens ordning.
 *
 * Saknas ett giltigt väntetillstånd lämnas requesten orörd; begränsarens
 * nycklar blir då vad de blir, och anropet avvisas ändå med 403 i
 * App\Http\Controllers\Auth\MagicLinkLoginController::store(). Ingen
 * inloggning hänger på fältet — det finns bara till för att räkna.
 */
final class BindsMagicLinkCodeThrottleToPendingLogin
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = PendingMagicLinkLogin::user($request);

        if ($user instanceof User) {
            $request->merge(['email' => $user->email]);
        }

        return $this->throttle->handle($request, $next, LoginRateLimiter::NAME);
    }
}
