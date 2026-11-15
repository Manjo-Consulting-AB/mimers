<?php

namespace App\Support\Usage;

use App\Actions\Usage\AdjustUsage;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * De räknade textraderna ([[ADR-0081 All lagrad data räknas mot kvoten]]) som
 * en enda fråga per tabell — vem som betalar, vems container raden ligger i,
 * och vad den väger enligt App\Support\Usage\MetadataSize.
 *
 * Klassen finns för att tre ställen ska se SAMMA mängd rader: nattens
 * avstämning (`bytesPerAccount()`), kontoraderingen och ägarbytet
 * (`moveToContainerOwner()`, `moveContainerPayer()`). Skulle de formulera
 * "vilka rader" var för sig glider räknarna isär från raderna.
 *
 * Alla rader räknas, mjukraderade också: de räknas tills de raderas för gott
 * (ADR-0081 § 3). Frågorna går därför genom query builder och inte genom
 * Eloquent, vars SoftDeletes-scope hade dolt dem.
 *
 * Betalaren är `billed_account_id`, utom för `container` där den är ägaren
 * (`container.account_id`). För `schedule_occurrence` är kolumnen NULL tills
 * en anteckning skrivits, och en rad utan betalare räknas inte.
 */
final class CountedRows
{
    /**
     * Raderna i en räknad tabell, med fyra fasta kolumner utöver
     * fritextkolumnerna: `id`, `payer_id` (kan vara NULL), `owner_id`
     * (containerns ägare) och `container_id`.
     */
    public static function query(string $table): Builder
    {
        $text = array_map(
            fn (string $column): string => "{$table}.{$column}",
            MetadataSize::COLUMNS[$table],
        );

        if ($table === 'container') {
            return DB::table('container')->select([
                'container.id',
                'container.account_id as payer_id',
                'container.account_id as owner_id',
                'container.id as container_id',
                ...$text,
            ]);
        }

        $query = DB::table($table);

        // Vägen från raden till dess container. Items, kategorier, taggar och
        // kostnader bär `container_id` själva; scheman och utlåningar går
        // genom sitt item, och förekomsterna genom sitt schema.
        match ($table) {
            'schedule_occurrence' => $query
                ->join('schedule', 'schedule.id', '=', 'schedule_occurrence.schedule_id')
                ->join('item', 'item.id', '=', 'schedule.item_id')
                ->join('container', 'container.id', '=', 'item.container_id'),
            'schedule', 'loan' => $query
                ->join('item', 'item.id', '=', "{$table}.item_id")
                ->join('container', 'container.id', '=', 'item.container_id'),
            default => $query->join('container', 'container.id', '=', "{$table}.container_id"),
        };

        return $query->select([
            "{$table}.id",
            "{$table}.billed_account_id as payer_id",
            'container.account_id as owner_id',
            'container.id as container_id',
            ...$text,
        ]);
    }

    /**
     * Summan av radernas vikt per betalande konto — sanningen bakom
     * `usage_counter.metadata_bytes`. Raderna läses i block och viktas med
     * MetadataSize, inte med SQL `LENGTH()` (se MetadataSize).
     *
     * @return array<int, int>
     */
    public static function bytesPerAccount(): array
    {
        $totals = [];

        foreach (array_keys(MetadataSize::COLUMNS) as $table) {
            self::query($table)->chunkById(500, function ($rows) use ($table, &$totals): void {
                foreach ($rows as $row) {
                    if ($row->payer_id === null) {
                        continue;
                    }

                    $totals[(int) $row->payer_id] = ($totals[(int) $row->payer_id] ?? 0)
                        + MetadataSize::ofAttributes($table, (array) $row);
                }
            }, "{$table}.id", 'id');
        }

        return $totals;
    }

    /**
     * Raderna `$accountId` betalar för i containrar kontot inte äger får
     * containerns ägare som betalare, och bytena flyttar med (ADR-0081 § 4).
     * Ingen kvotprövning: följden av en full ägare är att nya skrivningar
     * nekas, inte att något raderas. `container` är inte med — dess betalare
     * är redan ägaren.
     */
    public static function moveToContainerOwner(int $accountId): void
    {
        self::move(
            fn (Builder $query, string $table) => $query
                ->where("{$table}.billed_account_id", $accountId)
                ->where('container.account_id', '!=', $accountId),
            null,
        );
    }

    /**
     * Raderna i containern som `$from` betalar för får `$to` som betalare, och
     * bytena flyttar med. Bokförda på ett tredje konto rörs inte: den som
     * skrev raden betalar fortfarande för den. Containerraden själv följer
     * med ägaren och flyttas av anroparen via `containerBytes()`.
     */
    public static function moveContainerPayer(int $containerId, int $from, int $to): void
    {
        self::move(
            fn (Builder $query, string $table) => $query
                ->where('container.id', $containerId)
                ->where("{$table}.billed_account_id", $from),
            $to,
        );
    }

    /**
     * Containerradens egen vikt, för ägarbytet: containern byter betalare
     * genom att `account_id` byts, så bytena flyttas av anroparen.
     */
    public static function containerBytes(int $containerId): int
    {
        $row = self::query('container')->where('container.id', $containerId)->first();

        return $row === null ? 0 : MetadataSize::ofAttributes('container', (array) $row);
    }

    /**
     * @param  Closure(Builder, string): mixed  $scope  Begränsar raderna; får lita på att
     *                                                  `container` är joinad.
     * @param  int|null  $target  Ny betalare, eller null för containerns ägare.
     */
    private static function move(Closure $scope, ?int $target): void
    {
        /** @var array<int, array<int, int>> $bytes  [från][till] => byte */
        $bytes = [];

        foreach (array_keys(MetadataSize::COLUMNS) as $table) {
            if ($table === 'container') {
                continue;
            }

            $query = self::query($table);
            $scope($query, $table);

            $query->chunkById(500, function ($rows) use ($table, $target, &$bytes): void {
                $idsPerTarget = [];

                foreach ($rows as $row) {
                    $to = $target ?? (int) $row->owner_id;
                    $from = (int) $row->payer_id;

                    if ($from === $to) {
                        continue;
                    }

                    $bytes[$from][$to] = ($bytes[$from][$to] ?? 0)
                        + MetadataSize::ofAttributes($table, (array) $row);
                    $idsPerTarget[$to][] = $row->id;
                }

                foreach ($idsPerTarget as $to => $ids) {
                    DB::table($table)->whereIn('id', $ids)->update([
                        'billed_account_id' => $to,
                        'updated_at' => now(),
                    ]);
                }
            }, "{$table}.id", 'id');
        }

        foreach ($bytes as $from => $perTarget) {
            foreach ($perTarget as $to => $amount) {
                if ($amount > 0) {
                    (new AdjustUsage)->handle($from, metadataDelta: -$amount);
                    (new AdjustUsage)->handle($to, metadataDelta: +$amount);
                }
            }
        }
    }
}
