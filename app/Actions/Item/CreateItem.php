<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett nytt item med sina taggar och sin förälder, och loggar
 * händelserna — på ett ställe, så webbens och `/api`:s skapande inte kan
 * glida isär (issue 109, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]: skrivningen
 * bär numera en regel — vad som loggas och i vilken transaktion — och den
 * regeln ska ha ett eget test. Kontrollerna behåller allt som hör till
 * requesten: valideringen, grindarna, medlemsprövningen och uppslagen av
 * konto, kategori och taggar. De två ytorna skiljer sig åt där (webben har
 * alltid `tags` med, `/api` skiljer på utelämnat och tomt) och ska fortsätta
 * göra det; det som inte får skilja sig är skrivningen.
 *
 * **Itemet, taggarna och länken i EN transaktion, och loggraderna i samma
 * transaktion** (issue 13b § Beslut 7, issue 40 § Beslut 9): ett item sparat
 * med halv taggning, eller en loggrad som överlevde ett rollback, är värre
 * än ingen logg alls.
 *
 * `container_id`, `created_by_user_id` och `created_by_account_id` sätts
 * explicit — alla är uteslutna ur `App\Models\Item::#[Fillable]`. `$item`
 * kommer färdig från kontrollern med de vanliga fälten ifyllda; actionen
 * fyller på de fyra som inte får komma från kroppen.
 *
 * `LinkItems` injiceras: föräldrakanten ska skapas genom samma action som
 * alla andra relationer, med normaliseringen och cykelkontrollen från issue
 * 14 — och den loggar sin egen rad.
 */
class CreateItem
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly SyncItemTags $syncItemTags,
        private readonly LinkItems $linkItems,
    ) {}

    /**
     * @param  User  $actor  Den som skapar itemet; blir `user_id` på
     *                       loggraderna. Behörigheten är redan prövad.
     * @param  Item  $item  Det nya itemet med kroppens fält ifyllda, utan
     *                      `container_id`, `category_id` och `created_by_*`.
     * @param  Collection<int, Tag>  $tags  Den önskade taggmängden.
     * @param  Item|null  $parent  Föräldern ur kroppen, redan uppslagen i
     *                             containern.
     */
    public function handle(
        Container $container,
        Account $account,
        User $actor,
        Item $item,
        ?Category $category,
        Collection $tags,
        ?Item $parent,
    ): Item {
        DB::transaction(function () use ($container, $account, $actor, $item, $category, $tags, $parent): void {
            $item->container_id = $container->id;
            $item->category_id = $category?->id;
            $item->created_by_user_id = $actor->id;
            $item->created_by_account_id = $account->id;
            $item->save();

            // Först itemet, sedan det som hänger på det: listan sorterar
            // nyaste raden först, så itemets egen rad hamnar underst av de
            // rader samma anrop skrev — och läses som den händelse de andra
            // är detaljer till (issue 108).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ITEM_CREATED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
            );

            // Ett nytt item börjar utan taggar, så en tom önskad mängd är
            // ingen ändring och skriver ingen rad. SyncItemTags prövar det
            // själv; anropet är villkorslöst för att regeln ska bo där.
            $this->syncItemTags->handle($item, $tags, $actor);

            if ($parent !== null) {
                $this->linkItems->handle($parent, $item, 'parent', $actor);
            }
        });

        return $item;
    }
}
