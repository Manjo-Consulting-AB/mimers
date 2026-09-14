<?php

namespace App\Actions\Access;

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Alla åtkomstrader för en container, färdighydrerade för
 * App\Http\Resources\ContainerAccessResource — se issue 55a § Beslut 8.
 *
 * Bryts ut ur App\Http\Controllers\Api\ContainerAccessController, som bar
 * både behörighetsprövningen, frågorna och hydreringen. Webben behöver
 * exakt samma rader och exakt samma hydrering som `/api` (issue 55a
 * § Beslut 8), och två kopior av hydrateGranteeUlids()/hydrateItemScope()
 * hade varit två formuleringar av samma svar — samma skäl som issue 9a
 * § Beslut 8 ger för "giltig access".
 *
 * **Kropparna är oförändrade.** Frågeantalet är kravet från issue 9b
 * § Beslut 11 som issue 72 § Beslut 5 upprepar: två frågor för mottagarnas
 * och beviljarnas ULID:er, två för omfånget och `reach` — oavsett antal
 * rader. Ändra inte villkoren här utan att ändra dem i testet.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, precis
 * som i dag: `viewAccesses()` är ägarkontots grind, och den som frågar
 * måste ha prövat den INNAN den här Actionen anropas — annars ligger
 * förvaltningsdatan i minnet hos en anropare som inte fick se den. Se issue
 * 54 § Beslut 3, samma uppdelning som App\Actions\Container\CreateContainer
 * gör för containertaket.
 *
 * Formen är instansklass med `handle()`, injicerad av containern, som
 * förlagan App\Actions\Access\ResolveItemScope. Att den injicerar just
 * `ResolveItemScope` i stället för att ärva den: `reach()` är samma
 * slutning som behörighetsupplösningen använder, och två vandringar över
 * `item_link` kan glida isär (issue 72 § Beslut 5).
 */
class ListContainerAccesses
{
    public function __construct(private readonly ResolveItemScope $scope) {}

    /**
     * Containerns åtkomstrader, sorterade `created_at` fallande — även
     * återkallade och utgångna, se issue 9b § Beslut 10. Anroparen avgör
     * presentationen ur `revoked_at`/`expires_at`; ingen `status` härleds
     * någonstans på vägen (issue 55a § Beslut 7).
     *
     * @return Collection<int, ContainerAccess>
     */
    public function handle(Container $container): Collection
    {
        $accesses = $container->accesses()
            ->orderByDesc('created_at')
            ->get();

        $this->hydrateGranteeUlids($accesses);
        $this->hydrateItemScope($container, $accesses);

        return $accesses;
    }

    /**
     * Löser upp mottagarens och beviljarens ULID i EN fråga vardera,
     * oavsett antal rader (issue 9b § Beslut 11) — `ContainerAccess` har
     * medvetet ingen `grantee()`-relation (se modellens docblock), så
     * uppslagningen görs här i stället för i resursen. Sätts på varje
     * modellinstans med `setAttribute()` innan `ContainerAccessResource`
     * läser dem.
     *
     * Publik därför att `/api`:s skrivvägar (POST, PATCH) svarar med EN rad
     * och måste hydrera den ur samma kod. Att skriva frågan en andra gång i
     * kontrollern vore den dubblering den här klassen finns för att ta bort.
     *
     * @param  Collection<int, ContainerAccess>  $accesses
     */
    public function hydrateGranteeUlids(Collection $accesses): void
    {
        $userIds = $accesses->pluck('granted_by_user_id')
            ->merge($accesses->where('grantee_type', 'user')->pluck('grantee_id'))
            ->unique()
            ->values()
            ->all();

        $accountIds = $accesses->where('grantee_type', 'account')
            ->pluck('grantee_id')
            ->unique()
            ->values()
            ->all();

        $userUlids = User::query()->whereIn('id', $userIds)->pluck('ulid', 'id');
        $accountUlids = Account::query()->whereIn('id', $accountIds)->pluck('ulid', 'id');

        foreach ($accesses as $access) {
            $access->setAttribute('granted_by_ulid', $userUlids->get($access->granted_by_user_id));
            $access->setAttribute(
                'grantee_ulid',
                $access->grantee_type === 'user'
                    ? $userUlids->get($access->grantee_id)
                    : $accountUlids->get($access->grantee_id),
            );
        }
    }

    /**
     * Sätter `item_ulid` och `reach` på varje rad — omfånget och hur långt
     * det når (issue 72 § Beslut 5).
     *
     * TVÅ frågor, oavsett antal rader: en `Item::withTrashed()` för alla
     * ULID:er och ett anrop till App\Actions\Access\ResolveItemScope::reach()
     * som laddar containerns `parent`-kanter i EN fråga. `withTrashed()` är
     * inte en detalj: en grant på ett sedan länge mjukraderat item ska
     * redovisas som sitt item, inte som `null` — `null` hade lästs som en
     * container-bred grant (issue 55a § Beslut 6).
     *
     * Finns inga itemrader alls kostas ingenting: båda nycklarna blir
     * `null`, vilket är exakt vad en container-bred rad ska svara.
     *
     * Publik av samma skäl som hydrateGranteeUlids() ovan: POST svarar med
     * en enda rad och behöver just den här halvan, utan att betala för
     * mottagar-ULID-uppslagningen den redan har i handen.
     *
     * @param  Collection<int, ContainerAccess>  $accesses
     */
    public function hydrateItemScope(Container $container, Collection $accesses): void
    {
        $itemIds = $accesses->pluck('item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            foreach ($accesses as $access) {
                $access->setAttribute('item_ulid', null);
                $access->setAttribute('reach', null);
            }

            return;
        }

        $itemUlids = Item::withTrashed()->whereIn('id', $itemIds)->pluck('ulid', 'id');
        $reach = $this->scope->reach($container->id, $itemIds);

        foreach ($accesses as $access) {
            $access->setAttribute(
                'item_ulid',
                $access->item_id === null ? null : $itemUlids->get($access->item_id),
            );
            $access->setAttribute(
                'reach',
                $access->item_id === null ? null : ($reach[$access->item_id] ?? null),
            );
        }
    }
}
