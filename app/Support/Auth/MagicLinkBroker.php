<?php

namespace App\Support\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Utfärdar och löser in magic link-tokens, se issue 5 och
 * [[ADR-0011 Autentisering]] § Konsekvenser. Delas av webbens och API:ets
 * kontroller (App\Http\Controllers\Auth\MagicLink*, App\Http\Controllers\Api\Auth\MagicLink*)
 * via de delade FormRequests i App\Http\Requests\Auth, samma mönster som
 * App\Http\Requests\Auth\LoginRequest delar `authenticate()` mellan ytorna.
 *
 * **Beslut 1 — aldrig i klartext.** `issue()` genererar slumpen med
 * `Str::random()`, skickar den i mejlets länk och sparar bara
 * `hash('sha256', ...)` av den. En SHA-256-hex av en 64-tecken slump har
 * ingen rimlig kollisionsrisk, och uppslaget nedan sker mot hashen —
 * samma teknik som Sanctums egna personal access tokens
 * (NewAccessToken::hashedToken()). "Jämför i konstant tid" (issue #18 §
 * Beslut 1) är själva poängen med att hasha innan uppslaget: en
 * DB-likhetskontroll av en hash läcker inget om den ohashade slumpen,
 * till skillnad från att jämföra klartextlösenord tecken för tecken.
 *
 * **Beslut 2 — bunden till e-postadressen.** `consume()` slår upp raden
 * enbart på `token_hash` och jämför sedan `email`-kolumnen mot den
 * inskickade adressen som ett eget steg — inte som en del av
 * uppslagsvillkoret. Ett token utfärdat för alice@… kan alltså aldrig
 * logga in bob@…, även om någon skulle gissa rätt hash.
 *
 * **Beslut 3 — engångs och kortlivad.** `TTL_MINUTES = 15`: tillräckligt
 * för att hinna växla till e-postklienten och klicka länken, kort nog att
 * ett läckt mejl (delat inkorgskonto, en skärmdump, en spamfilter-förhandsvisning
 * som öppnar länken) inte förblir en giltig inloggning länge — se PR:ens
 * "Frågor och antaganden" för avvägningen. Engångsanvändning görs med en
 * villkorad UPDATE (`whereNull('used_at')`), atomär i sig själv utan en
 * explicit transaktion — två samtidiga förbrukningsförsök av samma token
 * kan aldrig båda lyckas, för databasen serialiserar UPDATE-satser mot
 * samma rad.
 *
 * **Beslut 4 — ingen egen begränsare.** `issue()` rate-limitas inte här;
 * `throttle:'.LoginRateLimiter::NAME` sitter på rutterna
 * (routes/web.php, routes/api.php) precis som lösenordsinloggningen, se
 * App\Providers\AppServiceProvider::configureLoginRateLimiting() som
 * uttryckligen namnger magic link som en tilltänkt återanvändare.
 * `LoginRateLimiter::clear()` anropas **inte** här — varken vid en lyckad
 * `issue()` eller `consume()`. För `issue()` är det hela poängen: att bara
 * rensa när en användare faktiskt hittades vore i sig en sidokanal som
 * avslöjar utfallet, tvärtemot beslut 6 — svaret ska vara identiskt för en
 * adress som finns och en som inte finns.
 *
 * **Ändrat i issue 80:** konsumtionsrutterna är sedan den issuen
 * `throttle:login`-begränsade också (kodförsöket i steg två är annars en
 * gissningsyta), och efter en SLUTFÖRD inloggning rensar kontrollerna
 * begränsaren — App\Http\Controllers\Auth\MagicLinkLoginController::completeLogin()
 * och App\Http\Controllers\Api\Auth\MagicLinkLoginController::store(). Det är
 * samma uppföljning som issue 7 gjorde för lösenordsinloggningen, och det
 * rör inte den här klassens metoder: en lyckad `consume()` säger redan
 * utfallet i sitt svar, så en rensning där läcker ingenting. `issue()`
 * förblir orensad, se stycket ovan.
 *
 * **Beslut 6 — röjer inte om adressen finns.** `issue()` returnerar tyst
 * (ingen rad skapas, inget mejl skickas) när ingen användare har adressen.
 * Anropande kontroller svarar identiskt oavsett — se
 * App\Http\Controllers\Auth\MagicLinkRequestController och dess
 * API-motsvarighet, som aldrig grenar på returvärdet.
 */
final class MagicLinkBroker
{
    /**
     * Storleksordningen 15 minuter, se beslut 3 och docblocken ovan.
     */
    public const TTL_MINUTES = 15;

    /**
     * Längden på den slump som skickas i länken (tecken, inte bytes) —
     * `Str::random()` hämtar sin entropi från `random_bytes()`, 64 tecken
     * ur ett 62-teckensalfabet ger gott om marginal mot gissning även utan
     * en begränsare på inlösenrutten (den begränsas inte, se beslut 4).
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Utfärdar ett nytt token för `$email` om, och bara om, en användare
     * med den adressen finns — se beslut 6. Skickar
     * App\Notifications\MagicLinkNotification till den användaren. Ingen
     * återkoppling till anroparen om vad som hände; se klassens docblock.
     */
    public static function issue(string $email): void
    {
        $email = self::normalise($email);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            return;
        }

        $raw = Str::random(self::TOKEN_LENGTH);

        MagicLinkToken::query()->create([
            'email' => $email,
            'token_hash' => self::hash($raw),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $user->notify(new MagicLinkNotification(self::url($email, $raw)));
    }

    /**
     * Prövar `$rawToken` för `$email` och returnerar användaren UTAN att
     * förbruka token — issue 80 · "En magic link går förbi bekräftad
     * tvåfaktor", § Beslut 3.
     *
     * Finns för att API:et måste kunna avgöra om kontot kräver en
     * engångskod innan token brinner: ett anrop utan `code` ska svara
     * `auth.totp_required` och lämna token orörd, så klienten kan skicka om
     * SAMMA token med koden. Kontrollen är identisk med `consume()`s — det
     * är samma `findToken()` — så det finns bara en sanning om vad ett
     * giltigt token är; skillnaden är att den här metoden inte skriver.
     *
     * Att i stället slå upp användaren på e-postadressen före tokenkontrollen
     * vore en sidokanal: svaret skulle avslöja för vem som helst som känner
     * till en adress om kontot har tvåfaktor påslagen, utan att inneha
     * länken — precis den sidokanal issue 6b förbjuder på
     * lösenordsinloggningen genom att kontrollera lösenordet före koden.
     *
     * @throws MagicLinkInvalidException Token finns inte, hör till en
     *                                   annan adress, eller är redan
     *                                   förbrukat.
     * @throws MagicLinkExpiredException Token är i övrigt giltigt men
     *                                   `expires_at` har passerat.
     */
    public static function resolve(string $email, string $rawToken): User
    {
        $email = self::normalise($email);

        self::findToken($email, $rawToken);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            // Användaren hann tas bort mellan utfärdande och inlösen.
            // Inget i dokumentationen beskriver kontoradering ännu — se
            // PR:ens "Frågor och antaganden".
            throw new MagicLinkInvalidException;
        }

        return $user;
    }

    /**
     * Löser in `$rawToken` för `$email` och returnerar användaren.
     *
     * @throws MagicLinkInvalidException Token finns inte, hör till en
     *                                   annan adress, eller är redan
     *                                   förbrukat.
     * @throws MagicLinkExpiredException Token är i övrigt giltigt men
     *                                   `expires_at` har passerat.
     */
    public static function consume(string $email, string $rawToken): User
    {
        $email = self::normalise($email);

        $token = self::findToken($email, $rawToken);

        // Villkorad UPDATE, se klassens docblock om beslut 3 — förhindrar
        // att två samtidiga förfrågningar med samma token båda lyckas.
        $consumed = MagicLinkToken::query()
            ->whereKey($token->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($consumed !== 1) {
            throw new MagicLinkInvalidException;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            // Användaren hann tas bort mellan utfärdande och inlösen.
            // Inget i dokumentationen beskriver kontoradering ännu — se
            // PR:ens "Frågor och antaganden".
            throw new MagicLinkInvalidException;
        }

        return $user;
    }

    /**
     * Raden bakom ett giltigt token, med klassens alla villkor prövade —
     * det enda stället de bor på. Skriver ingenting; `consume()` gör
     * förbrukningen efteråt.
     *
     * @throws MagicLinkInvalidException
     * @throws MagicLinkExpiredException
     */
    private static function findToken(string $email, string $rawToken): MagicLinkToken
    {
        $token = MagicLinkToken::query()
            ->where('token_hash', self::hash($rawToken))
            ->first();

        // Beslut 2: adressen jämförs som ett eget steg, inte bara som en
        // del av uppslaget ovan.
        if (! $token instanceof MagicLinkToken || $token->email !== $email) {
            throw new MagicLinkInvalidException;
        }

        if ($token->isUsed()) {
            throw new MagicLinkInvalidException;
        }

        if ($token->isExpired()) {
            throw new MagicLinkExpiredException;
        }

        return $token;
    }

    private static function normalise(string $email): string
    {
        return mb_strtolower($email);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Absolut länk till konsumtionsrutten, se issue #18 § Att se upp med:
     * "Länken måste vara absolut och korrekt ... Bygg inte egen
     * värdnamnshantering." `route()` respekterar redan `APP_URL` och den
     * åtstramade `trustProxies` (bootstrap/app.php, issue 4) — ingen egen
     * hostname-logik läggs till här.
     */
    private static function url(string $email, string $rawToken): string
    {
        return URL::route('magic-link.consume', [
            'email' => $email,
            'token' => $rawToken,
        ]);
    }
}
