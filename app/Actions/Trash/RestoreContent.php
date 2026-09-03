<?php

namespace App\Actions\Trash;

use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Återställer en mjukraderad rad ur papperskorgen, med reglerna från issue
 * 20a § Beslut 7 och 8. En Action i stället för direkt i kontrollern, se
 * [[ADR-0024 Tunna controllers och actions]] — § Beslut 10: "RestoreContent
 * äger reglerna. Actionen tar typ och modell, prövar Beslut 8 och kallar
 * restore()." 20c återanvänder mönstret men inte klassen.
 *
 * Återställning är ETT steg, aldrig en kaskad (§ Beslut 7): `restore()` på
 * raden, ingenting annat. En bilaga som raderats separat ligger kvar i
 * papperskorgen när itemet återställs; en tagg kommer tillbaka på sina
 * items automatiskt eftersom pivotraderna ligger kvar och det är SoftDeletes
 * globala scope som gömt taggen.
 *
 * Det enda som kan blockera är en förälder som FORTFARANDE ligger i
 * papperskorgen (§ Beslut 8): en bilaga vars item är raderat, eller en
 * underkategori vars förälder är raderad. Utan regeln får man innehåll som
 * är återställt men osynligt — det ligger under något som fortfarande är
 * raderat. `data` pekar ut föräldern så klienten kan erbjuda att ta
 * tillbaka den i ett klick.
 *
 * Föräldern slås upp med en explicit `withTrashed()`-fråga — att gå genom
 * relationen `$attachment->item` respektive `$category->parent` vore att låta
 * den relaterade modellens SoftDeletes-scope svara null för en raderad
 * förälder, precis det fall som ska fångas här.
 */
class RestoreContent
{
    /**
     * @param  'item'|'attachment'|'category'|'tag'  $type
     */
    public function handle(string $type, Item|Attachment|Category|Tag $model): void
    {
        if ($model instanceof Attachment) {
            $item = Item::withTrashed()->find($model->item_id);

            if ($item !== null && $item->trashed()) {
                throw ApiException::make('trash.parent_deleted', [
                    'type' => 'item',
                    'ulid' => $item->ulid,
                ], 422);
            }
        }

        if ($model instanceof Category && $model->parent_id !== null) {
            $parent = Category::withTrashed()->find($model->parent_id);

            if ($parent !== null && $parent->trashed()) {
                throw ApiException::make('trash.parent_deleted', [
                    'type' => 'category',
                    'ulid' => $parent->ulid,
                ], 422);
            }
        }

        DB::transaction(function () use ($model): void {
            // Återställningen och en eventuell räknarökning i en transaktion
            // (issue 26a): en mjukraderad bilaga som blir levande igen kommer
            // tillbaka i kontots förbrukning, i samma transaktion som raden.
            //
            // Beslutet att öka grundas på radens tillstånd UNDER radlåset, inte
            // på instansen som kontrollern laddade före transaktionen: två
            // samtidiga återställningar av samma rad skulle annars båda se
            // `trashed()` och öka räknaren två gånger (granskningsfynd 1).
            // newQueryWithoutScopes — instansen är mjukraderad — och
            // lockForUpdate är en current read. Är raden redan borta (gallrad
            // mellan kontrollerns uppslag och den här transaktionen) finns
            // inget att återställa och ingen räknare att röra.
            $rad = $model->newQueryWithoutScopes()
                ->whereKey($model->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $varMjukraderad = $rad->trashed();

            // restore() körs på instansen även när en samtidig återställning
            // redan hunnit först — den är då en no-op i databasen som bara
            // synkar instansens deleted_at för den som anropar.
            $model->restore();

            if ($model instanceof Attachment && $varMjukraderad) {
                $byteSize = (int) $model->storedFile()->value('byte_size');

                (new AdjustUsage)->handle($model->billed_account_id, bytesDelta: $byteSize);
            }
        });
    }
}
