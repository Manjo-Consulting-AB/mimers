<?php

namespace App\Support\Access;

use InvalidArgumentException;

/**
 * Behörighetsladdern, se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och
 * [[Konton och åtkomst]] § container_access.
 *
 * Fyra nivåer i stigande ordning, efter hur mycket BEFINTLIG information
 * nivån kan skada: `read` rör ingenting, `create` lägger bara till, `write`
 * ändrar det som redan står där, `delete` tar bort det.
 *
 * Ordningen definieras på ETT ställe — konstanten LADDER — och varje
 * jämförelse i systemet går genom den här klassen. Två uppräkningar av
 * ordningen kan glida isär på samma sätt som två formuleringar av "giltig
 * access" (issue 9a § Beslut 8), och den här skulle glida isär tyst.
 *
 * Laddern bor i PHP, aldrig i SQL: `level` är VARCHAR och `'delete' >=
 * 'write'` är falskt i varje kollation som finns. En `ORDER BY level` eller
 * en `where('level', '>=', ...)` någonstans i systemet är alltid en bugg —
 * använd atOrAbove() och `whereIn` i stället, se § Beslut 2.
 */
final class AccessLevel
{
    public const READ = 'read';

    public const CREATE = 'create';

    public const WRITE = 'write';

    public const DELETE = 'delete';

    /** @var list<string> Stigande. Ordningen HÄR är laddern. */
    public const LADDER = [self::READ, self::CREATE, self::WRITE, self::DELETE];

    /**
     * Nivåns plats i laddern, 0 för den lägsta. Högre rang = mer
     * förstörande.
     *
     * Ett okänt värde är ett PROGRAMMERINGSfel, inte ett användarfel:
     * databasens CHECK-villkor och Request-valideringen är det som ska
     * hindra värdet från att existera, och kommer det ändå hit är något
     * trasigt och ska höras — därför ett undantag och inte en tyst nolla.
     *
     * @throws InvalidArgumentException
     */
    public static function rank(string $level): int
    {
        $rank = array_search($level, self::LADDER, true);

        if ($rank === false) {
            throw new InvalidArgumentException("Okänd åtkomstnivå: {$level}");
        }

        return $rank;
    }

    /**
     * Är $level på eller ovanför $minimum i laddern? Det här är `>=` för
     * behörighetsnivåer, och den enda formen av den jämförelsen som får
     * finnas.
     */
    public static function atLeast(string $level, string $minimum): bool
    {
        return self::rank($level) >= self::rank($minimum);
    }

    /**
     * Den högsta av två nivåer. Ordningsoberoende — ADR-0028 § Beslut regel
     * 4 ("högsta nivån vinner") kräver det, annars blir resultatet beroende
     * av i vilken ordning två grants råkade skapas.
     */
    public static function max(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }

    /**
     * $minimum och allt ovanför — det som ska in i en `whereIn('level', …)`
     * för att uttrycka "minst den här nivån".
     *
     * @return list<string>
     */
    public static function atOrAbove(string $minimum): array
    {
        return array_slice(self::LADDER, self::rank($minimum));
    }
}
