<?php

namespace App\Actions\Trash;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;

/**
 * Slår upp EN mjukraderad rad inom containern, med retentionen tillämpad —
 * se issue 62a § Beslut 2 och issue 20a § Beslut 5.
 *
 * Kroppen är `App\Http\Controllers\Api\TrashController::findTrashed()`,
 * övertagen oförändrad. Uppslaget är samma sak för `/api`:s återställning
 * och webbens, och en andra kopia av en container-scopad `onlyTrashed`-fråga
 * är precis den sortens duplikat som glider isär.
 *
 * Hittas inget returneras null, och anroparen svarar 404 — det enda
 * realistiska fallet är ett utgånget innehåll som `RestoreRequest` släppte
 * igenom, för valideringen filtrerar med flit inte på retention (att göra
 * ett utgånget innehåll till ett 422-valideringsfel vore fel svar).
 *
 * Uppslaget är container-scopat även om valideringen redan bevisat
 * containertillhörigheten: bälte och hängslen, samma regel som issue 13a
 * § Att se upp med. För `attachment` går scopet genom join mot `item` och
 * `select('attachment.*')` ser till att itemets kolumner inte skriver över
 * bilagans vid hydreringen.
 *
 * **Ingen behörighetslogik bor här** ([[ADR-0024 Tunna controllers och
 * actions]]): uppslaget är ett filter, grinden är `ItemPolicy::delete()`
 * respektive `ContainerPolicy::update()` och prövas av anroparen, på den rad
 * den här metoden pekat ut (issue 74 § Beslut 2).
 */
class FindTrashedInContainer
{
    /**
     * @param  'item'|'attachment'|'category'|'tag'  $type
     */
    public function handle(Container $container, string $type, string $ulid): Item|Attachment|Category|Tag|null
    {
        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        return match ($type) {
            'item' => Item::query()->onlyTrashed()
                ->where('container_id', $container->id)
                ->where('ulid', $ulid)
                ->where('deleted_at', '>=', $cutoff)
                ->first(),
            'category' => Category::query()->onlyTrashed()
                ->where('container_id', $container->id)
                ->where('ulid', $ulid)
                ->where('deleted_at', '>=', $cutoff)
                ->first(),
            'tag' => Tag::query()->onlyTrashed()
                ->where('container_id', $container->id)
                ->where('ulid', $ulid)
                ->where('deleted_at', '>=', $cutoff)
                ->first(),
            'attachment' => Attachment::query()->onlyTrashed()
                ->select('attachment.*')
                ->join('item', 'item.id', '=', 'attachment.item_id')
                ->where('item.container_id', $container->id)
                ->where('attachment.ulid', $ulid)
                ->where('attachment.deleted_at', '>=', $cutoff)
                ->first(),
        };
    }
}
