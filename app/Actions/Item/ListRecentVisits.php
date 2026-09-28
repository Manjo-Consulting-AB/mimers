<?php

namespace App\Actions\Item;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\RecentVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Användarens senast besökta items, nyast först — se [[ADR-0049 Nyligen
 * besökta]] och [[M23 Mobilen och kartan]] § 160.
 *
 * **Listan filtreras genom ResolveItemScope som allt annat** (favoritlistan i
 * issue 106 är förlagan, och åtkomstvillkoret är detsamma). En besöksrad är en
 * rad om förhållandet mellan en person och ett item, och den speglar åtkomsten
 * utan att ge den: ett item man förlorat åtkomsten till — eller som ligger i
 * papperskorgen — försvinner ur listan. Raden står kvar, så en återfådd åtkomst
 * gör den synlig igen, och det är hela skillnaden mot att filtrera vid
 * skrivningen ([[ADR-0049 Nyligen besökta]] § Beslut).
 *
 * **Ingenting berättar hur många som filtrerats bort** (issue 73 § Beslut 6).
 * Svaret är de rader användaren når och ingenting annat: ingen räknare, ingen
 * gråad rad, ingen markering. En lista som sade *"2 dolda"* läckte exakt det
 * omfångsmodellen finns till för att inte läcka.
 *
 * **Åtkomstvillkoret byggs på samma två led som ListFavorites**:
 * `Container::accessibleBy` i en `whereHas` — som avgör vilka containers som
 * får delta, och där SoftDeletes' globala scope i underfrågan hindrar en
 * mjukraderad container från att dyka upp — och omfånget BREDVID den, aldrig i
 * stället för den. Den inledande `whereIn('item.id', [])` är skyddet och inte
 * en optimering: den kompilerar till `0 = 1`, så OR-gruppen alltid har minst
 * ett villkor och en tom omfångslista ger ett tomt svar i stället för ett svar
 * över hela databasen.
 *
 * **Frågan ställs mot `recent_visit` och inte mot `item`**, till skillnad från
 * favoritlistan: raden bär `visited_at`, som är det listan sorterar på OCH det
 * skalet skriver ut — en `whereHas` på item hade gett itemen men tappat
 * tidsstämpeln. `whereHas('item', …)` filtrerar bort ett mjukraderat item
 * genom Items globala scope, så papperskorgen syns inte heller här.
 *
 * **Frågekostnaden är konstant** och växer inte med antalet rader:
 * containrarna i ETT anrop, omfånget i ETT anrop över alla dem
 * (ResolveItemScope::forContainers, issue 70 § Beslut 2), besöksraderna i EN
 * fråga och itemet med sin container i TVÅ. Det är samma form som
 * ListFavorites och samma skäl: en fråga per rad är den N+1 issue 9a
 * § Att se upp med varnar för.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är urvalet självt, och anroparen
 * ansvarar för att frågan kommer från en inloggad användare — samma linje som
 * ListFavorites, ListItems och SearchAccessibleItems. Anroparen är
 * App\Http\Middleware\HandleInertiaRequests, vars closure bara löses för en
 * inloggad användare.
 */
class ListRecentVisits
{
    /**
     * Antalet rader listan visar — [[ADR-0049 Nyligen besökta]] § Beslut.
     * Personen har tjugo (App\Actions\Item\RecordRecentVisit), och de tio
     * översta räcker för en sidomeny.
     */
    public const LIMIT = 10;

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Användarens senast besökta items, nyast först och filtrerade på omfång.
     *
     * `item.container` är eager-laddad: raden visar containerns namn och
     * länkar till itemets detaljvy, och adressen behöver båda ULID:na. Det är
     * TVÅ frågor, konstanta över antalet rader.
     *
     * @return Collection<int, RecentVisit>
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

        return RecentVisit::query()
            ->where('recent_visit.user_id', $user->getKey())
            ->whereHas('item', function (Builder $query) use ($user, $accountIds, $unrestrictedContainerIds, $itemIds) {
                /** @var Builder<Item> $query */
                $query->whereHas('container', function (Builder $query) use ($user, $accountIds) {
                    /** @var Builder<Container> $query */
                    $query->accessibleBy($user, $accountIds);
                });

                $query->where(function (Builder $query) use ($unrestrictedContainerIds, $itemIds) {
                    $query->whereIn('item.id', []);

                    if ($unrestrictedContainerIds !== []) {
                        $query->orWhereIn('item.container_id', $unrestrictedContainerIds);
                    }

                    if ($itemIds !== []) {
                        $query->orWhereIn('item.id', $itemIds);
                    }
                });
            })
            ->with('item.container')
            ->orderByDesc('recent_visit.visited_at')
            ->orderByDesc('recent_visit.id')
            ->limit(self::LIMIT)
            ->get();
    }
}
