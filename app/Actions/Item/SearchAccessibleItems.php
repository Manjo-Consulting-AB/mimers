<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fritextsökningen över ALLA containers användaren når — kroppen som tidigare
 * stod i App\Http\Controllers\Api\ItemSearchController::index(), övertagen
 * oförändrad (issue 59b § Beslut 2).
 *
 * Frågan är global med flit: "var la jag den där?" är en fråga över allt
 * användaren har åtkomst till, inte inom en pärm hon redan valt (issue 15b
 * § Beslut 5). Det gör den till den enda ytan där en fråga går över alla
 * containers samtidigt, med olika omfång i var och en — och därför bär den
 * sitt eget åtkomstfilter i stället för rutt-nästlingens grind.
 * [[ADR-0012 Sök]] § Konsekvenser kallar ett sökindex som läcker mellan
 * konton för "en allvarlig incident".
 *
 * **Ingen `Gate::authorize()`.** Här finns ingen grind att anropa: urvalet
 * SJÄLVT är behörigheten, och det är därför den här utbrytningen är issue
 * 59b:s tyngdpunkt (Beslut 2). Den som anropar actionen ansvarar för att
 * frågan är ställd av en inloggad användare — båda anroparna
 * (`Api\ItemSearchController::index()` och
 * `App\Http\Controllers\SearchController::index()`) ligger bakom `auth`.
 *
 * Sökningen går via Scouts databasdrivrutin: en `LIKE`-formulering över
 * Item::toSearchableArray()s fem kolumner (issue 15b § Beslut 3), ingen
 * relevansordning utan `name` stigande (Beslut 7), taggarna laddas i förväg
 * så 13b § Beslut 10:s N+1-skydd inte förloras (Beslut 9). Containern laddas
 * i förväg av samma skäl: varje träff ska kunna säga vilken pärm den ligger i
 * utan en fråga per rad (issue 59b § Beslut 3) — `ItemResource` bär ingen
 * `container`-nyckel, och den ska den inte få heller.
 *
 * Åtkomstvillkoret är utbrutet till `Container::scopeAccessibleBy()`
 * (Beslut 4) och appliceras här som en `whereHas('container', ...)` på
 * sökfrågan. whereHas valdes framför `whereIn('container_id', ...)`:
 * villkoret formuleras på Container-modellen och SoftDeletes' globala scope
 * gäller automatiskt i underfrågan — en mjukraderad container kan inte dyka
 * upp via en lista löpnummer som hämtats med `withTrashed()` (issue 15b
 * § Att se upp med).
 *
 * Sedan issue 73 § Beslut 4 räcker containeråtkomsten inte: den som når
 * containern når inte nödvändigtvis allt i den. Frågan går över ALLA
 * containers användaren når, och omfånget är olika i varje — hon kan äga sin
 * egen, ha `read` på hela sambons och en grant på motorn i båtklubbens.
 * Villkoret blir därför en OR över containers, byggt av ETT anrop till
 * ResolveItemScope::forContainers() (konstant frågekostnad, issue 70
 * § Beslut 2):
 *
 *     (container_id IN [containers där omfånget är obegränsat])
 *     OR (item.id IN [itemnummer ur de begränsade omfången])
 *
 * Den inledande `whereIn('item.id', [])` är INTE en optimering utan skyddet:
 * den kompilerar till `0 = 1`, så den nästlade gruppen har alltid minst ett
 * villkor. Är båda listorna tomma blir svaret därför tomt — i stället för en
 * OR-grupp som faller bort och lämnar `whereHas` ensam (issue 73 § Beslut 4:
 * det klassiska felet i en dynamiskt byggd orWhere är ett sökresultat över
 * hela databasen). Raden ligger kvar först i gruppen, i samma form som i
 * kontrollern den flyttade ur.
 *
 * `accessibleBy` står kvar i `whereHas` precis som förut: den avgör vilka
 * containers som får delta, och SoftDeletes' globala scope i underfrågan
 * hindrar en mjukraderad container från att dyka upp. Omfångsvillkoret ligger
 * BREDVID den, inte i stället för den.
 */
class SearchAccessibleItems
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Alla items användaren når vars fem sökbara kolumner matchar $q,
     * sorterade på namn.
     *
     * @return Collection<int, Item>
     */
    public function handle(User $user, string $q): Collection
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        $containerIds = Container::query()
            ->accessibleBy($user, $accountIds)
            ->pluck('id')
            ->all();

        $unrestrictedContainerIds = [];
        $itemIds = [];

        foreach ($this->resolveItemScope->forContainers($user, $containerIds) as $containerId => $scope) {
            if ($scope->isUnrestricted()) {
                $unrestrictedContainerIds[] = $containerId;

                continue;
            }

            foreach ($scope->itemIds() ?? [] as $itemId) {
                $itemIds[] = $itemId;
            }
        }

        return Item::search($q)
            ->query(function (Builder $query) use ($user, $accountIds, $unrestrictedContainerIds, $itemIds) {
                $query
                    ->whereHas('container', function (Builder $query) use ($user, $accountIds) {
                        /** @var Builder<Container> $query */
                        $query->accessibleBy($user, $accountIds);
                    })
                    ->where(function (Builder $query) use ($unrestrictedContainerIds, $itemIds) {
                        $query->whereIn('item.id', []);

                        if ($unrestrictedContainerIds !== []) {
                            $query->orWhereIn('item.container_id', $unrestrictedContainerIds);
                        }

                        if ($itemIds !== []) {
                            $query->orWhereIn('item.id', $itemIds);
                        }
                    })
                    ->with(['container', 'category', 'createdByAccount', 'tags']);
            })
            ->orderBy('name')
            ->get();
    }
}
