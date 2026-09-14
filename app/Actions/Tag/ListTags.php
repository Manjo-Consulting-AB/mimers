<?php

namespace App\Actions\Tag;

use App\Actions\Access\ResolveItemScope;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Support\Access\ItemScope;
use Illuminate\Support\Collection;

/**
 * Containerns taggar, omfångsfiltrerade, med träffräknaren — se issue 56a
 * § Beslut 6 och 7, issue 12 § Beslut 9 och issue 73 § Beslut 5.
 *
 * `handle()` är `App\Http\Controllers\Api\TagController::index()`s kropp,
 * övertagen oförändrad: sorterad `name` stigande, ingen paginering, ett
 * konstant antal frågor oavsett antal taggar. En OMFÅNGSBEGRÄNSAD mottagare
 * ser bara taggar som sitter på minst ett item hon når — en tagg med noll
 * synliga träffar visas inte alls, för namnet ("Försäkringar", "Skilsmässa")
 * är ofta mer avslöjande än itemet. Ett OMFATTANDE omfång är oförändrat: alla
 * containerns taggar, även den ingen använt.
 *
 * **Räknaren bor här och inte i `TagResource`** (Beslut 6). `/api` har inte
 * bett om den, och ett fält som bara webben behöver i en delad resurs är
 * precis den drift [[ADR-0021 Frontendteknik]] § Konsekvenser varnar för.
 * `counts()` räknar därför i webbkontrollern i stället, och talet är per
 * OMFÅNG: en mottagare som når fyra items ska se att taggen sitter på två av
 * dem, inte att den sitter på nittio.
 *
 * **EN fråga för båda.** `itemsPerTag()` är samma svep som `visibleTagIds()`
 * använde — en join mot `item_tag` över items inom omfånget, grupperad på
 * `tag_id` — och svarar både vilka taggar som syns och hur många items var och
 * en sitter på. Resultatet memoiseras per container på instansen, så
 * `handle()` och `counts()` tillsammans kostar en fråga och inte två.
 * Instansen är inte `singleton` (Actions löses upp per injektion), så memon
 * lever exakt så länge som en request gör det.
 *
 * **`$user` är nollbar** av samma skäl som i App\Actions\Category\ListCategories
 * — `Request::user()` är det. Null blir `restricted([])`, alltså "når
 * ingenting": ett saknat omfång får aldrig bli ett obegränsat.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som issue 54 § Beslut 3, 55a § Beslut 8 och 55b § Beslut 7.
 *
 * SoftDeletes' globala scope gäller i båda leden: en mjukraderad tagg
 * försvinner ur `$container->tags()`, och ett mjukraderat item räknas inte
 * som träff eftersom `Item`-frågan filtrerar `deleted_at`.
 */
class ListTags
{
    /** @var array<int, array<int, int>> container_id → tag_id → antal items */
    private array $itemsPerTag = [];

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @return Collection<int, Tag>
     */
    public function handle(?User $user, Container $container): Collection
    {
        $scope = $this->scope($user, $container);

        return $container->tags()
            ->when(
                ! $scope->isUnrestricted(),
                fn ($query) => $query->whereIn('tag.id', $this->visibleTagIds($container, $scope)),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Träffräknaren för $tags, nycklad på tagg-ULID — det vyn slår upp på.
     * Talet är antalet items i $user:s OMFÅNG som bär taggen, aldrig antalet
     * items i containern (Beslut 6).
     *
     * @param  Collection<int, Tag>  $tags
     * @return array<string, int>
     */
    public function counts(?User $user, Container $container, Collection $tags): array
    {
        $perTagId = $this->itemsPerTag($container, $this->scope($user, $container));

        $counts = [];

        foreach ($tags as $tag) {
            $counts[$tag->ulid] = $perTagId[$tag->id] ?? 0;
        }

        return $counts;
    }

    /**
     * Omfånget för $user i $container, eller "når ingenting" när ingen
     * användare finns — se klassens docblock.
     */
    private function scope(?User $user, Container $container): ItemScope
    {
        return $user === null
            ? ItemScope::restricted([])
            : $this->resolveItemScope->handle($user, $container);
    }

    /**
     * Löpnumren för de taggar som sitter på minst ett item $scope når —
     * issue 73 § Beslut 5. Nycklarna ur samma svep som räknaren.
     *
     * @return list<int>
     */
    private function visibleTagIds(Container $container, ItemScope $scope): array
    {
        return array_map('intval', array_keys($this->itemsPerTag($container, $scope)));
    }

    /**
     * Antalet items $scope når per tagg, i EN fråga — en join mot `item_tag`
     * över items inom omfånget, grupperad på `tag_id`. `inScope()` och inte en
     * handskriven `whereIn`: formuleringen av "vad mottagaren når" bor i
     * App\Models\Item (issue 73 § Beslut 1).
     *
     * @return array<int, int> tag_id → antal items
     */
    private function itemsPerTag(Container $container, ItemScope $scope): array
    {
        if (isset($this->itemsPerTag[$container->id])) {
            return $this->itemsPerTag[$container->id];
        }

        $rows = Item::query()
            ->inScope($scope)
            ->join('item_tag', 'item_tag.item_id', '=', 'item.id')
            ->groupBy('item_tag.tag_id')
            ->selectRaw('item_tag.tag_id as tag_id, count(distinct item.id) as item_count')
            ->get();

        $counts = [];

        // `getAttribute()` och inte egenskapsåtkomst: raden är en projektion
        // över `item` och `item_tag`, och `tag_id`/`item_count` är alias ur
        // `selectRaw` som varken finns som kolumn eller cast på App\Models\Item.
        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('tag_id')] = (int) $row->getAttribute('item_count');
        }

        return $this->itemsPerTag[$container->id] = $counts;
    }
}
