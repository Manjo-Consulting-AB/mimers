<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uppdaterar `user.last_active_at` på varje autentiserat API-anrop, inte
 * bara inloggning. Se issue 3 · Konto och användare och
 * [[Konton och åtkomst]] § user, där kolumnen "driver livscykeln i
 * [[Planer och kvoter]]".
 *
 * Registrerad på hela `api`-middlewaregruppen (bootstrap/app.php) så att
 * varje framtida endpoint täcks automatiskt. Anropet är ett no-op när
 * requesten är oautentiserad — det är då upp till respektive rutt att
 * kräva `auth`.
 *
 * Autentisering byggs inte här (issue 4). `saveQuietly()` undviker att
 * trigga modellhändelser för en så frekvent, sidoeffektsfri skrivning.
 */
class UpdateLastActiveAt
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
