<?php

namespace App\Support\Usage;

use App\Actions\Usage\AdjustUsage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * Löpande bokföring av textens byte i `usage_counter.metadata_bytes`
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2 och 3, issue 1302).
 *
 * Alla skrivningar mot de räknade tabellerna går hit i stället för att varje
 * action räknar för sig: måttet kommer ur App\Support\Usage\MetadataSize och
 * raderna och betalaren ur App\Support\Usage\CountedRows — samma som nattens
 * avstämning använder — så att räknaren och avstämningen inte kan glida isär.
 *
 * Alla metoder förutsätter att anroparen redan står i en transaktion, precis
 * som AdjustUsage: räknaren ska ändras i samma transaktion som raden.
 *
 * - `save()` skapar eller ändrar en rad och bokför skillnaden mellan före
 *   (radens original) och efter. Byter raden betalare — en förekomst som får
 *   sin första anteckning, eller som töms — minusas den gamla betalaren och
 *   den nya plussas.
 * - `release()` och `releaseWhere()` körs FÖRE en radering för gott och
 *   minskar betalarna med radernas vikt. Mjukradering och återställning ändrar
 *   ingenting (slängda rader räknas, ADR-0081 § 3).
 */
final class MetadataLedger
{
    /**
     * Sparar raden och bokför skillnaden i vikt. Gäller både en ny och en
     * befintlig rad.
     */
    public static function save(Model $row): void
    {
        $table = $row->getTable();
        $existed = $row->exists;

        $before = $existed ? $row->getRawOriginal() : [];
        $oldPayer = $existed ? self::payerOf($table, $before) : null;
        $oldBytes = $oldPayer === null ? 0 : MetadataSize::ofAttributes($table, $before);

        $row->save();

        $after = $row->getAttributes();
        $newPayer = self::payerOf($table, $after);
        $newBytes = $newPayer === null ? 0 : MetadataSize::ofAttributes($table, $after);

        if ($oldPayer === $newPayer) {
            if ($newPayer !== null && $newBytes !== $oldBytes) {
                (new AdjustUsage)->handle($newPayer, metadataDelta: $newBytes - $oldBytes);
            }

            return;
        }

        if ($oldPayer !== null && $oldBytes > 0) {
            (new AdjustUsage)->handle($oldPayer, metadataDelta: -$oldBytes);
        }

        if ($newPayer !== null && $newBytes > 0) {
            (new AdjustUsage)->handle($newPayer, metadataDelta: $newBytes);
        }
    }

    /**
     * Minskar betalaren med radens vikt. Anropas före radering för gott.
     */
    public static function release(Model $row): void
    {
        $table = $row->getTable();
        $payer = self::payerOf($table, $row->getAttributes());

        if ($payer === null) {
            return;
        }

        $bytes = MetadataSize::of($row);

        if ($bytes > 0) {
            (new AdjustUsage)->handle($payer, metadataDelta: -$bytes);
        }
    }

    /**
     * Minskar varje betalare med vikten av de rader `$scope` pekar ut — för
     * massraderingar som tas bort med `DB::table(...)->delete()` eller
     * `forceDelete()` på en byggare, där inga modellinstanser finns. Summan
     * räknas fram före raderingen. Mjukraderade rader är med.
     *
     * @param  Closure(Builder): mixed  $scope  Begränsar CountedRows::query(); tabellerna
     *                                          är prefixade (`schedule.item_id`).
     */
    public static function releaseWhere(string $table, Closure $scope): void
    {
        $query = CountedRows::query($table);
        $scope($query);

        /** @var array<int, int> $perPayer */
        $perPayer = [];

        $query->chunkById(500, function ($rows) use ($table, &$perPayer): void {
            foreach ($rows as $row) {
                if ($row->payer_id === null) {
                    continue;
                }

                $perPayer[(int) $row->payer_id] = ($perPayer[(int) $row->payer_id] ?? 0)
                    + MetadataSize::ofAttributes($table, (array) $row);
            }
        }, "{$table}.id", 'id');

        foreach ($perPayer as $payer => $bytes) {
            if ($bytes > 0) {
                (new AdjustUsage)->handle($payer, metadataDelta: -$bytes);
            }
        }
    }

    /**
     * Betalaren: ägaren för containerraden, annars `billed_account_id`
     * (NULL för en förekomst utan anteckning).
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function payerOf(string $table, array $attributes): ?int
    {
        $column = $table === 'container' ? 'account_id' : 'billed_account_id';
        $value = $attributes[$column] ?? null;

        return $value === null ? null : (int) $value;
    }
}
