<?php

namespace App\Actions\Trash;

use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tag;

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

        $model->restore();
    }
}
