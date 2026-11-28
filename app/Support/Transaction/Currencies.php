<?php

namespace App\Support\Transaction;

use ResourceBundle;

/**
 * De valutakoder servern godtar, se [[ADR-0078 Valutor ur ISO 4217]] § 1 och
 * issue 1252.
 *
 * Listan är underhållsorganisationen SIX:s List One över AKTIVA ISO
 * 4217-koder i utgåvan 2025-03-31 — den som införde `XCG` för Curaçao och
 * Sint Maarten. Den innehåller därför varken historiska koder (`DEM`, `FRF`,
 * `HRK`, `ZWL`, …) eller fond- och specialkoder (`XAU`, `XDR`, `XTS`, `XXX`,
 * `CLF`, `USN`, …). `ANG` står kvar eftersom den fanns i listan vid det
 * datumet; den fasas ut till förmån för `XCG`.
 *
 * Att listan är en konstant och inte hämtas vid körning är ett medvetet val:
 * en nätverksförfrågan i en valideringsregel hade gjort ett API-anrop
 * beroende av en extern tjänst, och en lista som ändras under fötterna på en
 * release hade gjort samma betalning giltig i går och ogiltig i dag.
 * Uppdatera konstanten när ISO publicerar en ny utgåva — det är en
 * kodändring, inte en konfigurationsändring.
 *
 * Koderna är versaler och sorterade. Listan på ett ställe är poängen:
 * valideringen i #1253, sökrutan och containerns urval läser samma klass.
 *
 * **Namnen kommer ur ICU** (`intl`, `ICUDATA-curr`) på användarens språk, med
 * koden som reserv när bundlen saknas eller koden inte har ett namn där. Att
 * namnen inte ligger i `lang/` är med flit: de är ISO:s och CLDR:s data, inte
 * vår copy, och en katalog per språk hade gjort 180 rader att underhålla för
 * hand. Uppslaget memoiseras per locale — `options()` frågar efter varje kod,
 * och en bundle per kod hade varit 180 uppslag per sidladdning.
 */
final class Currencies
{
    /**
     * Namnen per locale, lästa en gång per process.
     *
     * @var array<string, array<string, string>>
     */
    private static array $names = [];

    /**
     * Aktiva ISO 4217-koder, sorterade.
     *
     * @var list<string>
     */
    private const CODES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD',
        'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK',
        'DJF', 'DKK', 'DOP', 'DZD',
        'EGP', 'ERN', 'ETB', 'EUR',
        'FJD', 'FKP',
        'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD',
        'HKD', 'HNL', 'HTG', 'HUF',
        'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK',
        'JMD', 'JOD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT',
        'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD',
        'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR',
        'MWK', 'MXN', 'MYR', 'MZN',
        'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD',
        'OMR',
        'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR',
        'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD',
        'SSP', 'STN', 'SVC', 'SYP', 'SZL',
        'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS',
        'UAH', 'UGX', 'USD', 'UYU', 'UZS',
        'VED', 'VES', 'VND', 'VUV',
        'WST',
        'XAF', 'XCD', 'XCG', 'XOF', 'XPF',
        'YER',
        'ZAR', 'ZMW', 'ZWG',
    ];

    /**
     * Alla godtagna koder, sorterade.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::CODES;
    }

    /**
     * Kodens namn på $locale, eller koden själv när ICU inte har något namn.
     *
     * Koden normaliseras till versaler så att anroparen kan skicka det värde
     * den har — valideringen har redan versaliserat, men en läsare av API:t
     * eller en testare ska inte behöva känna till den ordningen.
     */
    public static function name(string $code, string $locale): string
    {
        return self::namesFor($locale)[mb_strtoupper($code)] ?? mb_strtoupper($code);
    }

    /**
     * Hela listan som `{code, name}` på $locale, sorterad på kod.
     *
     * Formen är den sökrutan och `currencies`-proppen talar: fältet matchar
     * både kod och namn, och servern skickar därför båda. Sorteringen är
     * listans egen — koden är den stabila ordningen, och namnen skiljer sig
     * mellan språk.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function options(string $locale): array
    {
        return array_map(static fn (string $code): array => [
            'code' => $code,
            'name' => self::name($code, $locale),
        ], self::CODES);
    }

    /**
     * @return array<string, string>
     */
    private static function namesFor(string $locale): array
    {
        if (array_key_exists($locale, self::$names)) {
            return self::$names[$locale];
        }

        $names = self::readNames($locale);

        // `en_GB` bär ingen valutabundle i ICU; primärspråket gör det. Samma
        // reserv som `LocaleResolver` använder för katalogerna, och samma
        // svar för `en` och `en_GB` — men bara när den exakta strängen
        // saknar data.
        if ($names === [] && str_contains($locale, '_')) {
            $names = self::readNames(strstr($locale, '_', true) ?: $locale);
        }

        return self::$names[$locale] = $names;
    }

    /**
     * @return array<string, string>
     */
    private static function readNames(string $locale): array
    {
        if (! class_exists(ResourceBundle::class)) {
            return [];
        }

        try {
            $currencies = ResourceBundle::create($locale, 'ICUDATA-curr')?->get('Currencies');
        } catch (\Throwable) {
            return [];
        }

        if (! $currencies instanceof ResourceBundle) {
            return [];
        }

        $names = [];

        foreach (self::CODES as $code) {
            $entry = $currencies->get($code);
            $name = $entry instanceof ResourceBundle ? $entry->get(1) : null;

            if (is_string($name) && $name !== '') {
                $names[$code] = $name;
            }
        }

        return $names;
    }
}
