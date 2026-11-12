<?php

namespace App\Support\Frontend;

/**
 * Panelerna på containerns översikt — nycklar, förvald ordning och förvald
 * bredd, se [[ADR-0073 Översiktens ordning]] § 3. Den enda listan; ingen
 * tabell håller panelerna. Följer formen i App\Support\Tips.
 */
final class OverviewPanels
{
    public const NARROW = 'narrow';

    public const WIDE = 'wide';

    /**
     * Nyckel => förvald bredd, i förvald ordning.
     *
     * @var array<string, string>
     */
    public const PANELS = [
        'tasks' => self::NARROW,
        'costs' => self::NARROW,
        'details' => self::NARROW,
        'items' => self::WIDE,
        'activity' => self::NARROW,
    ];

    /**
     * @var list<string>
     */
    public const WIDTHS = [self::NARROW, self::WIDE];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PANELS);
    }

    public static function knows(string $key): bool
    {
        return array_key_exists($key, self::PANELS);
    }

    public static function defaultWidth(string $key): string
    {
        return self::PANELS[$key];
    }
}
