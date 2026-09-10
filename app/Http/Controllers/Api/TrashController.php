<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Trash\RestoreContent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trash\RestoreRequest;
use App\Http\Resources\TrashEntryResource;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Papperskorgen för innehåll i en levande container — issue 20a. Listar
 * mjukraderade items, bilagor, kategorier och taggar och återställer dem.
 * Ingenting raderas här: gallringen är 20b och papperskorgen för raderade
 * containers är 20c, ingendera rörs av den här klassen.
 *
 * INGEN ny behörighetslogik bor här (ADR-0024, issue 20a § Beslut 1): varje
 * metod anropar bara `Gate::authorize()` mot BEFINTLIGA grindar — ingen ny
 * policymetod, ingen `TrashPolicy`. Fram till issue 74 var det `view`
 * (index) och `update` (restore) på App\Policies\ContainerPolicy; efter
 * issue 70 kräver `update` en container-bred grant och låste därmed ute
 * varje omfångsbegränsad mottagare, även den som har `delete` på sitt item.
 *
 * Issue 74 § Beslut 2: `restore()` grindas därför PER POST mot
 * `ItemPolicy::delete()` för `item` och för bilagans item, och mot
 * `ContainerPolicy::update()` för de två containervida typerna. Ingen ny
 * policymetod — [[Konton och åtkomst]] § Behörighetsregler regel 3:
 * "`delete` mjukraderar och återställer ur papperskorgen" (issue 70 §
 * Beslut 8). En `read`-deltagare ser papperskorgen men kan inte återställa;
 * en `write`-deltagare ser den, når sina items, men `write` varken raderar
 * eller återställer.
 *
 * Räddningen av reglerna (Beslut 8: föräldrar som blockerar) ligger i
 * App\Actions\Trash\RestoreContent, inte här — kontrollern gör grind,
 * validering och svar. Återställningssvaret byggs i SAMMA form som listan
 * (TrashEntryResource), så klienten kan uppdatera vyn utan en ny hämtning
 * (Beslut 6).
 */
class TrashController extends Controller
{
    /**
     * GET /api/containers/{container}/trash — 200.
     *
     * Papperskorgen är EN lista (Beslut 1): alla fyra typerna
     * (`item`, `attachment`, `category`, `tag`, Beslut 3) i samma svar,
     * sorterade på `deleted_at` fallande — det senast raderade är det som
     * oftast ska tillbaka. Ingen paginering (samma skäl som issue 15a §
     * Beslut 8) och inga löpnummer.
     *
     * Exakt FYRA frågor, en per typ, oavsett hur många rader som finns
     * (Beslut 11). `context` för bilagor och underkategorier hämtas med en
     * join i respektive typ-fråga, aldrig med en fråga per rad. `attachment`
     * är det enda uppslaget som går två steg: en bilaga hör till containern
     * genom `attachment.item.container_id` (Beslut 3), så den frågan joinar
     * mot `item` i stället för att hämta containerns items och fråga per
     * item.
     *
     * Utgånget innehåll finns inte (Beslut 5): rader vars `deleted_at` är
     * äldre än retentionen listas inte, även om gallringsjobbet (20b) ännu
     * inte hunnit köra — svaret får aldrig bero på cronjobbets tajmning.
     * Gränsen räknas som `now() - trash_retention_days` och stryks i samma
     * typ-fråga — den lägger sig OVANPÅ omfångsfiltret (issue 74 § Beslut 4),
     * så ett utgånget item är osynligt för alla, oavsett omfång.
     *
     * Issue 74 § Beslut 1: papperskorgen är en containervy och blir annars
     * ett fönster in i allt som någon gång raderats i pärmen. En
     * OMFÅNGSBEGRÄNSAD mottagare ser bara de items hon når och de bilagor
     * som hänger på dem — aldrig en kategori och aldrig en tagg. Kategorier
     * och taggar är containervid organisation, inte itemets innehåll (samma
     * skäl som gör att de står kvar på containergrinden i issue 71, och att
     * issue 73 § Beslut 5 bara visar *använda* taggar): en raderad tagg som
     * heter "Skilsmässa" är en upplysning om pärmen, inte om motorn. Ett
     * OMFATTANDE omfång (ägarkontots medlem, container-bred grant) är
     * oförändrat — alla fyra typerna, precis som före issuen.
     */
    public function index(Request $request, Container $container, ResolveItemScope $resolveItemScope): JsonResponse
    {
        Gate::authorize('view', $container);

        $scope = $resolveItemScope->handle($request->user(), $container);

        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $entries = [];

        // `inScope()` och inte en handskriven `whereIn`: formuleringen av
        // "vad mottagaren når" bor i App\Models\Item (issue 73 § Beslut 1).
        foreach (Item::query()->onlyTrashed()
            ->where('container_id', $container->id)
            ->where('deleted_at', '>=', $cutoff)
            ->inScope($scope)
            ->get(['ulid', 'name', 'deleted_at']) as $item) {
            $entries[] = $this->entry('item', $item->ulid, $item->name, null, $item->deleted_at, $retentionDays);
        }

        if ($scope->isUnrestricted()) {
            // Vänsterjoin mot category på parent_id: en underkategori ska bära
            // förälderns namn som context även när föräldern själv ligger i
            // papperskorgen — joinen ser mjukraderade rader, som den ska här.
            // Alias-kolumnen läses med getAttribute() (den är ingen kolumn på
            // modellen) och normaliseras till ?string.
            foreach (Category::query()->onlyTrashed()
                ->leftJoin('category as parent_category', 'parent_category.id', '=', 'category.parent_id')
                ->where('category.container_id', $container->id)
                ->where('category.deleted_at', '>=', $cutoff)
                ->get(['category.ulid', 'category.name', 'category.deleted_at', 'parent_category.name as parent_name']) as $category) {
                $parentName = $category->getAttribute('parent_name');

                $entries[] = $this->entry('category', $category->ulid, $category->name, is_string($parentName) ? $parentName : null, $category->deleted_at, $retentionDays);
            }

            foreach (Tag::query()->onlyTrashed()
                ->where('container_id', $container->id)
                ->where('deleted_at', '>=', $cutoff)
                ->get(['ulid', 'name', 'deleted_at']) as $tag) {
                $entries[] = $this->entry('tag', $tag->ulid, $tag->name, null, $tag->deleted_at, $retentionDays);
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

        foreach (Attachment::query()->onlyTrashed()
            ->join('item', 'item.id', '=', 'attachment.item_id')
            ->where('item.container_id', $container->id)
            ->where('attachment.deleted_at', '>=', $cutoff)
            ->when($itemIds !== null, fn (Builder $query) => $query->whereIn('item.id', $itemIds))
            ->get(['attachment.ulid', 'attachment.filename', 'attachment.deleted_at', 'item.name as item_name']) as $attachment) {
            $itemName = $attachment->getAttribute('item_name');

            $entries[] = $this->entry('attachment', $attachment->ulid, $attachment->filename, is_string($itemName) ? $itemName : null, $attachment->deleted_at, $retentionDays);
        }

        usort($entries, function (array $a, array $b): int {
            $byDeletedAt = $b['deleted_at']->timestamp <=> $a['deleted_at']->timestamp;

            return $byDeletedAt !== 0 ? $byDeletedAt : strcmp($a['ulid'], $b['ulid']);
        });

        return TrashEntryResource::collection($entries)->response();
    }

    /**
     * POST /api/containers/{container}/trash/restore — 200 med posten i
     * samma form som listan.
     *
     * RestoreRequest har redan bevisat att `type` är en av de fyra typerna
     * och att ULID:en finns i motsvarande tabell, inom DEN HÄR containern
     * och mjukraderad — en ULID ur en annan container eller en levande rad
     * är 422 `validation.failed` (Beslut 6). Valideringen filtrerar INTE på
     * retention, så ett utgånget innehåll passerar den och hamnar här, där
     * uppslaget nedan inte hittar det: 404 `resource.not_found` (Beslut 5).
     *
     * Issue 74 § Beslut 2: grinden är PER POST och ligger efter uppslaget,
     * på den rad valideringen redan pekat ut. Den var `update` på containern
     * fram till issue 70, och den grinden kräver numera en container-bred
     * grant — en omfångsbegränsad mottagare med `delete` på sitt item kom
     * alltså inte åt att ångra sin egen radering. `category` och `tag` är
     * fortfarande containervida och behåller containergrinden; `item` och
     * bilagans item grindas mot `ItemPolicy::delete()`, som betyder både
     * "mjukradera" och "återställ ur papperskorgen" (regel 3).
     *
     * Själva återställningen och Beslut 8 (föräldrar som blockerar) ägs av
     * App\Actions\Trash\RestoreContent. Efter den är `deleted_at` null, och
     * svaret bär posten som om den vore en listpost med `deleted_at`/
     * `expires_at` null — klienten kan ta bort den ur papperskorgsvyn utan
     * en ny hämtning.
     */
    public function restore(RestoreRequest $request, Container $container, RestoreContent $restoreContent): JsonResponse
    {
        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        $type = $request->validated('type');
        $model = $this->findTrashed($container, $type, $request->validated('ulid'), $cutoff);

        if ($model === null) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        $this->authorizeRestore($container, $model);

        $restoreContent->handle($type, $model);

        if ($model instanceof Attachment) {
            $row = $this->entry('attachment', $model->ulid, $model->filename, $model->item?->name, null, $retentionDays);
        } elseif ($model instanceof Category) {
            $row = $this->entry('category', $model->ulid, $model->name, $model->parent?->name, null, $retentionDays);
        } else {
            // Item och tagg — båda har `name` och aldrig ett context.
            $row = $this->entry($type, $model->ulid, $model->name, null, null, $retentionDays);
        }

        return (new TrashEntryResource($row))->response();
    }

    /**
     * Grinden för EN återställning, vald på radens typ (issue 74 § Beslut 2).
     *
     * `item` grindas mot sitt eget item, `attachment` mot itemet den hänger
     * på — det är samma item användaren måste ha `delete` på för att ha fått
     * radera bilagan. De två containervida typerna grindas mot containern,
     * precis som före issuen: en tagg eller kategori är pärmens organisation
     * och inte någons item, så en omfångsbegränsad mottagare får 403 här.
     *
     * Föräldern slås upp med `withTrashed()` och inte genom relationen
     * `$attachment->item`: den relationen bär itemets SoftDeletes-scope och
     * svarar null när itemet ligger i papperskorgen. En bilaga vars item är
     * raderat ska inte bli 403 för sin egen ägare — den ska nå
     * RestoreContent och få 422 `trash.parent_deleted` (Beslut 8), precis
     * som före issuen. Samma uppslag och samma skäl som i RestoreContent.
     */
    private function authorizeRestore(Container $container, Item|Attachment|Category|Tag $model): void
    {
        if ($model instanceof Item) {
            Gate::authorize('delete', $model);

            return;
        }

        if ($model instanceof Attachment) {
            $item = Item::withTrashed()->find($model->item_id);

            abort_if($item === null, 404);

            Gate::authorize('delete', $item);

            return;
        }

        Gate::authorize('update', $container);
    }

    /**
     * Slår upp den mjukraderade raden inom containern, med retentionen
     * tillämpad. Hittas inget — det enda realistiska fallet är ett utgånget
     * innehåll som valideringen släppte igenom — returneras null och
     * kontrollern svarar 404 `resource.not_found`.
     *
     * Uppslaget är container-scopat även om valideringen redan bevisat
     * containertillhörigheten: bälte och hängslen, samma regel som issue 13a
     * § Att se upp med. För `attachment` går scopet genom join mot `item`
     * och `select('attachment.*')` ser till att itemets kolumner inte
     * skriver över bilagans vid hydreringen.
     *
     * @param  'item'|'attachment'|'category'|'tag'  $type
     */
    private function findTrashed(Container $container, string $type, string $ulid, Carbon $cutoff): Item|Attachment|Category|Tag|null
    {
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

    /**
     * Bygger en papperskorgspost ur dess delar. `deleted_at` är en Carbon
     * eller null (efter återställning) och `expires_at` härleds ur den plus
     * retentionen — aldrig en lagrad kolumn (Beslut 2).
     *
     * @return array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}
     */
    private function entry(string $type, string $ulid, string $label, ?string $context, ?Carbon $deletedAt, int $retentionDays): array
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
