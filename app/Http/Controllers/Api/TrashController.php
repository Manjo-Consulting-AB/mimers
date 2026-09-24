<?php

namespace App\Http\Controllers\Api;

use App\Actions\Trash\FindTrashedInContainer;
use App\Actions\Trash\ListTrash;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Papperskorgen för innehåll i en levande container — issue 20a. Listar
 * mjukraderade items, bilagor, kategorier och taggar och återställer dem.
 * Ingenting raderas här: gallringen är 20b och papperskorgen för raderade
 * containers är 20c, ingendera rörs av den här klassen.
 *
 * **Kontrollern är grind, anrop och svar** (issue 62a § Beslut 2). Listan
 * och uppslaget av en enskild rad ligger i App\Actions\Trash\ListTrash
 * respektive App\Actions\Trash\FindTrashedInContainer, och webbens
 * papperskorg anropar exakt samma två — sedan issue 62a är `/api` inte
 * längre den enda ytan mot de frågorna. Svaret här är oförändrat: samma
 * nycklar, samma sortering (`deleted_at` fallande, ULID som andrasortering)
 * och samma fyra frågor som före utbrytningen.
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
 *
 * **`authorizeRestore()` upprepas i webbkontrollern med flit** (issue 62a
 * § Beslut 3): den flyttar inte in i en Action, för då hade
 * behörighetsbeslutet lämnat kontrollern. Två kontrollrar mot samma regel
 * betyder två testsviter mot samma regel — här och i
 * tests/Feature/Frontend/PapperskorgsvyTest.php.
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
     * Urvalet, sorteringen, retentionsgränsen och omfångsfiltret bor i
     * App\Actions\Trash\ListTrash, som webbens papperskorg anropar — se den
     * klassens docblock för de fyra frågorna, "utgånget innehåll finns inte"
     * och issue 74 § Beslut 1.
     */
    public function index(Request $request, Container $container, ListTrash $listTrash): JsonResponse
    {
        Gate::authorize('view', $container);

        $list = $listTrash->handle($request->user(), $container);

        return TrashEntryResource::collection($list['entries'])->response();
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
     * uppslaget inte hittar det: 404 `resource.not_found` (Beslut 5).
     * Uppslaget ligger i App\Actions\Trash\FindTrashedInContainer.
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
     * en ny hämtning. Posten byggs av ListTrash::entry(), samma byggare som
     * listan använder.
     */
    public function restore(RestoreRequest $request, Container $container, FindTrashedInContainer $findTrashed, RestoreContent $restoreContent): JsonResponse
    {
        $retentionDays = (int) config('files.trash_retention_days');

        $type = $request->validated('type');
        $model = $findTrashed->handle($container, $type, $request->validated('ulid'));

        if ($model === null) {
            throw ApiException::make('resource.not_found', [], 404);
        }

        $this->authorizeRestore($container, $model);

        $restoreContent->handle($type, $model, $request->user());

        if ($model instanceof Attachment) {
            $row = ListTrash::entry('attachment', $model->ulid, $model->filename, $model->item?->name, null, $retentionDays);
        } elseif ($model instanceof Category) {
            $row = ListTrash::entry('category', $model->ulid, $model->name, $model->parent?->name, null, $retentionDays);
        } else {
            // Item och tagg — båda har `name` och aldrig ett context.
            $row = ListTrash::entry($type, $model->ulid, $model->name, null, null, $retentionDays);
        }

        return (new TrashEntryResource($row))->response();
    }

    /**
     * Grinden för EN återställning, vald på radens typ (issue 74 § Beslut 2).
     *
     * `item` grindas mot sitt eget item, `attachment` mot itemet den hänger
     * på — det är samma item användaren måste ha `delete` på för att ha fått
     * radera bilagan. De två containervida typerna grindas mot containern,
     * precis som före issuen: en tagg eller kategori är containerns organisation
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
}
