<?php

namespace App\Actions\Trash;

use App\Actions\Access\ResolveItemScope;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Support\Access\ItemScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Papperskorgen för innehållet i en levande container: de mjukraderade
 * items, bilagor, kategorier och taggar som fortfarande finns kvar, senast
 * raderat först — se issue 62a § Beslut 2 och 8, issue 20a § Beslut 1, 2,
 * 4, 5 och 11 och issue 74 § Beslut 1–4.
 *
 * Kroppen är `App\Http\Controllers\Api\TrashController::index()`s,
 * övertagen oförändrad: de fyra typ-frågorna, retentionsgränsen,
 * omfångsfiltret, sorteringen och `entry()`-byggandet. `/api` anropar
 * Actionen för att sidan inte ska bli en andra formulering av samma lista,
 * och webben anropar den av samma skäl.
 *
 * **Ingen behörighetslogik bor här** ([[ADR-0024 Tunna controllers och
 * actions]], issue 62a § Beslut 2). Omfånget är ett FILTER och hör hemma i
 * frågan; grinden är ett BESLUT och stannar i kontrollern. Actionen svarar
 * därför på "vilka rader finns", aldrig på "vem får se dem" — den som
 * anropar har redan prövat `ContainerPolicy::view()`.
 *
 * **Exakt FYRA frågor**, en per typ, oavsett hur många rader som finns
 * (issue 20a § Beslut 11). `context` för bilagor och underkategorier hämtas
 * med en join i respektive typ-fråga, aldrig med en fråga per rad.
 * `attachment` är det enda uppslaget som går två steg: en bilaga hör till
 * containern genom `attachment.item.container_id`, så den frågan joinar mot
 * `item` i stället för att hämta containerns items och fråga per item.
 *
 * **Utgånget innehåll finns inte** (issue 20a § Beslut 5): rader vars
 * `deleted_at` är äldre än retentionen listas inte, även om gallringsjobbet
 * (20b) ännu inte hunnit köra — svaret får aldrig bero på cronjobbets
 * tajmning. Gränsen räknas som `now() - trash_retention_days` och stryks i
 * samma typ-fråga — den lägger sig OVANPÅ omfångsfiltret (issue 74
 * § Beslut 4), så ett utgånget item är osynligt för alla, oavsett omfång.
 *
 * **Omfångsbegränsningen** (issue 74 § Beslut 1): en OMFÅNGSBEGRÄNSAD
 * mottagare ser bara de items hon når och de bilagor som hänger på dem —
 * aldrig en kategori och aldrig en tagg. Kategorier och taggar är
 * containervid organisation, inte itemets innehåll, och de två frågorna
 * hoppas därför över helt för henne. Ett OMFATTANDE omfång är oförändrat:
 * alla fyra typerna.
 *
 * **`subjects` är den andra halvan av svaret.** Varje post bär sin laddade
 * rad i `subjects`, nycklad på ULID — webbkontrollern frågar `ItemPolicy`
 * per rad för återställningsknappen (issue 62a § Beslut 6), och en andra
 * hämtning av samma rader vore en fråga för mycket. `/api` läser bara
 * `entries` och rör den inte. `id` och `item_id` är därför med i
 * kolumnlistorna: de behövs för att bygga grindobjektet, och de syns
 * aldrig i svaret — `TrashEntryResource` läser sex namngivna nycklar.
 *
 * **`subcategory_counts` är det tredje svaret, och bara webben läser det**
 * (issue 150). En kategori som raderades med sitt underträd listas som EN rad
 * — den översta — och talet i `counts` är hur många underkategorier som följer
 * med i den raden. Det kan inte bo i `TrashEntryResource`, som är delad med
 * `/api` (och som läser sex namngivna nycklar), av samma skäl som
 * `can_restore` bor utanför den: en delad resurs får inte ett fält bara den
 * ena ytan behöver, och `app/Http/Resources/**` ligger utanför issue 150:s
 * omfång. Nycklarna är de översta radernas ULID:n; en rad utan underträd
 * saknas i uppslaget och blir noll.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är
 * det. Skulle den ändå vara null blir omfånget `restricted([])` — "når
 * ingenting". Ett saknat omfång får aldrig bli ett obegränsat, samma linje
 * som App\Actions\Item\ListItems och App\Actions\Category\ListCategories.
 */
class ListTrash
{
    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * @return array{entries: list<array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}>, subjects: array<string, Item|Attachment|Category|Tag>, subcategory_counts: array<string, int>}
     */
    public function handle(?User $user, Container $container): array
    {
        $scope = $user === null
            ? ItemScope::restricted([])
            : $this->resolveItemScope->handle($user, $container);

        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $entries = [];
        $subjects = [];
        $subcategoryCounts = [];

        // `inScope()` och inte en handskriven `whereIn`: formuleringen av
        // "vad mottagaren når" bor i App\Models\Item (issue 73 § Beslut 1).
        foreach (Item::query()->onlyTrashed()
            ->where('container_id', $container->id)
            ->where('deleted_at', '>=', $cutoff)
            ->inScope($scope)
            ->get(['id', 'ulid', 'name', 'deleted_at']) as $item) {
            $entries[] = self::entry('item', $item->ulid, $item->name, null, $item->deleted_at, $retentionDays);
            $subjects[$item->ulid] = $item;
        }

        if ($scope->isUnrestricted()) {
            // Kategorierna hämtas i EN fråga med förälderns namn OCH dess
            // deleted_at som alias (issue 150): namnet är sammanhanget, och
            // tidsstämpeln avgör om raden raderades SAMTIDIGT som sin
            // förälder. Joinen ser mjukraderade rader, som den ska här — en
            // underkategori ska bära förälderns namn som context även när
            // föräldern själv ligger i papperskorgen. Alias-kolumnerna läses
            // med getAttribute() (de är inga kolumner på modellen).
            $categoryRows = Category::query()->onlyTrashed()
                ->leftJoin('category as parent_category', 'parent_category.id', '=', 'category.parent_id')
                ->where('category.container_id', $container->id)
                ->where('category.deleted_at', '>=', $cutoff)
                ->get([
                    'category.id',
                    'category.ulid',
                    'category.name',
                    'category.parent_id',
                    'category.deleted_at',
                    'parent_category.name as parent_name',
                ]);

            $categoryList = $this->categories($categoryRows, $retentionDays);

            $entries = [...$entries, ...$categoryList['entries']];
            $subjects += $categoryList['subjects'];
            $subcategoryCounts = $categoryList['counts'];

            foreach (Tag::query()->onlyTrashed()
                ->where('container_id', $container->id)
                ->where('deleted_at', '>=', $cutoff)
                ->get(['ulid', 'name', 'deleted_at']) as $tag) {
                $entries[] = self::entry('tag', $tag->ulid, $tag->name, null, $tag->deleted_at, $retentionDays);
                $subjects[$tag->ulid] = $tag;
            }
        }

        // Bilagans `context` är itemets namn — det står kvar även för en
        // omfångsbegränsad mottagare: hon når itemet, annars ser hon inte
        // bilagan (issue 74 § Beslut 1).
        //
        // Filtret skrivs som `whereIn('item.id', …)` och inte med
        // `Item::inScope()`: scopet hör till Item-modellen och en
        // Attachment-fråga kan inte bära det. Formuleringen är ändå samma —
        // `itemIds()` svarar `null` för "hela containern", precis som
        // scopeInScope läser den (issue 73 § Beslut 1), och kolumnen är
        // kvalificerad för att joinen mot `item` gör `id` tvetydig.
        $itemIds = $scope->itemIds();

        // Den inre joinen mot item är sedan issue 158 också det som håller
        // containerns bild UTANFÖR papperskorgen: en containerbilaga har
        // `item_id = NULL`, matchar aldrig en inre join, och kan därför
        // varken listas eller återställas härifrån
        // ([[ADR-0047 Containerns bild]] § Beslut: en borttagen bild rensas
        // direkt och går inte att ångra).
        foreach (Attachment::query()->onlyTrashed()
            ->join('item', 'item.id', '=', 'attachment.item_id')
            ->where('item.container_id', $container->id)
            ->where('attachment.deleted_at', '>=', $cutoff)
            ->when($itemIds !== null, fn (Builder $query) => $query->whereIn('item.id', $itemIds))
            ->get(['attachment.ulid', 'attachment.filename', 'attachment.deleted_at', 'attachment.item_id', 'item.name as item_name']) as $attachment) {
            $itemName = $attachment->getAttribute('item_name');

            $entries[] = self::entry('attachment', $attachment->ulid, $attachment->filename, is_string($itemName) ? $itemName : null, $attachment->deleted_at, $retentionDays);
            $subjects[$attachment->ulid] = $attachment;
        }

        usort($entries, function (array $a, array $b): int {
            $byDeletedAt = $b['deleted_at']->timestamp <=> $a['deleted_at']->timestamp;

            return $byDeletedAt !== 0 ? $byDeletedAt : strcmp($a['ulid'], $b['ulid']);
        });

        return ['entries' => $entries, 'subjects' => $subjects, 'subcategory_counts' => $subcategoryCounts];
    }

    /**
     * Kategoriraderna i papperskorgen, med underträdet hopfällt — issue 150
     * och [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26.
     *
     * **Bara den ÖVERSTA kategorin listas.**
     * App\Actions\Category\DeleteCategory raderar ett helt underträd i en
     * transaktion med EN `deleted_at`, och den tidsstämpeln är vad som binder
     * trädet samman: en rad vars förälder ligger i papperskorgen med SAMMA
     * `deleted_at` raderades samtidigt som sin förälder och listas inte för
     * sig. En underkategori som raderades vid ett annat tillfälle — föräldern
     * lever, eller ligger i papperskorgen sedan tidigare — har en annan
     * tidsstämpel och listas, precis som förut, med förälderns namn som
     * `context`.
     *
     * **Antalet underkategorier är hela det hopfällda underträdet**, inte bara
     * de direkta barnen: det är vad raden lovar att återställningen tar med
     * sig. Talet räknas i minnet ur den redan hämtade samlingen, i EN fråga
     * för hela listan.
     *
     * Memoiseringen är ingen optimering utan en nödvändighet: trädet är högst
     * `Category::MAX_DEPTH` nivåer, och en vandring uppåt per rad hade varit
     * kvadratisk i en container med många kategorier.
     *
     * @param  Collection<int, Category>  $rows
     * @return array{entries: list<array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}>, subjects: array<string, Category>, counts: array<string, int>}
     */
    private function categories(Collection $rows, int $retentionDays): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->getAttribute('id')] = $row;
        }

        // Föräldern i SAMMA papperskorgskast: bara när förälderns rad ligger i
        // den hämtade mängden och delar tidsstämpeln. En förälder utanför
        // retentionen är inte med i mängden och räknas därför inte som
        // samtidig — den raderades förr, och barnet listas för sig.
        $sameCastParent = [];

        foreach ($rows as $row) {
            $id = (int) $row->getAttribute('id');
            $parentId = $row->getAttribute('parent_id');
            $parent = $parentId !== null ? ($byId[(int) $parentId] ?? null) : null;

            $sameCastParent[$id] = $parent !== null && $parent->deleted_at->equalTo($row->deleted_at)
                ? (int) $parentId
                : null;
        }

        $entries = [];
        $subjects = [];
        $counts = [];

        foreach ($rows as $row) {
            $id = (int) $row->getAttribute('id');

            if ($sameCastParent[$id] !== null) {
                // Hopfälld: listas inte för sig, men räknas på sin översta rad.
                $topId = $this->topId($id, $sameCastParent);
                $topUlid = $byId[$topId]->ulid;

                $counts[$topUlid] = ($counts[$topUlid] ?? 0) + 1;

                continue;
            }

            $parentName = $row->getAttribute('parent_name');

            $entries[] = self::entry(
                'category',
                $row->ulid,
                $row->name,
                is_string($parentName) ? $parentName : null,
                $row->deleted_at,
                $retentionDays,
            );
            $subjects[$row->ulid] = $row;
        }

        return ['entries' => $entries, 'subjects' => $subjects, 'counts' => $counts];
    }

    /**
     * Den översta raden i en samtidigt kastad kedja — se `categories()`. Kedjan
     * är ändlig: `sameCastParent` pekar bara på rader i samma hämtade mängd,
     * och `MoveCategory` avvisar cykler.
     *
     * @param  array<int, int|null>  $sameCastParent
     */
    private function topId(int $id, array $sameCastParent): int
    {
        while ($sameCastParent[$id] !== null) {
            $id = $sameCastParent[$id];
        }

        return $id;
    }

    /**
     * Bygger en papperskorgspost ur dess delar. `deleted_at` är en Carbon
     * eller null (efter återställning) och `expires_at` härleds ur den plus
     * retentionen — aldrig en lagrad kolumn (issue 20a § Beslut 2).
     *
     * Publik och statisk därför att `Api\TrashController::restore()` bygger
     * sin ENSKILDA post ur exakt samma sex nycklar (§ Beslut 6): två byggare
     * av samma form glider isär, och `TrashEntryResource` är kontraktet de
     * båda måste hålla.
     *
     * @return array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}
     */
    public static function entry(string $type, string $ulid, string $label, ?string $context, ?Carbon $deletedAt, int $retentionDays): array
    {
        return [
            'type' => $type,
            'ulid' => $ulid,
            'label' => $label,
            'context' => $context,
            'deleted_at' => $deletedAt,
            'expires_at' => $deletedAt?->copy()->addDays($retentionDays),
        ];
    }
}
