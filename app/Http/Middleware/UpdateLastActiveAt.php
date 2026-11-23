<?php

namespace App\Http\Middleware;

use App\Actions\User\RecordActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uppdaterar `user.last_active_at` på varje autentiserat anrop — i webben
 * och i API:et, inte bara vid inloggning. Se issue 3 · Konto och användare,
 * issue 141 och [[Konton och åtkomst]] § user, där kolumnen "driver
 * livscykeln i [[Planer och kvoter]]".
 *
 * Registrerad på både `web`- och `api`-middlewaregruppen (bootstrap/app.php)
 * så att varje framtida endpoint täcks automatiskt. I webbgruppen ligger den
 * efter sessionen, annars finns ingen `$request->user()` att läsa. Anropet
 * är ett no-op när requesten är oautentiserad — det är då upp till
 * respektive rutt att kräva `auth`.
 *
 * **Skrivningen är strypt till ett dygn.** Livscykeln räknar i månader, och
 * en UPDATE per sidvisning ger den ingenting. Kolumnen skrivs bara när den
 * är NULL eller äldre än ett dygn, och jämförelsen görs mot värdet som
 * redan är laddat på användarmodellen — ingen egen fråga. Ett anrop som
 * passerar strypningen lämnar det nya värdet i minnet, så nästa kontroll i
 * samma request är gratis.
 *
 * Själva skrivningen ligger i App\Actions\User\RecordActivity, som MCP-
 * verktygen också anropar. Autentisering byggs inte här (issue 4). `saveQuietly()` undviker att
 * trigga modellhändelser för en så frekvent, sidoeffektsfri skrivning.
 */
class UpdateLastActiveAt
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            app(RecordActivity::class)->handle($user);
        }

        return $next($request);
    }
}
