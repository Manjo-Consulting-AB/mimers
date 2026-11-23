<?php

namespace App\Actions\Help;

use App\Support\Help\SearchGapQuery;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Räknar hjälpens anonyma mätning — M82 · issue 470,
 * [[ADR-0094 Hjälpcentret]] § 8 och [[ADR-0079 Övervakning]] § 4.
 *
 * Två tabeller: `help_event_daily` för artikelvisningar, sökningar, klick på
 * sökträffar och öppnade eller okända sammanhang, och
 * `help_search_gap_daily` för sökfrågor som gav noll träffar. Ingen rad bär
 * användare, konto, IP eller session — raden är en summa per dag.
 *
 * **Uppräkningen är en enda upsert**, i samma form som
 * App\Actions\Usage\CountFeatureUse::increment(): det unika indexet pekar ut
 * raden och `count` räknas upp i databasen. Det gör att två samtidiga
 * visningar inte kan skriva över varandra.
 *
 * **Ett fel loggas och sväljs.** Mätningen får aldrig fälla sidan — en trasig
 * tabell ska ge en rad i loggen, inte ett 500-svar. Därför omsluter båda
 * metoderna sitt arbete i en try/catch.
 *
 * **Taken.** Högst `help.search_gap_daily_limit` nya frågor per dag i
 * `help_search_gap_daily`, och lika många distinkta sammanhang per dag i
 * `context_unknown`. En rad som redan finns för dagen räknas upp även när
 * taket är nått; det är bara en ny rad som stoppas. På så vis kan ingen fylla
 * tabellerna genom att spruta frågor, men de frågor som redan finns behåller
 * sitt värde.
 *
 * Frågan avidentifieras av App\Support\Help\SearchGapQuery innan den når
 * tabellen; `sanitize` svarar `null` för en fråga som inte bär något att lära
 * av, och då sparas ingenting.
 */
class CountHelpEvent
{
    public const ARTICLE_VIEW = 'article_view';

    public const SEARCH = 'search';

    public const SEARCH_CLICK = 'search_click';

    public const CONTEXT_OPEN = 'context_open';

    public const CONTEXT_UNKNOWN = 'context_unknown';

    /**
     * Kolumnen `context` är VARCHAR(100), men rutten för sammanhang har ingen
     * längdgräns. Kapa därför innan raden byggs: en för lång adress ska inte
     * fälla upserten och därmed spamma loggen.
     */
    public const CONTEXT_MAX_LENGTH = 100;

    /**
     * Räknar upp en händelse. `$articleId` fylls i när händelsen gäller en
     * artikel, `$context` vid `context_open` och `context_unknown`; annars är
     * de tomma strängar, som det unika indexet kräver.
     */
    public function event(string $event, string $articleId = '', string $context = ''): void
    {
        try {
            $context = mb_substr($context, 0, self::CONTEXT_MAX_LENGTH);

            $now = now();
            $date = $now->toDateString();

            // Taket gäller bara när en ny rad skulle läggas till; en rad som
            // redan finns räknas upp.
            if ($event === self::CONTEXT_UNKNOWN && $this->isNewRowOverCap(
                'help_event_daily',
                $date,
                ['event' => self::CONTEXT_UNKNOWN, 'context' => $context],
                (int) config('help.unknown_context_daily_limit'),
            )) {
                return;
            }

            self::increment('help_event_daily', [
                'date' => $date,
                'event' => $event,
                'article_id' => $articleId,
                'context' => $context,
                'count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['date', 'event', 'article_id', 'context'], $now);
        } catch (Throwable $e) {
            Log::warning('Uppräkningen av hjälphändelse misslyckades.', ['exception' => $e::class]);
        }
    }

    /**
     * Räknar en sökfråga som gav noll träffar. Frågan avidentifieras först;
     * blir ingenting kvar sparas den inte.
     */
    public function searchGap(string $query): void
    {
        try {
            $sanitized = SearchGapQuery::sanitize($query);

            if ($sanitized === null) {
                return;
            }

            $now = now();
            $date = $now->toDateString();

            if ($this->isNewRowOverCap(
                'help_search_gap_daily',
                $date,
                ['query' => $sanitized],
                (int) config('help.search_gap_daily_limit'),
            )) {
                return;
            }

            self::increment('help_search_gap_daily', [
                'date' => $date,
                'query' => $sanitized,
                'count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['date', 'query'], $now);
        } catch (Throwable $e) {
            Log::warning('Uppräkningen av sökfråga utan träff misslyckades.', ['exception' => $e::class]);
        }
    }

    /**
     * Sant när `$match` inte redan finns för dagen och antalet rader för dagen
     * nått taket — alltså när en ny rad ska stoppas. En rad som finns räknas
     * upp och stoppas aldrig.
     *
     * @param  array<string, string>  $match
     */
    private function isNewRowOverCap(string $table, string $date, array $match, int $limit): bool
    {
        $existing = DB::table($table)->where('date', $date);

        foreach ($match as $column => $value) {
            $existing->where($column, $value);
        }

        if ($existing->exists()) {
            return false;
        }

        // Taket räknar raderna för dagen som hör till samma slag: alla rader
        // i frågetabellen, och `context_unknown`-raderna i händelsetabellen.
        // Varje distinkt fråga eller sammanhang är en rad, så radantalet är
        // antalet distinkta värden.
        $rows = DB::table($table)->where('date', $date);

        if (array_key_exists('event', $match)) {
            $rows->where('event', $match['event']);
        }

        return $rows->count() >= $limit;
    }

    /**
     * Den enda upserten: en rad per nyckel, räknad upp i databasen.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $uniqueBy
     */
    private static function increment(string $table, array $row, array $uniqueBy, DateTimeInterface $now): void
    {
        $count = DB::getQueryGrammar()->wrap('count');

        DB::table($table)->upsert(
            [$row],
            $uniqueBy,
            ['count' => DB::raw($count.' + 1'), 'updated_at' => $now],
        );
    }
}
