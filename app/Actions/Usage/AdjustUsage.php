<?php

namespace App\Actions\Usage;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Den enda vägen IN i räknaren (issue 26a § Beslut 3) — se [[Planer och
 * kvoter]] § usage_counter. Varje gång en bilaga eller en container blir
 * levande eller slutar vara levande anropar den som ändrar raden den här
 * actionen med bytena och/eller containerantalet, i samma transaktion som
 * ändringen.
 *
 * Tre krav på implementationen (Beslut 3):
 *
 * 1. Raden skapas om den inte finns, i samma anrop och utan racevillkor:
 *    upsert med `account_id` som unik nyckel — två samtidiga första
 *    uppladdningar för ett nytt konto kolliderar på ON DUPLICATE KEY /
 *    ON CONFLICT i stället för att den ena kastar.
 * 2. Ändringen är SQL, aldrig läs-ändra-skriv i PHP: två samtidiga
 *    uppladdningar som båda läser 100 och skriver 110 tappar den ena.
 * 3. Kolumnerna är UNSIGNED och får aldrig gå under noll. En minskning
 *    klampar i SQL (CASE, inte GREATEST/CAST — samma språk på mysql och
 *    sqlite), och en klampning ska gå att se: 26b:s avstämning är det som
 *    upptäcker att den hänt.
 *
 * Actionen öppnar ingen egen transaktion — den anropas alltid inifrån
 * anroparens transaktion, och en egen `DB::transaction` där vore en nästlad
 * transaktion som ser ut att skydda något den inte skyddar.
 */
class AdjustUsage
{
    public function handle(int $accountId, int $bytesDelta = 0, int $containersDelta = 0): void
    {
        // Steg 1: se till att raden finns. Upsert (inte updateOrInsert) är
        // det som gör skapandet race-fritt: inserten och "finns den redan,
        // gör inget" är ETT uttalande på både mysql (ON DUPLICATE KEY) och
        // sqlite (ON CONFLICT). `updated_at` i uppdateringslistan är en
        // no-op som bara håller tidsstämpeln färsk — kolumnerna som räknas
        // sätts i steg 2.
        DB::table('usage_counter')->upsert(
            [
                'account_id' => $accountId,
                'storage_bytes' => 0,
                'container_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['account_id'],
            ['updated_at'],
        );

        $changes = [];

        if ($bytesDelta !== 0) {
            $changes['storage_bytes'] = $this->changeAsSql('storage_bytes', $bytesDelta);
        }

        if ($containersDelta !== 0) {
            $changes['container_count'] = $this->changeAsSql('container_count', $containersDelta);
        }

        if ($changes !== []) {
            DB::table('usage_counter')->where('account_id', $accountId)->update($changes);
        }
    }

    /**
     * En ökning är ren addition. En minskning måste klamra vid noll: utan
     * klampningen wrappar en UNSIGNED-kolumn i MySQL till ett gigantiskt tal
     * och kontot är för alltid över sin kvot. CASE-formuleringen räknar bara
     * fram ett negativt resultat i grenen som aldrig lagrar det, och är
     * giltig SQL på både mysql och sqlite — till skillnad från
     * CAST(... AS SIGNED), som saknas i sqlite.
     */
    private function changeAsSql(string $column, int $delta): Expression
    {
        if ($delta > 0) {
            return DB::raw("{$column} + {$delta}");
        }

        $amount = -$delta;

        return DB::raw("CASE WHEN {$column} >= {$amount} THEN {$column} - {$amount} ELSE 0 END");
    }
}
