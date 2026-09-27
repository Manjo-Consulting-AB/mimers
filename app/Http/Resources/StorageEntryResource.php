<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Radformatet för urvalslistan i nedgraderingen, se issue 28 § Beslut 3.
 * En bilaga på kontots storage-yta: vad det är för fil, hur stor den är och
 * VAR den ligger — `container` och `item` per rad, så användaren kan välja
 * medan hon ser sammanhanget ("de fyrtio semesterbilderna" sitter på samma
 * item).
 *
 * Alltid närvarande nycklar, aldrig ett löpnummer: ingen `id`, ingen
 * `item_id`, `stored_file_id` eller `billed_account_id`. `byte_size` läses
 * ur `stored_file` — relationen är eagrad av kontrollern, listan får inte
 * göra en fråga per rad. `container` och `item` läses genom bilagans
 * relationer; kontrollern eagrar dem med `withTrashed()`, för en bilaga vars
 * item eller container ligger i papperskorgen räknas fortfarande mot kontot
 * och ska gå att rensa bort.
 *
 * **`item` är `null` för en containerbilaga** (issue 158 · [[ADR-0047
 * Containerns bild]]). En sådan bilaga är containerns bild och hör inte
 * till något item; containern kommer ur
 * App\Models\Attachment::owningContainer(), som svarar containern för båda
 * slagen. Nyckeln är kvar med `null` i stället för att utelämnas — samma
 * regel som `kind` och `description` i ContainerResource: en klient som
 * måste skilja "saknas" från "inget värde" ska inte behöva hantera två fall.
 *
 * @mixin Attachment
 */
class StorageEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $container = $this->owningContainer();

        return [
            'ulid' => $this->ulid,
            'filename' => $this->filename,
            'byte_size' => $this->storedFile->byte_size,
            'kind' => $this->kind,
            'container' => $container === null ? null : [
                'ulid' => $container->ulid,
                'name' => $container->name,
            ],
            'item' => $this->item === null ? null : [
                'ulid' => $this->item->ulid,
                'name' => $this->item->name,
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
