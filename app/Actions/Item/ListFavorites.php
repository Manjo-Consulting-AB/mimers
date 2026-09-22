<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Användarens favoriter, sorterade på namn — se [[M17 Designsystemet]] § 106
 * och [[ADR-0042 Designsystemet]] § Konsekvenser.
 *
 * **Listan filtreras genom ResolveItemScope som allt annat.** En favorit är en
 * rad om förhållandet mellan en användare och ett item, och den speglar
 * åtkomsten utan att ge den (issue 105). Dras åtkomsten in försvinner raden —
 * en genväg till något man inte längre når är en trasig länk, och en lista som
 * visar den vore en andra väg till ett item grinden redan nekat.
 *
 * **Ingenting berättar hur många som filtrerats bort** (issue 73 § Beslut 6).
 * Svaret är de rader användaren når och ingenting annat: ingen räknare, ingen
 * gråad rad, ingen markering. En favoritlista som sade *"2 dolda"* läckte
 * exakt det omfångsmodellen finns till för att inte läcka.
 *
 * **Åtkomstvillkoret är detsamma som den globala sökningens**
 * (App\Actions\Item\SearchAccessibleItems, issue 73 § Beslut 4) och byggs på
 * samma två led: `Container::accessibleBy` i en `whereHas` — som avgör vilka
 * containers som får delta, och där SoftDeletes' globala scope i underfrågan
 * hindrar en mjukraderad container från att dyka upp — och omfånget BREDVID
 * den, aldrig i stället för den. Den inledande `whereIn('item.id', [])` är
 * skyddet och inte en optimering: den kompilerar till `0 = 1`, så OR-gruppen
 * alltid har minst ett villkor och en tom omfångslista ger ett tomt svar i
 * stället för ett svar över hela databasen.
 *
 * **Frågekostnaden är konstant** och växer inte med antalet favoriter:
 * containrarna i ETT anrop, omfånget i ETT anrop över alla dem
 * (ResolveItemScope::forContainers, issue 70 § Beslut 2 — tre frågor, fyra när
 * någon container har en itemgrant), favoritraderna i EN fråga och containern
 * eager-laddad i EN. Det är samma form som sökningen och samma skäl: en fråga
 * per rad är den N+1 issue 9a § Att se upp med varnar för.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är urvalet självt, och anroparen
 * ansvarar för att frågan kommer från en inloggad användare — samma linje som
 * ListItems, SearchAccessibleItems och issue 54 § Beslut 3. Anroparen är
 * App\Http\Middleware\HandleInertiaRequests, vars closure bara löses för en
 * inloggad användare.
 *
 * **Ingen paginering och ingen sökning.** Favoriterna är få per definition; blir
 * de många är det ett nytt beslut och inte en scrollyta här (issue 106
 * § Out of scope).
 */
class ListFavorites
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Användarens favoriter, i namnordning, filtrerade på omfång.
     *
     * `container` är eager-laddad: raden länkar till itemets detaljvy och
     * behöver containerns ULID för adressen. Det är EN fråga, konstant över
     * antalet favoriter.
     *
     * @return Collection<int, Item>
     */
    public function handle(User $user): Collection
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

        return Item::query()
            ->whereHas('favoritedBy', function (Builder $query) use ($user) {
                /** @var Builder<Favorite> $query */
                $query->where('user_id', $user->id);
            })
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
            ->with('container')
            ->orderBy('name')
            ->get();
    }
}
