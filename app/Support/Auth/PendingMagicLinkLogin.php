<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Väntetillståndet mellan steg ett och steg två i webbens magic
 * link-inloggning — issue 80 § Beslut 2.
 *
 * En webbläsare kan inte skicka engångskoden i samma request som klicket på
 * mejllänken. `MagicLinkBroker::consume()` körs därför som förut — token är
 * förbrukad efter klicket — och i stället för att logga in läggs det här
 * tillståndet i sessionen: användarens id och en tidsstämpel, ingenting mer.
 * Sidan som renderas frågar efter koden, och först en giltig kod loggar in.
 *
 * **Tillståndet bor i sessionen och ingen annanstans.** Ingen
 * användaridentitet i URL:en, ingen ny kolumn, ingen ny tabell — se issue 80
 * § Beslut 2 och omfångsrutan ("Ingen migrering och ingen ny kolumn").
 *
 * **Rensas när det används, när det går ut, och när något annat loggas in.**
 * De två första sköter den här klassen: `clear()` efter en lyckad inloggning
 * (så att ett använt tillstånd inte kan spelas upp igen), och `user()` som
 * rensar ett utgånget tillstånd på vägen ut. Det tredje fallet kräver ingen
 * egen mekanism, och det är värt att skriva ner varför:
 *
 * - Steg två-rutten ligger i `guest`-gruppen (routes/web.php). En session som
 *   loggat in på något annat sätt når därför aldrig kontrollern — `guest`
 *   omdirigerar den till /dashboard. Ett kvarglömt tillstånd är oanvändbart
 *   i samma stund någon är inloggad, oavsett vad som står i sessionen.
 * - En utloggning (`AuthenticatedSessionController::destroy()`) kör
 *   `session()->invalidate()`, som tömmer hela sessionspåsen — tillståndet
 *   dör med den.
 *
 * Ett tillstånd som ligger kvar i en inloggad session är alltså inte en väg
 * in, och det är borta så snart sessionen byter ägare igen. Att binda
 * tillståndet till sessionens id hade sett ut som skydd men inte varit det:
 * id:t byts vid varje förfrågan i testmiljön (array-drivrutinen utan
 * cookies), så bindningen hade fällt giltiga försök där utan att tillföra
 * något i produktion.
 *
 * **Utgår efter fem minuter** (`TTL_MINUTES`, issue 80 § Beslut 2): kort nog
 * att ett kvarglömt tillstånd inte är en väg in, långt nog att hinna hämta
 * koden ur appen.
 */
final class PendingMagicLinkLogin
{
    /**
     * Livstiden för väntetillståndet, se klassens docblock och issue 80
     * § Beslut 2.
     */
    public const TTL_MINUTES = 5;

    private const SESSION_KEY = 'auth.magic-link-pending';

    /**
     * Lägger väntetillståndet för `$user` i `$request`s session, och skriver
     * över ett eventuellt tidigare försök — ett nytt klick på en ny länk är
     * ett nytt försök, inte två parallella.
     */
    public static function start(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * Användaren som väntar på att ange sin kod, eller `null` när inget
     * giltigt väntetillstånd finns — inget startat, utgånget, eller
     * tillhörande ett konto som hunnit raderas.
     */
    public static function user(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || now()->getTimestamp() > (int) ($pending['expires_at'] ?? 0)) {
            self::clear($request);

            return null;
        }

        $user = User::query()->whereKey($pending['user_id'] ?? null)->first();

        if (! $user instanceof User) {
            self::clear($request);

            return null;
        }

        return $user;
    }

    /**
     * Rensar väntetillståndet — efter en lyckad inloggning, så att det
     * aldrig kan spelas upp igen.
     *
     * Ett misslyckat kodförsök rensar INTE: koden får skrivas fel och göras
     * om inom de fem minuterna, precis som i inloggningsformuläret.
     * Takgränsen på försöken är `throttle:login` på rutten, se
     * routes/web.php.
     */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
