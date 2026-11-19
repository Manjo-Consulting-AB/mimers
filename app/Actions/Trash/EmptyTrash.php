<?php

namespace App\Actions\Trash;

use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\LegalHold;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Tömmer en containers papperskorg på riktigt, nu och inte först när
 * retentionen gått ut. Grinden (`ContainerPolicy::emptyTrash`) ligger i
 * kontrollerna; Actionen är verktyget.
 *
 * Själva raderingen är PurgeContent, orörd, i nattjobbets ordning:
 * bilagor, items, kategorier, taggar, fält. Allt sker i EN transaktion — ett
 * fel i en enskild radering rullar tillbaka hela tömningen. Filernas bytes
 * tas av PurgesExpiredStoredFiles efter sin frist, som vanligt.
 *
 * En rättslig spärr på ägarkontot stoppar tömningen innan något rörs.
 */
class EmptyTrash
{
    public function __construct(
        private readonly PurgeContent $purgeContent,
    ) {}

    /**
     * @return array{attachment: int, item: int, category: int, tag: int, field: int}
     */
    public function handle(Container $container): array
    {
        if (LegalHold::covers($container->account)) {
            throw ApiException::make('trash.legal_hold', [], 422);
        }

        return DB::transaction(function () use ($container): array {
            $itemIds = Item::withTrashed()
                ->where('container_id', $container->id)
                ->pluck('id');

            $attachments = Attachment::onlyTrashed()
                ->where(function ($query) use ($container, $itemIds): void {
                    $query->where('container_id', $container->id)
                        ->orWhereIn('item_id', $itemIds);
                })
                ->get();

            foreach ($attachments as $attachment) {
                $this->purgeContent->attachment($attachment);
            }

            $items = Item::onlyTrashed()->where('container_id', $container->id)->get();

            foreach ($items as $item) {
                $this->purgeContent->item($item);
            }

            $categories = Category::onlyTrashed()->where('container_id', $container->id)->get();

            foreach ($categories as $category) {
                $this->purgeContent->category($category);
            }

            $tags = Tag::onlyTrashed()->where('container_id', $container->id)->get();

            foreach ($tags as $tag) {
                $this->purgeContent->tag($tag);
            }

            // Fälten sist (issue 417): itemens egna fält har redan gallrats
            // med sina items ovan, så det som återstår är containerns fält
            // och de egna fält vars item lever. Varje fält tar sina värden
            // med sig.
            $fields = ItemField::onlyTrashed()->where('container_id', $container->id)->get();

            foreach ($fields as $field) {
                $this->purgeContent->field($field);
            }

            return [
                'attachment' => $attachments->count(),
                'item' => $items->count(),
                'category' => $categories->count(),
                'tag' => $tags->count(),
                'field' => $fields->count(),
            ];
        });
    }
}
