<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Collection;

/**
 * Itemets relationer, sorterade på motpartens namn — se issue 58 § Beslut 2,
 * issue 14 § Beslut 8 och issue 73 § Beslut 7.
 *
 * Kroppen är `App\Http\Controllers\Api\ItemLinkController::index()`s, övertagen
 * oförändrad: unionen över App\Models\Item::linksFrom()/linksTo(),
 * motpartsuppslaget i EN fråga med omfångsfiltret i SAMMA fråga, de tre
 * minnesattributen `counterpart_ulid`/`counterpart_name`/`relation_to_item` och
 * sorteringen på motpartens namn. Webben och `/api` ritar samma lista ur samma
 * kod, så de två ytorna kan aldrig glida isär.
 *
 * **Omfånget filtrerar RADERNA** (issue 73 § Beslut 7). En motpart utanför
 * mottagarens omfång faller bort i namnfrågan och därmed ur resultatet —
 * länken finns inte i svaret alls. Inte ett `null`-namn, inte en post med bara
 * ULID, inte ett spöke: ett spöke säger "det finns något här du inte får se",
 * och den upplysningen är hela det läckage [[ADR-0028 Åtkomst på itemnivå]]
 * § Konsekvenser stänger. Anroparen lägger därför INGENTING ovanpå listan —
 * ingen räknare över hur många länkar som föll bort.
 *
 * En mjukraderad motpart filtreras bort av SoftDeletes globala scope i
 * namnfrågan, så dess länk döljs (issue 14 § Beslut 10) medan raden ligger
 * kvar.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är det,
 * och samma linje som App\Actions\Item\ListItems: skulle den vara null blir
 * omfånget `restricted([])` — "når ingenting" — aldrig ett obegränsat.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som varje tidigare utbrytning i M10 (issue 57a § Beslut 3, 55a
 * § Beslut 8, 56a § Beslut 7).
 */
class ListItemLinks
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Relationerna SEDDA FRÅN `$item`, oavsett hur de ligger lagrade
     * (issue 14 § Beslut 8): `relation_to_item` är vad motparten är för det
     * här itemet. Ordningen är motpartens `name` stigande.
     *
     * @return Collection<int, ItemLink>
     */
    public function handle(?User $user, Container $container, Item $item): Collection
    {
        $scope = $user === null
            ? ItemScope::restricted([])
            : $this->resolveItemScope->handle($user, $container);

        $links = $item->linksFrom()
            ->union($item->linksTo()->getQuery())
            ->get();

        $counterpartIds = $links
            ->map(fn (ItemLink $link) => $this->counterpartId($link, $item))
            ->unique()
            ->values()
            ->all();

        $itemsById = Item::query()
            ->whereIn('id', $counterpartIds)
            ->inScope($scope)
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        $visible = $links->filter(fn (ItemLink $link) => $itemsById->has($this->counterpartId($link, $item)));

        $visible->each(function (ItemLink $link) use ($item, $itemsById) {
            $counterpart = $itemsById->get($this->counterpartId($link, $item));

            $link->setAttribute('counterpart_ulid', $counterpart->ulid);
            $link->setAttribute('counterpart_name', $counterpart->name);
            $link->setAttribute('relation_to_item', $link->relationSeenFromItem($item->id));
        });

        return $visible->sortBy('counterpart_name')->values();
    }

    /**
     * Motpartens id för en länk sedd från `$item` — den ände som INTE är
     * `$item`.
     */
    private function counterpartId(ItemLink $link, Item $item): int
    {
        return $link->from_item_id === $item->id ? $link->to_item_id : $link->from_item_id;
    }
}
