<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Genererar och bekräftar TOTP-hemligheter, se issue #19 och
 * [[ADR-0023 TOTP-bibliotek]]. Delas av webbens och API:ets kontroller
 * (App\Http\Controllers\Auth\TotpController,
 * App\Http\Controllers\Api\Auth\TotpController), samma
 * delnings-/översättningsmönster som App\Support\Auth\MagicLinkBroker:
 * den här klassen kastar egna, ytoberoende undantag
 * (TotpAlreadyConfirmedException, TotpInvalidException) som varje yta
 * översätter till sitt eget svar.
 *
 * **Beslut 1 — aldrig i klartext.** `generate()` sparar hemligheten via
 * `User::casts()`s `'totp_secret' => 'encrypted'` (Laravels egen
 * `encrypted`-cast, `APP_KEY` finns per miljö) — den här klassen vet
 * inget om kryptering, den sätter bara `$user->totp_secret` och sparar.
 *
 * **Hemlighetslängd — 32 tecken, bibliotekets egen standard.**
 * `Google2FA::generateSecretKey()`s default: 160 bitar, den nivå RFC 4226
 * § 4 R6 rekommenderar (minimikravet är 128). Se [[Konton och åtkomst]]
 * § user: kolumnen är `VARBINARY(512)` sedan
 * 2026_08_24_140000_widen_user_totp_secret_column.php (uppföljning efter
 * granskning av PR #36) — vidgad just för att rymma den här längden med
 * marginal. `create_user_table`-migrationens ursprungliga `VARBINARY(255)`
 * (issue 3) räckte inte: Laravels `encrypted`-cast lägger på IV, MAC och
 * ett JSON-kuvert innan base64, och en 32-tecken hemlighet krypterar
 * uppmätt (`Crypt::encryptString()`) till 256 bytes — en byte för mycket
 * för den gamla kolumnen. Se widen-migrationens docblock för hela
 * uträkningen och varför den nya bredden är 512, inte den minsta siffra
 * som räcker i dag. Sänk inte den här konstanten för att undvika en
 * migration — se tests/Feature/Auth/TotpAktiveringTest.php, som numera
 * bevisar båda hållen: att en genererad hemlighet är 32 tecken, och att
 * chiffertexten ändå ryms bekvämt.
 *
 * **Beslut 2 — läcker aldrig ut igen.** `generate()` returnerar
 * `otpauth://`-URI:n en gång, som anropet svarar med — den sparas
 * ingenstans i klartext, och `User` döljer redan `totp_secret` i
 * serialisering (`#[Hidden(['password_hash', 'totp_secret'])]`, issue 3).
 *
 * **Beslut 3 och 4 — aktivering och avstängning kräver samma bevis.**
 * `confirm()` och `disable()` verifierar båda koden mot Google2FA innan
 * något skrivs. `generate()` och `confirm()` vägrar dessutom om kontot
 * redan har en bekräftad TOTP (`TotpAlreadyConfirmedException`) — annars
 * skulle en kapad session kunna byta ut hemligheten och bekräfta sin
 * egen, helt utan det bevis `disable()` kräver.
 *
 * **Klockdriftfönster — 1 (bibliotekets egen standard), medvetet valt.**
 * `Google2FA::$window` accepterar giltiga koder `$window` steg (á 30
 * sekunder, `keyRegeneration`) före och efter den aktuella — alltså ±30
 * sekunder, en total godtagen lucka på ~90 sekunder (nuvarande period
 * plus en på var sida). Se [[ADR-0023 TOTP-bibliotek]] § Konsekvenser:
 * "Fönstret ska sättas medvetet och motiveras ... större fönster betyder
 * fler giltiga koder samtidigt." Satt uttryckligen här (inte bara lämnat
 * på standardvärdet) av två skäl: (1) det är samma marginal Google
 * Authenticator-appen själv förlitar sig på, gott om utrymme för normal
 * klockdrift och tiden det tar att läsa av och skriva in en kod, utan
 * att i onödan vidga fönstret av giltiga koder en gissare kan träffa;
 * (2) issue #19 bygger ingen förbrukad-tidslucka-spärr — det är 6b:s
 * ansvar (se ADR-0023 § Konsekvenser) — så ett bredare fönster här skulle
 * också öka hur många på varandra följande giltiga koder en och samma
 * hemlighet accepterar innan 6b:s repris-skydd finns på plats.
 *
 * **Ingen repris-spärr i den här issuen.** En förbrukad tidslucka går
 * att återanvända mot `confirm()`/`disable()` — se
 * [[ADR-0023 TOTP-bibliotek]] § Konsekvenser: det är uttryckligen 6b:s
 * ansvar ("en förbrukad tidslucka får inte gå att spela upp igen ... är
 * applikationens ansvar, inte bibliotekets"), inte något den här
 * aktiverings-/avstängningsissuen bygger.
 */
final class TotpBroker
{
    /**
     * Google2FA::generateSecretKey()s egen standard — se
     * klassdokumentationen ovan för varför den inte sänks.
     */
    private const SECRET_LENGTH = 32;

    /**
     * Klockdriftfönster, se klassdokumentationen ovan.
     */
    private const WINDOW = 1;

    /**
     * TTL för repris-spärrens cachepost — issue 6b · TOTP vid inloggning.
     * Se verifyLoginCode() nedan för varför cachen används i stället för
     * en ny kolumn. WINDOW (ovan) släpper igenom koder i totalt ~90
     * sekunder (nuvarande tidslucka plus en på var sida, 30 sekunder
     * styck); fem minuter ger marginal mot klockdrift utan att posten
     * ligger kvar på obestämd tid.
     */
    private const REPLAY_TTL_SECONDS = 300;

    /**
     * Genererar en ny hemlighet, sparar den (krypterad via `User`s cast)
     * och returnerar en `otpauth://`-URI för klienten att rendera som
     * QR-kod — se [[ADR-0023 TOTP-bibliotek]] § Beslut: "Ingen
     * QR-generering på servern."
     *
     * @throws TotpAlreadyConfirmedException Kontot har redan en bekräftad
     *                                       TOTP — se klassdokumentationen,
     *                                       beslut 3/4.
     */
    public static function generate(User $user): string
    {
        if ($user->totp_confirmed_at !== null) {
            throw new TotpAlreadyConfirmedException;
        }

        $engine = self::engine();

        $secret = $engine->generateSecretKey(self::SECRET_LENGTH);

        $user->totp_secret = $secret;
        $user->save();

        return $engine->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Bekräftar den senast genererade hemligheten med en kod från appen
     * och sätter `totp_confirmed_at`. Se klassdokumentationen, beslut 3:
     * att bara ha sparat en hemlighet räcker inte.
     *
     * @throws TotpAlreadyConfirmedException Kontot har redan en bekräftad
     *                                       TOTP — inget att bekräfta.
     * @throws TotpInvalidException Ingen hemlighet genererad, eller
     *                              koden stämmer inte.
     */
    public static function confirm(User $user, string $code): void
    {
        if ($user->totp_confirmed_at !== null) {
            throw new TotpAlreadyConfirmedException;
        }

        self::verifyOrFail($user, $code);

        $user->totp_confirmed_at = now();
        $user->save();
    }

    /**
     * Stänger av TOTP — kräver samma bevis (en giltig kod) som
     * aktivering, se klassdokumentationen, beslut 4.
     *
     * Raderar samtidigt kontots återställningskoder
     * (App\Support\Auth\RecoveryCodeBroker::purge(), issue 6c) — se den
     * klassens beslut 5 för varför de inte får ligga kvar: de vore annars
     * bara vilande, inte döda, och skulle leva upp igen vid en
     * ominskrivning. Båda skrivningarna i en transaktion, så en
     * avstängning aldrig kan lämna kontot utan TOTP men med kodarket kvar
     * i tabellen.
     *
     * @throws TotpInvalidException Ingen aktiv hemlighet, eller koden
     *                              stämmer inte.
     */
    public static function disable(User $user, string $code): void
    {
        self::verifyOrFail($user, $code);

        DB::transaction(function () use ($user): void {
            $user->totp_secret = null;
            $user->totp_confirmed_at = null;
            $user->save();

            RecoveryCodeBroker::purge($user);
        });
    }

    /**
     * Verifierar en kod vid inloggning — issue 6b · TOTP vid inloggning.
     * Till skillnad från `confirm()`/`disable()` ändrar den här metoden
     * inget lagrat tillstånd på kontot; den kontrollerar bara koden.
     * Anropas bara när kontot redan har en bekräftad TOTP — se
     * App\Http\Requests\Auth\LoginRequest::authenticate(), som är den
     * enda anroparen och som redan kontrollerat `totp_confirmed_at` innan
     * den här metoden nås.
     *
     * **Repris-skydd — applikationens ansvar, inte bibliotekets.** Se
     * [[ADR-0023 TOTP-bibliotek]] § Konsekvenser: "en förbrukad tidslucka
     * får inte gå att spela upp igen ... är applikationens ansvar, inte
     * bibliotekets", och issue 6b § Beslut som redan är fattade punkt 3.
     *
     * `Google2FA::verifyKeyNewer()` är byggd exakt för det här ("Useful
     * if you need to ensure that a single key cannot be used twice", se
     * vendor/pragmarx/google2fa/src/Google2FA.php) — given en tidigare
     * godkänd tidslucka (en heltalsräknare: unix-tid delat med 30, se
     * Google2FA::getTimestamp()) accepterar den bara en NYARE tidslucka.
     * Är den skickade koden samma tidslucka som senast, eller äldre,
     * avvisas den även om koden i sig är korrekt.
     *
     * **Lagring: cachen (databasdrivrutinen), ingen ny kolumn.** Issue
     * 6b:s omfångsruta utesluter uttryckligen nya migrationer. Samma
     * `cache`-tabell och -butik som App\Support\Auth\LoginRateLimiter
     * redan förlitar sig på för inloggningsbegränsningen (`CACHE_STORE`,
     * se .env) återanvänds här för att spara senast godkända tidslucka
     * per användare — en enda rad per konto, inte en serie. TTL:en
     * (`REPLAY_TTL_SECONDS`) är satt till fem minuter: godkännandefönstret
     * (`WINDOW`, se klassdokumentationen) släpper igenom koder i totalt
     * ~90 sekunder (nuvarande tidslucka plus en på var sida), så fem
     * minuter ger gott om marginal mot klockdrift utan att cachen växer
     * obegränsat för konton som bara loggar in TOTP en gång.
     *
     * **Fallgropen som motiverar `?? 0` nedan, inte `Cache::get()`s eget
     * null-default:** `Google2FA::findValidOTP()` returnerar den
     * matchade tidsluckan som `int` bara när `$oldTimestamp`-argumentet
     * INTE är `null` — är det `null` (Cache-nyckeln saknas, dvs. första
     * inloggningen) returnerar biblioteket i stället `true` (bool). Hade
     * den raden nedan skickat `Cache::get($key)`s null rakt in i
     * `verifyKeyNewer()`, hade den FÖRSTA lyckade verifieringen sparat
     * `true` — inte den verkliga tidsluckan — i cachen. Nästa
     * verifiering hade då jämfört mot `true` (kastas till `1` av PHP:s
     * `+`-operator), en tidslucka så långt tillbaka i tiden att den
     * aldrig utesluter något verkligt förfluten tidslucka, och samma kod
     * hade gått att spela upp igen direkt efter den allra första
     * inloggningen. `0` i stället för `null` håller `$oldTimestamp`
     * icke-null även första gången, så biblioteket alltid returnerar den
     * verkliga tidsluckan — se testet som bevisar just det här fallet,
     * inte bara den allmänna repris-spärren.
     *
     * @throws TotpInvalidException Ingen aktiv hemlighet, fel kod, eller
     *                              en tidslucka som redan är förbrukad.
     */
    public static function verifyLoginCode(User $user, string $code): void
    {
        $secret = $user->totp_secret;

        if (! is_string($secret) || $secret === '') {
            throw new TotpInvalidException;
        }

        $cacheKey = self::replayCacheKey($user);
        $lastAcceptedTimestamp = (int) (Cache::get($cacheKey) ?? 0);

        // hash_equals() i grunden, se verifyOrFail() ovan. $window skickas
        // uttryckligen (samma som self::engine() redan satte) för att
        // matcha verifyOrFail()s explicita anrop.
        $matchedTimestamp = self::engine()->verifyKeyNewer($secret, $code, $lastAcceptedTimestamp, self::WINDOW);

        if ($matchedTimestamp === false) {
            throw new TotpInvalidException;
        }

        Cache::put($cacheKey, $matchedTimestamp, self::REPLAY_TTL_SECONDS);
    }

    private static function replayCacheKey(User $user): string
    {
        return 'totp-last-accepted-timeslot:'.$user->id;
    }

    /**
     * @throws TotpInvalidException
     */
    private static function verifyOrFail(User $user, string $code): void
    {
        $secret = $user->totp_secret;

        if (! is_string($secret) || $secret === '') {
            throw new TotpInvalidException;
        }

        // Google2FA::verifyKey() jämför i konstant tid (hash_equals(),
        // se vendor/pragmarx/google2fa/src/Google2FA.php::findValidOTP())
        // — se [[ADR-0023 TOTP-bibliotek]] § Kontext.
        if (self::engine()->verifyKey($secret, $code, self::WINDOW) === false) {
            throw new TotpInvalidException;
        }
    }

    private static function engine(): Google2FA
    {
        $engine = new Google2FA;
        $engine->setWindow(self::WINDOW);

        return $engine;
    }
}
