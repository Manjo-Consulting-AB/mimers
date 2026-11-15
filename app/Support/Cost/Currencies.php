<?php

namespace App\Support\Cost;

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
 */
final class Currencies
{
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
}
