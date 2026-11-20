<?php

namespace App\Http\Controllers\Api;

use App\Actions\Item\RestoreItemVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Http\Resources\ItemVersionResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API:ets versionsyta, se [[ADR-0088 Versionshistorik för items]] Riktning 5
 * och issue 413.
 *
 * **Läsning kräver `ItemPolicy::view`, återställning `ItemPolicy::update`.**
 * Ingen ny policymetod: versionerna hör till itemet, och åtkomsten är
 * itemets ([[ADR-0028 Åtkomst på itemnivå]]).
 *
 * **Versionen hämtas alltid genom itemet** (`$item->versions()`), så en
 * version som hör till ett annat item blir 404 — även när båda items ligger i
 * samma container. `{version}` binds därför som en sträng i signaturen.
 *
 * **ULID:er utåt, aldrig löpnummer** (AGENTS.md § Databaskonventioner):
 * `ItemVersionResource` bär versionens egen ULID och pekarna som ULID:er.
 *
 * Listan är sidindelad som de andra listorna under itemet. Taket för antalet
 * versioner är tjugo (ADR-0088 Riktning 1), så sidan rymmer i praktiken
 * alltid hela listan — formen är den samma ändå.
 */
class ItemVersionController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * GET /api/containers/{container}/items/{item}/versions — 200.
     */
    public function index(Request $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $versions = $item->versions()->paginate(self::PER_PAGE);

        ItemVersionResource::prepare($versions->getCollection());

        return ItemVersionResource::collection($versions)->response();
    }

    /**
     * GET /api/containers/{container}/items/{item}/versions/{version} — 200.
     */
    public function show(Request $request, Container $container, Item $item, string $version): ItemVersionResource
    {
        Gate::authorize('view', $item);

        $itemVersion = $this->version($item, $version);

        ItemVersionResource::prepare([$itemVersion]);

        return new ItemVersionResource($itemVersion);
    }

    /**
     * POST /api/containers/{container}/items/{item}/versions/{version}/restore
     * — 200 med `ItemResource` och `meta.skipped`.
     *
     * `meta.skipped` räknar upp de fält som inte längre fanns och därför
     * lämnades orörda: `category`, `tags` och `cover` (ADR-0088 Riktning 5).
     */
    public function restore(Request $request, Container $container, Item $item, string $version, RestoreItemVersion $restoreItemVersion): JsonResponse
    {
        Gate::authorize('update', $item);

        $itemVersion = $this->version($item, $version);
        $skipped = $restoreItemVersion->handle($item, $itemVersion, $request->user());

        // Samma uppladdning som Api\ItemController::update, så resursen aldrig
        // kör en oplanerad lazy-load och svaret speglar det återställda läget.
        $item->loadMissing(['category', 'createdByAccount', 'tags', 'container.fields', 'ownFields', 'fieldValues']);

        return (new ItemResource($item))
            ->additional(['meta' => ['skipped' => $skipped]])
            ->response();
    }

    /**
     * Versionen genom itemet — en ULID från ett annat item ger 404.
     */
    private function version(Item $item, string $ulid): ItemVersion
    {
        return $item->versions()->where('ulid', $ulid)->firstOrFail();
    }
}
