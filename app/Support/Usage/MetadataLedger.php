<?php

namespace App\Support\Usage;

use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Support\Plan\Entitlements;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
 *   (raden läst under lås i transaktionen) och efter. Byter raden betalare — en förekomst som får
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
     * befintlig rad. `$checkQuota = false` för det systemet skapar utan
     * användarens text (ADR-0081 § 2 och 4): raden räknas men prövas inte.
     */
    public static function save(Model $row, bool $checkQuota = true): void
    {
        $table = $row->getTable();
        $existed = $row->exists;

        // "Före" läses ur raden under lås, inte ur anroparens (möjligen förlegade)
        // instans: radlåset serialiserar samtidiga skrivare.
        $before = [];
        if ($existed) {
            $locked = DB::table($table)->where('id', $row->getKey())->lockForUpdate()->first();
            $before = $locked === null ? $row->getRawOriginal() : (array) $locked;
        }
        $oldPayer = $existed ? self::payerOf($table, $before) : null;
        $oldBytes = $oldPayer === null ? 0 : MetadataSize::ofAttributes($table, $before);

        $row->save();

        $after = $row->getAttributes();
        $newPayer = self::payerOf($table, $after);
        $newBytes = $newPayer === null ? 0 : MetadataSize::ofAttributes($table, $after);

        if ($oldPayer === $newPayer) {
            if ($newPayer !== null && $newBytes !== $oldBytes) {
                // Bara en växande skrivning prövas (ADR-0081 § 5); krymper den
                // går den alltid igenom. Kastet rullar tillbaka transaktionen.
                if ($checkQuota && $newBytes > $oldBytes) {
                    self::assertFits($newPayer, $newBytes - $oldBytes);
                }

                (new AdjustUsage)->handle($newPayer, metadataDelta: $newBytes - $oldBytes);
            }

            return;
        }

        if ($oldPayer !== null && $oldBytes > 0) {
            (new AdjustUsage)->handle($oldPayer, metadataDelta: -$oldBytes);
        }

        if ($newPayer !== null && $newBytes > 0) {
            if ($checkQuota) {
                self::assertFits($newPayer, $newBytes);
            }
            (new AdjustUsage)->handle($newPayer, metadataDelta: $newBytes);
        }
    }

    /**
     * Prövar att betalaren rymmer `$addedBytes` till (issue 1303).
     */
    private static function assertFits(int $payer, int $addedBytes): void
    {
        (new Entitlements)->assertMetadataWithinLimit(Account::query()->findOrFail($payer), $addedBytes);
    }

    /**
     * Ryms `$addedBytes` hos betalaren? (issue 412 · [[ADR-0088
     * Versionshistorik för items]] Riktning 3.)
     *
     * Samma fråga som `save()` ställer, men utan att skriva något: en version
     * prövas INNAN den sparas, så att den kan tas bort eller hoppas över utan
     * att en rad först har lagts in och rullats tillbaka. Frågan ställer
     * samma `assertFits` som `save()` gör, så de två inte kan glida isär.
     */
    public static function fits(int $payer, int $addedBytes): bool
    {
        try {
            self::assertFits($payer, $addedBytes);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /**
     * Minskar betalaren med radens vikt. Anropas före radering för gott.
     */
    public static function release(Model $row): void
    {
        $table = $row->getTable();
        $locked = $row->exists
            ? DB::table($table)->where('id', $row->getKey())->lockForUpdate()->first()
            : null;
        $attributes = $locked === null ? $row->getAttributes() : (array) $locked;
        $payer = self::payerOf($table, $attributes);

        if ($payer === null) {
            return;
        }

        $bytes = MetadataSize::ofAttributes($table, $attributes);

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
        $query->lockForUpdate();

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
