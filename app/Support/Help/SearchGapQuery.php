<?php

namespace App\Support\Help;

/**
 * Avidentifierar en sökfråga innan den sparas som en fråga utan träff —
 * M82 · issue 470, [[ADR-0094 Hjälpcentret]] § 8.
 *
 * Frågan är användarens egen text, så den får bara sparas i en form som inte
 * pekar ut någon. Stegen är, i den här ordningen:
 *
 * 1. Varje ord som innehåller `@` tas bort. En e-postadress är det mest
 *    sannolika personliga som kan hamna i en sökfråga, och den kan stå mitt i
 *    ett ord (`anna@exempel.se`).
 * 2. `HelpSearch::normalize` — samma normalisering som sökningen använder, så
 *    att det sparade värdet är den form sökningen faktiskt såg.
 * 3. Varje följd av siffror blir `#`. Ett personnummer, ett kontonummer eller
 *    ett telefonnummer är då bara en form, inte ett värde.
 * 4. Ord längre än 30 tecken tas bort — en inklistrad rad eller en token är
 *    ingen sökfråga.
 * 5. Resultatet kapas till 60 tecken på ordgräns, samma bredd som kolumnen.
 *
 * Är mindre än tre tecken kvar efteråt sparas ingenting: `null` betyder att
 * frågan inte bär något att lära av, och `sanitize` är den enda vägen till
 * tabellen.
 */
final class SearchGapQuery
{
    /** Ord längre än så här tas bort innan frågan sparas. */
    public const MAX_WORD_LENGTH = 30;

    /** Frågan kapas till så många tecken — kolumnens bredd. */
    public const MAX_LENGTH = 60;

    /** Kortare än så här efter avidentifieringen sparas ingenting. */
    public const MIN_LENGTH = 3;

    public static function sanitize(string $query): ?string
    {
        // 1. Ord som bär en @ — en e-postadress — tas bort helt.
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter(
            $words,
            static fn (string $word): bool => ! str_contains($word, '@'),
        );

        // 2. Samma normalisering som sökningen.
        $normalized = HelpSearch::normalize(implode(' ', $words));

        // 3. Sifferföljder blir ett tecken.
        $normalized = (string) preg_replace('/\p{N}+/u', '#', $normalized);

        // 4. Ord som är för långa för att vara en fråga tas bort.
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) <= self::MAX_WORD_LENGTH,
        );

        $result = trim(implode(' ', $words));

        // 5. Kapa på ordgräns. Ordningen ovan gör att en ensam kvarvarande
        //    "rad" aldrig är längre än 30 tecken, så det finns ett blanksteg
        //    att kapa vid när resultatet är för långt.
        if (mb_strlen($result) > self::MAX_LENGTH) {
            $cut = mb_substr($result, 0, self::MAX_LENGTH);
            $lastSpace = mb_strrpos($cut, ' ');
            $result = $lastSpace === false ? $cut : mb_substr($cut, 0, $lastSpace);
            $result = trim($result);
        }

        return mb_strlen($result) < self::MIN_LENGTH ? null : $result;
    }
}
