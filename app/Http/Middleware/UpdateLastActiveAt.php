<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * Autentisering byggs inte här (issue 4). `saveQuietly()` undviker att
 * trigga modellhändelser för en så frekvent, sidoeffektsfri skrivning.
 */
class UpdateLastActiveAt
{
    /**
     * Hur gammalt värdet måste vara för att skrivas om. Ett dygn, för att
     * livscykelns gränser är månader — se [[Planer och kvoter]] §
     * Kontolivscykel.
     */
    private const WRITE_INTERVAL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->shouldWrite($user)) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    /**
     * Skriver bara när kolumnen är NULL eller äldre än ett dygn. Läser
     * `last_active_at` ur modellens redan laddade attribut — en `fresh()`
     * eller en `exists`-fråga här hade kostat en fråga per anrop, vilket är
     * precis vad strypningen finns för att undvika.
     *
     * Attributet läses med `getAttribute()` och inte som en egenskap: casten
     * säger att värdet alltid är ett `Carbon` (kolumnen är NOT NULL), så en
     * egenskapsläsning får den statiska analysen att avvisa NULL-grenen som
     * död kod. Den är inte död — en modell vars attribut ännu inte laddats
     * har inget värde alls.
     */
    private function shouldWrite(User $user): bool
    {
        $lastActiveAt = $user->getAttribute('last_active_at');

        return ! $lastActiveAt instanceof Carbon
            || $lastActiveAt->lt(now()->subHours(self::WRITE_INTERVAL_HOURS));
    }
}
