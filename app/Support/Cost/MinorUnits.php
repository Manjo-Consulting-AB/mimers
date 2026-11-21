<?php

namespace App\Support\Cost;

use App\Exceptions\Api\ApiException;

/**
 * Tolkar ett belopp som en användare skrivit i HUVUDENHET — en sträng som
 * "1200,50" — till heltalet i MINSTA enhet (120050) som cost_entry.amount
 * lagrar, se issue 45a § Beslut 4 och [[ADR-0016 Kostnadsregistrering]].
 *
 * Hela poängen med klassen är att omvandlingen aldrig förlorar ett öre:
 * inget `floatval`, ingen `(float)`, ingen `round()` — bara strängoperationer
 * och heltalsaritmetik. Decimaldelen fylls ut med nollor till valutans
 * exponent och konkateneras ("1200" . "50"), och `(int)` görs först på
 * slutet. Fler decimaler än valutan tillåter AVVISAS med `cost.amount_decimals`
 * — aldrig avrundning, aldrig trunkering.
 *
 * Komma och punkt är samma tecken: "1200,50" och "1200.50" ger identiskt
 * resultat. Decimaler krävs inte — "1200" är 1 200,00 och blir 120000.
 *
 * Formatfel (och belopp som inte ryms i BIGINT, siffersträngen längre än 18
 * tecken) kastas som ApiException `cost.amount_invalid` — de två felkoderna
 * kastas härifrån, inte ur valideringshöljet, för ValidationErrorMapper ger
 * `validation.<regel>` med tom data för egna regelobjekt och klienten skulle
 * inte få veta VILKEN gräns som slog i (§ Beslut 5). Samma val som
 * `loan.already_open` (issue 76 § Beslut 4).
 *
 * `format()` går motsatt väg: heltalet i minsta enhet skrivs tillbaka som en
 * decimalsträng i huvudenhet, med valutans decimaler. Den finns för att
 * MCP-verktygen ska tala samma format som appen i BÅDA riktningarna.
 */
final class MinorUnits
{
    /**
     * Tolkar $amount (huvudenhet) till minsta enhet för $currency.
     *
     * $currency måste vara versaler (requestlagren normaliserar); en kod som
     * inte står i config/kostnader.php får `default_minor_units` (§ Beslut 6).
     */
    public static function parse(string $amount, string $currency): int
    {
        $amount = trim($amount);

        // Valfritt `-`, en eller flera siffror, valfritt en decimalpunkt
        // ELLER ett decimalkomma följt av en eller flera siffror. Inga
        // tusentalsavskiljare, inga mellanslag inuti, inget `+`, ingen
        // exponentform (§ Beslut 4).
        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $amount) !== 1) {
            throw self::invalid();
        }

        $negative = str_starts_with($amount, '-');
        $unsigned = $negative ? substr($amount, 1) : $amount;

        $separator = strpbrk($unsigned, '.,');

        if ($separator === false) {
            $whole = $unsigned;
            $fraction = '';
        } else {
            $whole = substr($unsigned, 0, -strlen($separator));
            $fraction = substr($separator, 1);
        }

        $decimals = self::minorUnits($currency);

        if (strlen($fraction) > $decimals) {
            throw ApiException::make('cost.amount_decimals', [
                'currency' => $currency,
                'max_decimals' => $decimals,
            ], 422);
        }

        $combined = $whole.str_pad($fraction, $decimals, '0');

        // Ska rymmas i BIGINT. Högst 18 siffror är alltid under gränsen
        // 9223372036854775807; en längre sträng ska falla på en felkod, inte
        // på ett databasfel (§ Beslut 4).
        if (strlen($combined) > 18) {
            throw self::invalid();
        }

        $value = (int) $combined;

        return $negative ? -$value : $value;
    }

    /**
     * Skriver heltalet i minsta enhet som en sträng i HUVUDENHET — inversen
     * till parse(). 10000 SEK blir "100.00", 100 JPY blir "100" och -350 SEK
     * blir "-3.50".
     *
     * Decimaltecknet är alltid punkt, aldrig komma, och tusentalsavskiljare
     * skrivs aldrig: strängen ska kunna skickas rakt tillbaka till parse(),
     * som tar både punkt och komma men inget annat. Antalet decimaler är
     * valutans eget — samma config som parse() läser — så ett belopp i JPY
     * får inga decimaler alls.
     *
     * Tecknet läses ur strängen och inte genom att negera talet: `(string)`
     * av PHP_INT_MIN klarar sig där en negation svämmar över, och beloppet
     * är aldrig mer än en BIGINT.
     */
    public static function format(int $minor, string $currency): string
    {
        $decimals = self::minorUnits($currency);

        $negative = $minor < 0;
        $digits = ltrim((string) $minor, '-');

        if ($decimals === 0) {
            return ($negative ? '-' : '').$digits;
        }

        // Minst en siffra före decimaltecknet: 5 öre ska bli "0.05", inte
        // ".05", som parse() skulle avvisa.
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')
            .substr($digits, 0, -$decimals)
            .'.'.substr($digits, -$decimals);
    }

    private static function minorUnits(string $currency): int
    {
        return config('kostnader.minor_units.'.$currency, config('kostnader.default_minor_units', 2));
    }

    private static function invalid(): ApiException
    {
        return ApiException::make('cost.amount_invalid', [], 422);
    }
}
