<?php

namespace App\Support\Auth;

use App\Models\User;
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
 * **Hemlighetslängd — 16 tecken, inte bibliotekets standard 32.** Se
 * [[Konton och åtkomst]] § user: kolumnen är `VARBINARY(255)`
 * (`binary('totp_secret', 255)` i create_user_table-migrationen, issue 3
 * — rörs inte här, se issue #19 § Omfång "Inga nya migrationer").
 * Laravels `encrypted`-cast lägger på IV, MAC och en JSON-kuvert innan
 * base64: uppmätt med `Crypt::encryptString()` ger en 32-tecken hemlighet
 * (bibliotekets standardlängd) ett 256 bytes chiffertext — **en byte för
 * mycket** för kolumnen, medan alla längder upp till 31 tecken ryms
 * bekvämt (228 bytes). SQLite (testsviten, phpunit.xml) tvingar inga
 * kolumnlängder alls, så det här är inget test i den här PR:en skulle
 * fånga av sig själv — MySQL i produktion (strict mode) skulle avvisa
 * eller trunkera en 32-tecken hemlighet.
 *
 * Biblioteket kräver dessutom (`Base32::checkGoogleAuthenticatorCompatibility()`,
 * påslagen som standard) att hemlighetens teckenlängd är en tvåpotens,
 * så det enda alternativet under 32 är 16 — vilket också är bibliotekets
 * egen lägstanivå (`Base32::checkIsBigEnough()`, 128 bitar). Se PR:ens
 * "Frågor och antaganden": det här är min lösning på en kolumn som är
 * för smal för bibliotekets standardlängd, inte ett dokumenterat beslut
 * — flaggat för Tony, med en framtida migration (bredare kolumn) som
 * alternativ om 32 tecken önskas.
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
     * Se klassdokumentationen ovan — 16, inte Google2FA::generateSecretKey()s
     * egen standard 32, för att chiffertexten ska rymmas i den befintliga
     * VARBINARY(255)-kolumnen.
     */
    private const SECRET_LENGTH = 16;

    /**
     * Klockdriftfönster, se klassdokumentationen ovan.
     */
    private const WINDOW = 1;

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
     * @throws TotpInvalidException Ingen aktiv hemlighet, eller koden
     *                              stämmer inte.
     */
    public static function disable(User $user, string $code): void
    {
        self::verifyOrFail($user, $code);

        $user->totp_secret = null;
        $user->totp_confirmed_at = null;
        $user->save();
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
